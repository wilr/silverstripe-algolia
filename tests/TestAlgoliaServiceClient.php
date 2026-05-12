<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use Algolia\AlgoliaSearch\SearchClient;
use SilverStripe\Dev\TestOnly;

class TestAlgoliaServiceClient extends SearchClient implements TestOnly
{
    /**
     * @var array<string, TestAlgoliaServiceIndex>
     */
    private static array $indexesByName = [];

    public function initIndex($name)
    {
        if (!isset(self::$indexesByName[$name])) {
            self::$indexesByName[$name] = new TestAlgoliaServiceIndex($name, $this->api, $this->config);
        }

        return self::$indexesByName[$name];
    }
}
