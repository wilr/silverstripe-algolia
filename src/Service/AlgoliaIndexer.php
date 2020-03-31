<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\SiteConfig\SiteConfig;
use Algolia\AlgoliaSearch\SearchClient;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Map;

class AlgoliaIndexer
{
    use Configurable;
    use Injectable;

    /**
     * Include rendered markup from the object's `Link` method in the index.
     *
     * @config
     */
    private static $include_page_content = true;

    private $item;

    private $apiKey;

    private $applicationID;

    private $indexName;

    private $client;


    public function __construct()
    {
        $siteConfig = SiteConfig::current_site_config();

        $this->apiKey = $siteConfig->adminAPIKey;
        $this->applicationID = $siteConfig->applicationID;
        $this->indexName = $siteConfig->indexName;
    }

    /**
     * @param AlgoliaSearchable
     */
    public function setItem($item)
    {
        $this->item = $item;

        return $this;
    }

    /**
     * @return Algolia\AlgoliaSearch\SearchClient
     */
    public function getClient()
    {
        if (!$this->client) {
            $this->client = SearchClient::create(
                $this->applicationID,
                $this->apiKey
            );
        }

        return $this->client;
    }

    /**
     * @return bool
     */
    public function indexData()
    {
        $item = $this->item;
        $client = $this->getClient();

        $searchIndex = $client->initIndex($this->indexName);

        if (!$item->ShowInSearch) {
            $this->deleteData();

            return;
        }

        $fields = $this->algoliaFields();

        $item->invokeWithExtensions('updateAlgoliaFields', $fields);

        foreach ($fields as $k => $v) {
            $toIndex[$k] = $v;
        }

        $searchIndex->saveObject($toIndex);
    }

    /**
     * @return SilverStripe\ORM\Map
     */
    public function algoliaFields()
    {
        $item = $this->item;

        $toIndex = [
            'objectID' => $item->ID,
            'objectTitle' => $item->Title,
            'objectLastEdited' => $item->dbObject('LastEdited')->Rfc822(),
            'objectLink' => str_replace(['?stage=Stage', '?stage=Live'], '', $item->AbsoluteLink())
        ];

        if ($this->config()->get('include_page_content')) {
            $toIndex['objectForTemplate'] =
                Injector::inst()->create(AlgoliaPageCrawler::class, $item)->getMainContent();
        }

        $specs = DataObject::getSchema()->fieldSpecs(get_class($item));
        $fields = new Map(ArrayList::create());

        foreach ($toIndex as $k => $v) {
            $fields->push($k, $v);
        }

        foreach ($specs as $k => $v) {
            if (in_array($k, ['ID', 'Title', 'AlgoliaIndexed', 'CanViewType', 'CanEditType', 'Locale'])) {
                continue;
            }

            try {
                $obj = $item->dbObject($k);

                if ($obj) {
                    $fields->push($k, $obj->forTemplate());
                }
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
            }
        }

        if ($manyMany = $item->manyMany()) {
            foreach ($manyMany as $relationship => $class) {
                foreach ($item->{$relationship}() as $relatedObj) {
                    // @todo
                }
            }
        }

        if ($hasMany = $item->hasMany()) {
            foreach ($hasMany as $relationship => $class) {
                foreach ($item->{$relationship}() as $relatedObj) {
                    // @todo
                }
            }
        }

        if ($hasOne = $item->hasOne()) {
            foreach ($hasOne as $relationship => $class) {
                foreach ($item->{$relationship}() as $relatedObj) {
                    // @todo
                }
            }
        }
    }

    public function deleteData()
    {
        $item = $this->item;

        $client = $this->getClient();
        $searchIndex = $client->initIndex($this->indexName);

        $searchIndex->deleteObject($item->ID);
    }
}
