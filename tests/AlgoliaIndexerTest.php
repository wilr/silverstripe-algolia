<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Config\Config;
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

        return $this->assertTrue($deleted);
    }

    public function testIndexItemPersistsToMockIndex(): void
    {
        $object = AlgoliaTestObject::create();
        $object->Title = 'Indexed';
        $object->Active = true;
        $object->write();

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $this->assertTrue($indexer->indexItem($object));

        $remote = $indexer->getObject($object);
        $this->assertNotEmpty($remote);
    }

    public function testIndexItemsSavesBatchToMock(): void
    {
        $one = AlgoliaTestObject::create();
        $one->Active = true;
        $one->Title = 'One';
        $one->write();

        $two = AlgoliaTestObject::create();
        $two->Active = true;
        $two->Title = 'Two';
        $two->write();

        $list = AlgoliaTestObject::get()->filter('ID', [$one->ID, $two->ID])->sort('ID');

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $indexer->indexItems($list);

        $this->assertNotEmpty($indexer->getObject($one));
        $this->assertNotEmpty($indexer->getObject($two));
    }

    public function testExportAttributesIncludesManyManyRelationship(): void
    {
        Config::modify()->merge(AlgoliaTestObject::class, 'algolia_index_fields', [
            'RelatedTestObjects',
        ]);

        $related = AlgoliaTestObject::create();
        $related->Active = true;
        $related->Title = 'Related';
        $related->write();

        $parent = AlgoliaTestObject::create();
        $parent->Active = true;
        $parent->Title = 'Parent';
        $parent->write();
        $parent->RelatedTestObjects()->add($related);

        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $map = $indexer->exportAttributesFromObject($parent);
        $data = $map->toArray();

        $this->assertArrayHasKey('RelatedTestObjects', $data);
        $this->assertIsArray($data['RelatedTestObjects']);
        $this->assertNotEmpty($data['RelatedTestObjects']);
    }
}
