<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\ORM\DataObjectSchema;
use Wilr\SilverStripe\Algolia\Service\AlgoliaQuerier;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

class AlgoliaQuerierTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        AlgoliaTestObject::class
    ];

    protected static $required_extensions = [
        AlgoliaTestObject::class => [
            AlgoliaObjectExtension::class
        ]
    ];

    public static function setUpBeforeClass(): void
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

    public function testGetLastResultReturnsSearchPayload(): void
    {
        $querier = Injector::inst()->get(AlgoliaQuerier::class);
        $querier->fetchResults('testIndex', 'needle');

        $this->assertIsArray($querier->getLastResult());
    }

    public function testFetchResultsUsesFirstConfiguredIndexWhenNameIsNull(): void
    {
        $querier = Injector::inst()->get(AlgoliaQuerier::class);
        $results = $querier->fetchResults(null, 'query');

        $this->assertInstanceOf(PaginatedList::class, $results);
    }

    public function testFetchResultsHydratesHitsAndAppliesOrmFilters(): void
    {
        $this->logInWithPermission('ADMIN');

        $obj = AlgoliaTestObject::create();
        $obj->Active = true;
        $obj->Title = 'UniqueHitTitle';
        $obj->write();

        Injector::inst()->registerService(new TestAlgoliaServiceSearchHit(), AlgoliaService::class);
        TestAlgoliaServiceIndexWithHit::$objectSilverstripeId = (int) $obj->ID;

        $querier = Injector::inst()->get(AlgoliaQuerier::class);
        $page = $querier->fetchResults('testIndex', 'needle', [], ['Title' => 'UniqueHitTitle']);

        $this->assertSame(1, $page->getTotalItems());
        $this->assertCount(1, $page->toArray());
    }

    protected function tearDown(): void
    {
        Injector::inst()->registerService(new TestAlgoliaService(), AlgoliaService::class);
        TestAlgoliaServiceIndexWithHit::$objectSilverstripeId = 0;

        parent::tearDown();
    }
}
