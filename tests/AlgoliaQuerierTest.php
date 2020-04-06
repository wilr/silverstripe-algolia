<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\PaginatedList;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaQuerier;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

class AlgoliaQuerierTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        AlgoliaTestObject::class
    ];

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        // mock AlgoliaService
        Injector::inst()->get(DataObjectSchema::class)->reset();
        Injector::inst()->registerService(new TestAlgoliaService(), AlgoliaService::class);
    }

    public function testFetchResults()
    {
        $results = Injector::inst()->get(AlgoliaQuerier::class)->fetchResults('indexName', 'search keywords');

        $this->assertInstanceOf(PaginatedList::class, $results);
    }
}
