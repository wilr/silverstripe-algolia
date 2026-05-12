<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Ramsey\Uuid\Uuid;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaDeleteItemJob;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaIndexItemJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

/**
 * @extends Extension<DataObject>
 */
class AlgoliaObjectExtension extends Extension
{
    use Configurable;

    /**
     * @config
     */
    private static bool $enable_indexer = true;

    /**
     * @config
     */
    private static bool $use_queued_indexing = false;

    private static array $db = [
        'AlgoliaIndexed' => 'Datetime',
        'AlgoliaError' => 'Varchar(200)',
        'AlgoliaUUID' => 'Varchar(200)'
    ];

    /**
     * @return bool
     */
    public function indexEnabled(): bool
    {
        return $this->config()->get('enable_indexer') ? true : false;
    }



    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('AlgoliaIndexed');
        $fields->removeByName('AlgoliaUUID');
        $fields->removeByName('AlgoliaError');
    }


    public function updateSettingsFields(FieldList $fields): void
    {
        if ($this->indexEnabled()) {
            $fields->addFieldsToTab(
                'Root.Search',
                [
                    ReadonlyField::create('AlgoliaIndexed', _t(__CLASS__ . '.LastIndexed', 'Last indexed in Algolia'))
                        ->setDescription($this->owner->AlgoliaError),
                    ReadonlyField::create('AlgoliaUUID', _t(__CLASS__ . '.UUID', 'Algolia UUID'))
                ]
            );
        }
    }

    /**
     * Returns whether this object should be indexed into Algolia.
     */
    public function canIndexInAlgolia(): bool
    {
        if ($this->owner->hasField('ShowInSearch')) {
            return $this->owner->ShowInSearch;
        }

        return true;
    }

    /**
     * When publishing the page, push this data to Algolia Indexer. The data
     * which is sent to Algolia is the rendered template from the front end.
     */
    public function onAfterPublish(): void
    {
        if (self::shouldBlockIndexingForAlgolia($this->owner)) {
            $this->removeFromAlgolia();
        } else {
            // check to see if the classname changed, if it has then it might
            // need to be removed from other indexes before being re-added
            if ($this->owner->isChanged('ClassName')) {
                $this->removeFromAlgolia();
            }

            $this->indexInAlgolia();
        }
    }

    public function markAsRemovedFromAlgoliaIndex(): DataObject
    {
        $this->touchAlgoliaIndexedDate(true);

        return $this->owner;
    }

    /**
     * Update the AlgoliaIndexed date for this object.
     */
    public function touchAlgoliaIndexedDate(bool $isDeleted = false): DataObject
    {
        $conn = DB::get_conn();
        $newValue = $isDeleted || $conn === null ? 'null' : $conn->now();

        $this->updateAlgoliaFields([
            'AlgoliaIndexed' => $newValue,
            'AlgoliaUUID' => "'" . $this->owner->AlgoliaUUID . "'",
        ]);

        return $this->owner;
    }

    /**
     * Update search metadata without triggering draft state etc
     *
     * @param array<string, string> $fields
     */
    private function updateAlgoliaFields(array $fields): void
    {
        $schema = DataObject::getSchema();
        $table = $schema->tableForField($this->owner->ClassName, 'AlgoliaIndexed');

        if ($table && count($fields)) {
            $sets = [];

            foreach ($fields as $field => $value) {
                $sets[] = "$field = $value";
            }

            $set = implode(', ', $sets);
            $query = sprintf('UPDATE %s SET %s WHERE ID = %s', $table, $set, $this->owner->ID);

            DB::query($query);

            if ($this->owner->hasExtension(Versioned::class) && $this->owner->hasStages()) {
                DB::query(
                    sprintf(
                        'UPDATE %s_Live SET %s WHERE ID = %s',
                        $table,
                        $set,
                        $this->owner->ID
                    )
                );
            }
        }
    }

    /**
     * Index this record into Algolia or queue if configured to do so
     *
     * @return bool
     */
    public function indexInAlgolia(): bool
    {
        if ($this->indexEnabled() && self::shouldBlockIndexingForAlgolia($this->owner)) {
            return false;
        }

        if ($this->config()->get('use_queued_indexing')) {
            $indexJob = new AlgoliaIndexItemJob($this->owner->ClassName, $this->owner->ID);
            QueuedJobService::singleton()->queueJob($indexJob);

            return true;
        } else {
            return $this->doImmediateIndexInAlgolia();
        }
    }

    /**
     * Index this record into Algolia
     *
     * @return bool
     */
    public function doImmediateIndexInAlgolia(): bool
    {
        if ($this->indexEnabled() && self::shouldBlockIndexingForAlgolia($this->owner)) {
            return false;
        }


        $schema = DataObject::getSchema();
        $table = $schema->tableForField($this->owner->ClassName, 'AlgoliaError');
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        try {
            if ($indexer->indexItem($this->owner)) {
                $this->touchAlgoliaIndexedDate();

                DB::query(
                    sprintf(
                        'UPDATE %s SET AlgoliaError = \'\' WHERE ID = %s',
                        $table,
                        $this->owner->ID
                    )
                );

                return true;
            } else {
                return false;
            }
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);

            DB::query(
                sprintf(
                    'UPDATE %s SET AlgoliaError = \'%s\' WHERE ID = %s',
                    $table,
                    Convert::raw2sql($e->getMessage()),
                    $this->owner->ID
                )
            );

            $this->owner->AlgoliaError = $e->getMessage();
        }

        return false;
    }

    /**
     * When unpublishing this item, remove from Algolia
     */
    public function onAfterUnpublish(): void
    {
        if ($this->indexEnabled()) {
            $this->removeFromAlgolia();
        }
    }

    /**
     * Remove this item from Algolia
     *
     * @return boolean
     */
    public function removeFromAlgolia(): bool
    {
        if (!$this->owner->AlgoliaUUID) {
            // Not in the index, so skipping
            return false;
        }

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        if ($this->config()->get('use_queued_indexing')) {
            $indexDeleteJob = new AlgoliaDeleteItemJob($this->owner->getClassName(), $this->owner->AlgoliaUUID);
            QueuedJobService::singleton()->queueJob($indexDeleteJob);

            $this->markAsRemovedFromAlgoliaIndex();
        } else {
            try {
                $indexer->deleteItem($this->owner->getClassName(), $this->owner->AlgoliaUUID);

                $this->markAsRemovedFromAlgoliaIndex();
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->error($e);

                return false;
            }
        }
        return true;
    }

    public function onBeforeWrite(): void
    {
        if (!$this->owner->AlgoliaUUID) {
            $this->assignAlgoliaUUID(false);
        }
    }

    public function assignAlgoliaUUID(bool $writeImmediately = true): void
    {
        $uuid = Uuid::uuid4();
        $value = $uuid->toString();

        $this->owner->AlgoliaUUID = $value;

        if ($writeImmediately) {
            $this->updateAlgoliaFields(['AlgoliaUUID' => "'$value'"]);
        }
    }

    /**
     * Before deleting this record ensure that it is removed from Algolia.
     */
    public function onBeforeDelete(): void
    {
        if ($this->indexEnabled()) {
            $this->removeFromAlgolia();
        }
    }

    /**
     * Ensure each record has unique UUID
     */
    public function onBeforeDuplicate(): void
    {
        $this->assignAlgoliaUUID(false);
        $this->owner->AlgoliaIndexed = null;
        $this->owner->AlgoliaError = null;
    }

    /**
     * @return array<string, \Algolia\AlgoliaSearch\SearchIndex>
     */
    public function getAlgoliaIndexes(): array
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        return $indexer->getService()->initIndexes($this->owner);
    }

    /**
     * True when aggregated extension hooks for {@see canIndexInAlgolia()} veto indexing for this owner.
     */
    public static function shouldBlockIndexingForAlgolia(DataObject $owner): bool
    {
        $flags = $owner->invokeWithExtensions('canIndexInAlgolia');

        if ($flags === []) {
            return false;
        }

        return min($flags) === false;
    }

    /**
     * Runs {@see assignAlgoliaUUID()} with a correctly scoped extension instance (for code paths where the
     * owner is typed as generic {@see DataObject}).
     */
    public static function runAssignAlgoliaUuid(DataObject $owner, bool $writeImmediately = true): void
    {
        $extension = Injector::inst()->create(static::class);
        $extension->withOwner($owner, static function () use ($extension, $writeImmediately): void {
            $extension->assignAlgoliaUUID($writeImmediately);
        });
    }

    /**
     * Runs {@see doImmediateIndexInAlgolia()} with a correctly scoped extension instance.
     */
    public static function runDoImmediateIndexInAlgolia(DataObject $owner): bool
    {
        $extension = Injector::inst()->create(static::class);

        return $extension->withOwner($owner, static fn (): bool => $extension->doImmediateIndexInAlgolia());
    }

    public static function runTouchAlgoliaIndexedDate(DataObject $owner): void
    {
        $extension = Injector::inst()->create(static::class);
        $extension->withOwner($owner, static function () use ($extension): void {
            $extension->touchAlgoliaIndexedDate();
        });
    }
}
