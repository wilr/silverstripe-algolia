<?php

namespace Wilr\SilverStripe\Algolia\Tests;

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

    public static function setUpBeforeClass()
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

        $this->assertFalse($object->canIndexInAlgolia(), 'Objects with canIndexInAlgolia() set to false should not index');

        $object->Active = true;
        $object->write();

        $this->assertTrue($object->canIndexInAlgolia(), 'Objects with canIndexInAlgolia() set to true should index');

        $index = $object->indexInAlgolia();

        $this->assertTrue($index, 'Indexed in Algolia');
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
}
