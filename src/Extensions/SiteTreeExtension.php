<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataExtension;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class SiteTreeExtension extends DataExtension
{
    private static $enable_indexer = true;

    private static $db = [
        'AlgoliaIndexed' => 'SS_Datetime'
    ];

    public function enable_indexer(): bool
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
        if ($this->owner->enable_indexer() && $this->owner->ShowInSearch) {
            $indexer = Injector::inst()->create(AlgoliaIndexer::class, $this->owner);
            $indexer->indexData();

            return true;
        } else {
            return true;
        }
    }

    /**
     *
     */
    public function onBeforeDelete()
    {
        if ($this->owner->enable_indexer()) {
            $indexer = Injector::inst()->create(AlgoliaIndexer::class, $this->owner);
            $indexer->deleteData();
        }
    }
}
