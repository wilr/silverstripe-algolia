<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\SearchClient;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SiteConfig\SiteConfig;

class AlgoliaService
{
    use Configurable;
    use Injectable;

    private $apiKey;

    private $applicationID;

    private $indexName;

    private $client;

    /**
     *
     */
    public function __construct()
    {
        $siteConfig = SiteConfig::current_site_config();

        $this->apiKey = $siteConfig->adminAPIKey;
        $this->applicationID = $siteConfig->applicationID;
        $this->indexName = $siteConfig->indexName;
    }

    /**
     * @param string $indexName
     *
     * @return $this
     */
    public function setIndexName($indexName)
    {
        $this->indexName = $indexName;

        return $this;
    }

    /**
     * @return \Algolia\AlgoliaSearch\SearchClient
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
     * @return \Algolia\AlgoliaSearch\SearchIndex
     */
    public function initIndex()
    {
        return $this->getClient()->initIndex($this->indexName);
    }
}
