<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\SearchClient;
use Exception;
use Psr\Log\LoggerInterface;
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
            return array_map(
                function ($indexName) {
                    return $this->getClient()->initIndex($this->environmentizeIndex($indexName));
                },
                array_keys($this->indexes)
            );
        }

        if (is_string($item)) {
            $item = Injector::inst()->get($item);
        } elseif (is_array($item)) {
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


    /**
     * Sync setting from YAML configuration into Algolia.
     *
     * This runs automatically on dev/build operations.
     */
    public function syncSettings()
    {
        $config = $this->indexes;

        if (!$config) {
            return;
        }

        foreach ($config as $index => $data) {
            $indexName = $this->environmentizeIndex($index);

            if (isset($data['indexSettings'])) {
                $index = $this->getClient()->initIndex($indexName);

                if ($index) {
                    try {
                        // update any replica indexes with the environment
                        if (isset($data['indexSettings']['replicas'])) {
                            $data['indexSettings']['replicas'] = array_map(
                                function ($replica) {
                                    return Director::get_environment_type() . '_' . $replica;
                                },
                                $data['indexSettings']['replicas']
                            );
                        }

                        $index->setSettings($data['indexSettings']);
                    } catch (Exception $e) {
                        Injector::inst()->create(LoggerInterface::class)->error($e);

                        if (Director::isDev()) {
                            throw $e;
                        }
                    }
                }
            }
        }
    }
}
