<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataExtension;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class AlgoliaSiteTreeExtension extends DataExtension
{
    private static $enable_indexer = true;

    private static $db = [
        'AlgoliaIndexed' => 'Datetime'
    ];

    public function indexEnabled(): bool
    {
        return $this->owner->stat('enable_indexer') ? true : false;
    }

    public function updateSettingsFields(FieldList $fields)
    {
        if ($this->owner->enable_indexer()) {
            $fields->addFieldsToTab('Root.Settings', [
                LiteralField::create('LastUpdated', 'Last indexed: ' . $this->owner->LastEdited . '')
            ]);
        }
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
     * Should this record be indexed.
     *
     * @return bool
     */
    public function indexInAlgolia(): bool
    {
        if ($this->owner->indexEnabled() && $this->owner->ShowInSearch) {
            $indexer = Injector::inst()->get(AlgoliaIndexer::class);
            $indexer->setItem($this->owner);

            try {
                $indexer->indexData();

                return true;
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);

                return false;
            }
        } else {
            return true;
        }
    }

    /**
     *
     */
    public function onBeforeDelete()
    {
        if ($this->owner->indexEnabled()) {
            $indexer = Injector::inst()->get(AlgoliaIndexer::class);
            $indexer->setItem($this->owner);

            try {
                $indexer->deleteData();
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
            }
        }
    }
}
