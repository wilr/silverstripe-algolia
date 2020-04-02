<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\SearchClient;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

class AlgoliaService
{
    use Configurable;
    use Injectable;

    /**
     * @var string
     *
     * @config
     */
    private static $admin_api_key;

    /**
     * @var string
     *
     * @config
     */
    private static $search_api_key;

    /**
     * @var string
     *
     * @config
     */
    private static $application_id;

    /**
     * Maps a set of classes to a given index name in Algolia.
     *
     * A class can exist in more than one index. For instance, you may wish to have
     * your entire site under a 'generalSearch' index but also push products to a
     * special 'productSearch' index. The below link contains good references for
     * when you may need to do this.
     *
     * algolia.com/doc/guides/sending-and-managing-data/prepare-your-data/in-depth/choosing-between-one-or-more-indices/
     *
     * Definition should be in the following format
     *
     * ```yml
     * Wilr\SilverStripe\Algolia\Service\AlgoliaService:
     *  index_class_mapping:
     *    indexName:
     *      includeClasses:
     *          - Page
     *          - Member
     *      indexSettings:
     *          attributesForFaceting:
     *              - 'filterOnly(ObjectClassName)'
     * ```
     *
     * `includeClasses` must be an array of classnames, `indexSettings` should
     * follow the format listed on the following link:
     *
     * https://www.algolia.com/doc/api-reference/settings-api-parameters/
     */
    private static $index_class_mapping = [];

    private $client;

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
     * Returns an array of all the indexes which need the given item or item
     * class. If no item provided, returns a list of all the indexes defined.
     *
     * @param DataObject|string|null $item
     *
     * @return \Algolia\AlgoliaSearch\SearchIndex[]
     */
    public function initIndexes($item = null)
    {
        $mapping = $this->config()->get('index_class_mapping');

        if (!$item) {
            return array_keys($mapping);
        }

        if (is_string($item)) {
            $item = Injector::inst()->get($item);
        } else if (is_array($item)) {
            $item = Injector::inst()->get($item['objectClassName']);
        }

        $matches = [];

        foreach ($mapping as $indexName => $data) {
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
            $output[$index] = $this->getClient()->initIndex($index);
        }

        return $output;
    }
}
