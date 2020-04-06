<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\TestOnly;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

class TestAlgoliaService extends AlgoliaService implements TestOnly
{
    public function getClient()
    {
        return new TestAlgoliaServiceClient();
    }
}
