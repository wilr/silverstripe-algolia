<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\SearchClient;
use Exception;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

class AlgoliaService
{
    use Injectable;

    public $adminApiKey = '';

    public $searchApiKey = '';

    public $applicationId = '';

    public $indexes = [];

    protected $client;

    /**
     * @return \Algolia\AlgoliaSearch\SearchClient
     */
    public function getClient()
    {
        if (!$this->client) {
            if (!$this->adminApiKey) {
                throw new Exception('No adminApiKey configured for '. self::class);
            }

            if (!$this->applicationId) {
                throw new Exception('No applicationId configured for '. self::class);
            }

            $this->client = SearchClient::create(
                $this->applicationId,
                $this->adminApiKey
            );
        }

        return $this->client;
    }

    /**
     * Returns an array of all the indexes which need the given item or item
     * class. If no item provided, returns a list of all the indexes defined.
     *
     * @param DataObject|string|null $item
     *
     * @return \Algolia\AlgoliaSearch\SearchIndex[]
     */
    public function initIndexes($item = null)
    {
        if (!$item) {
            return array_map(function ($indexName) {
                return $this->environmentizeIndex($indexName);
            }, array_keys($this->indexes));
        }

        if (is_string($item)) {
            $item = Injector::inst()->get($item);
        } else if (is_array($item)) {
            $item = Injector::inst()->get($item['objectClassName']);
        }

        $matches = [];

        foreach ($this->indexes as $indexName => $data) {
            $classes = (isset($data['includeClasses'])) ? $data['includeClasses'] : null;

            if ($classes) {
                foreach ($classes as $candidate) {
                    if ($item instanceof $candidate) {
                        $matches[] = $indexName;

                        break;
                    }
                }
            }
        }

        $output = [];

        foreach ($matches as $index) {
            $output[$index] = $this->getClient()->initIndex($this->environmentizeIndex($index));
        }

        return $output;
    }

    /**
     * @param string $indexName
     *
     * @return string
     */
    public function environmentizeIndex($indexName)
    {
        return sprintf("%s_%s", Director::get_environment_type(), $indexName);
    }
}
