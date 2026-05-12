<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\TestOnly;

/**
 * Search client that serves {@see TestAlgoliaServiceIndexWithHit} instances.
 */
class TestAlgoliaServiceClientSearchHit extends TestAlgoliaServiceClient implements TestOnly
{
    /**
     * @var array<string, TestAlgoliaServiceIndexWithHit>
     */
    private static array $indexesByName = [];

    public function initIndex($name)
    {
        if (!isset(self::$indexesByName[$name])) {
            self::$indexesByName[$name] = new TestAlgoliaServiceIndexWithHit($name, $this->api, $this->config);
        }

        return self::$indexesByName[$name];
    }
}
