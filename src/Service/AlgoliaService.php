<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\AlgoliaSearch\SearchIndex;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\Security\Security;
use SilverStripe\ORM\DataObject;
use Throwable;
use Exception;

class AlgoliaService
{
    use Injectable;

    public string $adminApiKey = '';

    public string $searchApiKey = '';

    public string $applicationId = '';

    /** @var array<string, mixed> */
    public array $indexes = [];

    protected ?SearchClient $client = null;

    /** @var array<string, SearchIndex> */
    protected array $preloadedIndexes = [];

    /**
     * @return \Algolia\AlgoliaSearch\SearchClient
     */
    public function getClient(): SearchClient
    {
        $existing = $this->client;
        if ($existing instanceof SearchClient) {
            return $existing;
        }

        if (!$this->adminApiKey) {
            throw new Exception('No adminApiKey configured for ' . self::class);
        }

        if (!$this->applicationId) {
            throw new Exception('No applicationId configured for ' . self::class);
        }

        $client = SearchClient::create($this->applicationId, $this->adminApiKey);
        $this->client = $client;

        return $client;
    }

    /**
     * @return \Algolia\AlgoliaSearch\SearchClient
     */
    public function getSearchClient(): SearchClient
    {
        $existing = $this->client;
        if ($existing instanceof SearchClient) {
            return $existing;
        }

        if (!$this->searchApiKey) {
            throw new Exception('No searchApiKey configured for ' . self::class);
        }

        if (!$this->applicationId) {
            throw new Exception('No applicationId configured for ' . self::class);
        }

        $client = SearchClient::create($this->applicationId, $this->searchApiKey);
        $this->client = $client;

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    public function getIndexes(bool $excludeReplicas = true): array
    {
        if (!$excludeReplicas) {
            return $this->indexes;
        }

        $replicas = [];
        $output = [];

        foreach ($this->indexes as $indexName => $data) {
            if (isset($data['indexSettings']) && isset($data['indexSettings']['replicas'])) {
                foreach ($data['indexSettings']['replicas'] as $replicaName) {
                    $replicas[$replicaName] = $replicaName;
                }
            }
        }

        foreach ($this->indexes as $indexName => $data) {
            if (in_array($indexName, $replicas)) {
                continue;
            }

            $output[$indexName] = $data;
        }

        return $output;
    }


    public function getIndexByName(string $name): SearchIndex
    {
        $indexes = $this->initIndexes();

        if (!isset($indexes[$name])) {
            throw new Exception(sprintf(
                'Index ' . $name . ' not found, must be one of [%s]',
                implode(', ', array_keys($indexes))
            ));
        }

        return $indexes[$name];
    }


    /**
     * @param DataObject|string|array<string, mixed>|null $item
     *    Instance, class-string, legacy stub array, or null to warm every configured index handle.
     * @return array<string, SearchIndex>
     */
    public function initIndexes(DataObject|string|array|null $item = null, bool $excludeReplicas = true): array
    {
        if (!Security::database_is_ready()) {
            return [];
        }

        try {
            $client = $this->getClient();
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);

            if (Director::isDev()) {
                Debug::message($e->getMessage());
            }

            return [];
        }
        if (!$item) {
            if ($this->preloadedIndexes) {
                return $this->preloadedIndexes;
            }

            $indexes = $this->getIndexes($excludeReplicas);

            $this->preloadedIndexes = [];

            foreach ($indexes as $indexName => $data) {
                $this->preloadedIndexes[$indexName] = $client->initIndex($this->environmentizeIndex($indexName));
            }

            return $this->preloadedIndexes;
        }

        if (is_string($item)) {
            $resolved = Injector::inst()->get($item);
            $item = $resolved instanceof DataObject ? $resolved : null;
        } elseif (is_array($item)) {
            if (!isset($item['objectClassName']) || !is_string($item['objectClassName'])) {
                return [];
            }

            $resolved = Injector::inst()->get($item['objectClassName']);
            $item = $resolved instanceof DataObject ? $resolved : null;
        }

        if (!$item instanceof DataObject) {
            return [];
        }

        $matches = [];

        $replicas = [];

        foreach ($this->indexes as $indexName => $data) {
            $classes = (isset($data['includeClasses'])) ? $data['includeClasses'] : null;
            $filter = (isset($data['includeFilter'])) ? $data['includeFilter'] : null;

            if ($classes) {
                foreach ($classes as $candidate) {
                    if ($item instanceof $candidate) {
                        if (method_exists($item, 'shouldIncludeInIndex') && !$item->shouldIncludeInIndex($indexName)) {
                            continue;
                        }

                        if ($filter && isset($filter[$candidate])) {
                            // check to see if this item matches the filter.
                            $check = $candidate::get()->filter([
                                'ID' => $item->ID,
                            ])->where($filter[$candidate])->first();

                            if (!$check) {
                                continue;
                            }
                        }

                        $matches[] = $indexName;

                        break;
                    }
                }
            }

            if (isset($data['indexSettings']) && isset($data['indexSettings']['replicas'])) {
                foreach ($data['indexSettings']['replicas'] as $replicaName) {
                    $replicas[$replicaName] = $replicaName;
                }
            }
        }

        $output = [];

        foreach ($matches as $index) {
            if (in_array($index, array_keys($replicas)) && $excludeReplicas) {
                continue;
            }

            $output[$index] = $client->initIndex($this->environmentizeIndex($index));
        }

        return $output;
    }

    /**
     * Prefixes the given indexName with the configured prefix, or environment
     * type.
     *
     * @param string $indexName
     *
     * @return string
     */
    public function environmentizeIndex(string $indexName): string
    {
        $prefix = Environment::getEnv('ALGOLIA_PREFIX_INDEX_NAME');

        if ($prefix === false) {
            $prefix = Director::get_environment_type();
        }

        return sprintf("%s_%s", $prefix, $indexName);
    }


    /**
     * Sync setting from YAML configuration into Algolia.
     *
     * This runs automatically on dev/build operations.
     */
    public function syncSettings(): bool
    {
        $config = $this->indexes;

        if (!$config) {
            return false;
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
                                    return $this->environmentizeIndex($replica);
                                },
                                $data['indexSettings']['replicas']
                            );
                        }

                        $index->setSettings($data['indexSettings']);
                    } catch (Throwable $e) {
                        Injector::inst()->get(LoggerInterface::class)->error($e);


                        return false;
                    }
                }
            }
        }


        return true;
    }
}
