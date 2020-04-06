<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\TestOnly;

class TestAlgoliaServiceClient implements TestOnly
{
    public function initIndex($name)
    {
        return new TestAlgoliaServiceIndex();
    }
}
