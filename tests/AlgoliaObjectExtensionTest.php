<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

class AlgoliaObjectExtensionTest extends SapphireTest
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

    public function testIndexInAlgolia()
    {
        $object = AlgoliaTestObject::create();
        $object->Active = false;
        $object->write();

        $this->assertFalse(
            min($object->invokeWithExtensions('canIndexInAlgolia')),
            'Objects with canIndexInAlgolia() false should not index'
        );

        $object->Active = true;
        $object->write();

        $this->assertTrue(
            min($object->invokeWithExtensions('canIndexInAlgolia')),
            'Objects with canIndexInAlgolia() set to true should index'
        );

        $index = $object->indexInAlgolia();
        $this->assertTrue($index, 'Indexed in Algolia');
    }

    public function testIndexEnabledReflectsConfig(): void
    {
        Config::modify()->set(AlgoliaObjectExtension::class, 'enable_indexer', false);

        $object = AlgoliaTestObject::create();
        $object->write();

        $this->assertFalse($object->indexEnabled());
    }

    public function testTouchAlgoliaIndexedDate()
    {
        $object = AlgoliaTestObject::create();
        $object->write();

        $object->touchAlgoliaIndexedDate();

        $this->assertNotNull(
            DB::query(
                sprintf(
                    'SELECT AlgoliaIndexed FROM AlgoliaTestObject WHERE ID = %s',
                    $object->ID
                )
            )->value()
        );

        $object->touchAlgoliaIndexedDate(true);

        $this->assertNull(
            DB::query(
                sprintf(
                    'SELECT AlgoliaIndexed FROM AlgoliaTestObject WHERE ID = %s',
                    $object->ID
                )
            )->value()
        );
    }

    public function testShouldBlockIndexingForAlgoliaStatic(): void
    {
        $blocked = AlgoliaTestObject::create();
        $blocked->Active = false;
        $blocked->write();

        $this->assertTrue(AlgoliaObjectExtension::shouldBlockIndexingForAlgolia($blocked));

        $allowed = AlgoliaTestObject::create();
        $allowed->Active = true;
        $allowed->write();

        $this->assertFalse(AlgoliaObjectExtension::shouldBlockIndexingForAlgolia($allowed));
    }

    public function testRemoveFromAlgoliaWithoutUuidReturnsFalse(): void
    {
        $object = AlgoliaTestObject::create();
        $object->Active = true;
        $object->write();

        DB::query(sprintf(
            "UPDATE AlgoliaTestObject SET AlgoliaUUID = '' WHERE ID = %d",
            (int) $object->ID
        ));

        $reloaded = AlgoliaTestObject::get()->byID($object->ID);
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->removeFromAlgolia());
    }

    public function testGetAlgoliaIndexesReturnsInitHandles(): void
    {
        $object = AlgoliaTestObject::create();
        $object->Active = true;
        $object->Title = 'Indexed';
        $object->write();

        $indexes = $object->getAlgoliaIndexes();

        $this->assertArrayHasKey('testIndex', $indexes);
        $this->assertIsObject($indexes['testIndex']);
    }
}
