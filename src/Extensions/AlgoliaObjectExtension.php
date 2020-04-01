<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Symbiote\QueuedJobs\Jobs\AlgoliaDeleteItemJob;
use Symbiote\QueuedJobs\Jobs\AlgoliaIndexItemJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class AlgoliaObjectExtension extends DataExtension
{
    use Configurable;

    /**
     *
     */
    private static $enable_indexer = true;

    /**
     *
     */
    private static $use_queued_indexing = false;

    private static $db = [
        'AlgoliaIndexed' => 'Datetime'
    ];

    /**
     * @return bool
     */
    public function indexEnabled(): bool
    {
        return $this->config('enable_indexer') ? true : false;
    }

    public function updateCMSFields(FieldList $fields)
    {
        if ($this->owner->indexEnabled()) {
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create('AlgoliaIndexed', _t(__CLASS__.'.LastIndexed', 'Last indexed in Algolia'))
            ]);
        }
    }

    /**
     * Returns whether this object should be indexed into Algolia.
     */
    public function canIndexInAlgolia(): bool
    {
        if ($this->owner->hasField('ShowInSearch')) {
            return $this->owner->hasField('ShowInSearch');
        }

        return true;
    }

    /**
     * When publishing the page, push this data to Algolia Indexer. The data
     * which is sent to Algolia is the rendered template from the front end.
     */
    public function onAfterPublish()
    {
        $this->owner->indexInAlgolia();
    }

    /**
     * Update the AlgoliaIndexed date for this object.
     */
    public function touchAlgoliaIndexedDate()
    {
        $schema = DataObject::getSchema();
        $table = $schema->tableForField($this->owner->ClassName, 'AlgoliaIndexed');

        if ($table) {
            DB::query(sprintf('UPDATE %s SET AlgoliaIndexed = NOW() WHERE ID = %s', $table, $this->owner->ID));

            if ($this->owner->hasExtension('SilverStripe\Versioned\Versioned')) {
                DB::query(sprintf('UPDATE %s_Live SET AlgoliaIndexed = NOW() WHERE ID = %s', $table, $this->owner->ID));
            }
        }
    }

    /**
     * Index this record into Algolia
     *
     * @return bool
     */
    public function indexInAlgolia(): bool
    {
        if ($this->owner->indexEnabled() && $this->owner->invokeWithExtensions('canIndexInAlgolia') === false) {
            return false;
        }

        if ($this->config()->get('use_queued_indexing')) {
            $indexJob = new AlgoliaIndexItemJob(get_class($this->owner), $this->owner->ID);
            QueuedJobService::singleton()->queueJob($indexJob);

            return true;
        } else {
            $indexer = Injector::inst()->get(AlgoliaIndexer::class);

            try {
                $indexer->indexItem($this->owner);

                $this->touchAlgoliaIndexedDate();

                return true;
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);

                return false;
            }
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
     */
    public function removeFromAlgolia()
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $indexer->setItem($this->owner);

        if ($this->config()->get('use_queued_indexing')) {
            $key = $indexer->generateUniqueID($this->owner);

            $indexDeleteJob = new AlgoliaDeleteItemJob($key);
            QueuedJobService::singleton()->queueJob($indexDeleteJob);
        } else {

            try {
                $indexer->deleteData();

                $this->touchAlgoliaIndexedDate();
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
            }
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
}
