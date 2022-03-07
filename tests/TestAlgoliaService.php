<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\TestOnly;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

class TestAlgoliaService extends AlgoliaService implements TestOnly
{
    public $indexes = [
        'testIndex' => [
            'includeClasses' => [
                AlgoliaTestObject::class
            ]
        ]
    ];

    public function getClient()
    {
        return TestAlgoliaServiceClient::create('ABC', '123');
    }
}
