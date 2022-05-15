<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

class AlgoliaIndexerTest extends SapphireTest
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

    public function testExportAttributesForObject()
    {
        $object = AlgoliaTestObject::create();
        $object->Title = 'Foobar';
        $object->write();
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $map = $indexer->exportAttributesFromObject($object)->toArray();

        $this->assertArrayHasKey('objectID', $map);
        $this->assertEquals($map['objectTitle'], 'Foobar');

        $object = AlgoliaCustomTestObject::create();
        $object->Title = 'Qux';
        $object->write();

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $map = $indexer->exportAttributesFromObject($object)->toArray();

        $this->assertArrayHasKey('objectID', $map);
        $this->assertEquals($map['objectTitle'], 'Qux');
        $this->assertEquals($map['MyCustomField'], 'MyCustomFieldValue');
    }


    public function testDeleteExistingItem()
    {
        $object = AlgoliaTestObject::create();
        $object->Title = 'Delete This';
        $object->write();

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $deleted = $indexer->deleteItem($object->getClassName(), $object->AlgoliaUUID);

        return $this->assertTrue($deleted);
    }

    public function testDeleteNonExistentItem()
    {
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $deleted = $indexer->deleteItem(AlgoliaTestObject::class, 9999999);

        return $this->assertFalse($deleted);
    }
}
