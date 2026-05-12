<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use Algolia\AlgoliaSearch\SearchClient;
use SilverStripe\Dev\TestOnly;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

class TestAlgoliaService extends AlgoliaService implements TestOnly
{
    public string $applicationId = 'test-app-id';

    public array $indexes = [
        'testIndex' => [
            'includeClasses' => [
                AlgoliaTestObject::class,
            ],
        ],
    ];

    public function getClient(): SearchClient
    {
        return TestAlgoliaServiceClient::create('ABC', '123');
    }

    public function getSearchClient(): SearchClient
    {
        return TestAlgoliaServiceClient::create('ABC', 'search-key');
    }
}
