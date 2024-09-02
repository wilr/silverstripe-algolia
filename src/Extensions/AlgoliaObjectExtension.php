<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Ramsey\Uuid\Uuid;
use SilverStripe\Core\Convert;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaDeleteItemJob;
use Wilr\Silverstripe\Algolia\Jobs\AlgoliaIndexItemJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaConfigure;

class AlgoliaObjectExtension extends DataExtension
{
    use Configurable;

    /**
     * @var boolean
     */
    private $ranSync = false;

    /**
     * @config
     *
     * @var boolean
     */
    private static $enable_indexer = true;

    /**
     * @config
     *
     * @var boolean
     */
    private static $use_queued_indexing = false;

    private static $db = [
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



    /**
     * @param FieldList
     */
    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName('AlgoliaIndexed');
        $fields->removeByName('AlgoliaUUID');
        $fields->removeByName('AlgoliaError');
    }


    /**
     * @param FieldList
     */
    public function updateSettingsFields(FieldList $fields)
    {
        if ($this->owner->indexEnabled()) {
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
    public function onAfterPublish()
    {
        if (min($this->owner->invokeWithExtensions('canIndexInAlgolia')) == false) {
            $this->owner->removeFromAlgolia();
        } else {
            // check to see if the classname changed, if it has then it might
            // need to be removed from other indexes before being re-added
            if ($this->owner->isChanged('ClassName')) {
                $this->owner->removeFromAlgolia();
            }

            $this->owner->indexInAlgolia();
        }
    }

    /**
     *
     */
    public function markAsRemovedFromAlgoliaIndex()
    {
        $this->touchAlgoliaIndexedDate(true);

        return $this->owner;
    }

    /**
     * Update the AlgoliaIndexed date for this object.
     */
    public function touchAlgoliaIndexedDate($isDeleted = false)
    {
        $newValue = $isDeleted ? 'null' : DB::get_conn()->now();

        $this->updateAlgoliaFields([
            'AlgoliaIndexed' => $newValue,
            'AlgoliaUUID' => "'" . $this->owner->AlgoliaUUID . "'",
        ]);

        return $this->owner;
    }

    /**
     * Update search metadata without triggering draft state etc
     */
    private function updateAlgoliaFields($fields)
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
        if ($this->owner->indexEnabled() && min($this->owner->invokeWithExtensions('canIndexInAlgolia')) == false) {
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
        if ($this->owner->indexEnabled() && min($this->owner->invokeWithExtensions('canIndexInAlgolia')) == false) {
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
    public function onAfterUnpublish()
    {
        if ($this->owner->indexEnabled()) {
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

    public function onBeforeWrite()
    {
        if (!$this->owner->AlgoliaUUID) {
            $this->owner->assignAlgoliaUUID(false);
        }
    }

    public function assignAlgoliaUUID($writeImmediately = true)
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
    public function onBeforeDelete()
    {
        if ($this->owner->indexEnabled()) {
            $this->removeFromAlgolia();
        }
    }

    /**
     * Ensure each record has unique UUID
     */
    public function onBeforeDuplicate()
    {
        $this->owner->assignAlgoliaUUID(false);
        $this->owner->AlgoliaIndexed = null;
        $this->owner->AlgoliaError = null;
    }

    /**
     * @return array
     */
    public function getAlgoliaIndexes()
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);

        return $indexer->getService()->initIndexes($this->owner);
    }
}
