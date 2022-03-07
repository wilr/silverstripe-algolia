<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use Algolia\AlgoliaSearch\SearchClient;
use SilverStripe\Dev\TestOnly;

class TestAlgoliaServiceClient extends SearchClient implements TestOnly
{
    public function initIndex($name)
    {
        return new TestAlgoliaServiceIndex($name, $this->api, $this->config);
    }
}
