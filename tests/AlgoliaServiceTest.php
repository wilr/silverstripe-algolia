<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;
use Wilr\SilverStripe\Algolia\Tests\AlgoliaTestObject;

class AlgoliaServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        AlgoliaTestObject::class,
        AlgoliaCustomTestObject::class
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


    public function testInitIndexes()
    {
        $service = Injector::inst()->create(AlgoliaService::class);

        $service->indexes = [
            'testIndexTestObjects' => [
                'includeClasses' => [
                    AlgoliaTestObject::class
                ],
            ],
            'testIndexCustomTestObjects' => [
                'includeClasses' => [
                    AlgoliaCustomTestObject::class
                ],
            ],
        ];

        $testObj = new AlgoliaTestObject();
        $testObj->Title = 'Test';
        $testObj->Active = 1;
        $testObj->write();

        $testObj2 = new AlgoliaCustomTestObject();
        $testObj2->Title = 'Test';
        $testObj2->Active = 1;

        $this->assertEquals(['testIndexTestObjects'], array_keys($service->initIndexes($testObj)));
        $this->assertEquals(['testIndexCustomTestObjects'], array_keys($service->initIndexes($testObj2)));
    }


    public function testInitIndexesWithFilter()
    {
         $service = Injector::inst()->create(AlgoliaService::class);

        $service->indexes = [
            'testIndexTestObjects' => [
                'includeClasses' => [
                    AlgoliaTestObject::class
                ],
                'includeFilter' => [
                    AlgoliaTestObject::class => "Title != 'Ted'"
                ]
            ],
            'testIndexTestObjectsNamedTed' => [
                'includeClasses' => [
                    AlgoliaTestObject::class
                ],
                'includeFilter' => [
                    AlgoliaTestObject::class => "Title = 'Ted'"
                ]
            ],
        ];

        $testObj = new AlgoliaTestObject();
        $testObj->Title = 'Test';
        $testObj->Active = 1;
        $testObj->write();


        $testObj2 = new AlgoliaTestObject();
        $testObj2->Title = 'Ted';
        $testObj2->Active = 1;
        $testObj2->write();

        $this->assertEquals(['testIndexTestObjects'], array_keys($service->initIndexes($testObj)));
        $this->assertEquals(['testIndexTestObjectsNamedTed'], array_keys($service->initIndexes($testObj2)));
    }

}
