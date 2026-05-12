<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use Algolia\AlgoliaSearch\SearchClient;
use SilverStripe\Dev\TestOnly;

/**
 * {@see AlgoliaService} stub that routes searches through {@see TestAlgoliaServiceClientSearchHit}.
 */
class TestAlgoliaServiceSearchHit extends TestAlgoliaService implements TestOnly
{
    public function getSearchClient(): SearchClient
    {
        return TestAlgoliaServiceClientSearchHit::create('ABC', 'search-key');
    }
}
