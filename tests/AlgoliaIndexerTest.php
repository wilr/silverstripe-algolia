<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\SapphireTest;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class AlgoliaIndexerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        AlgoliaTestObject::class
    ];

    public function testExportAttributesForObject()
    {
        $object = AlgoliaTestObject::create();
        $object->Title = 'Foobar';
        $object->write();

        $indexer = new AlgoliaIndexer();
        $map = $indexer->exportAttributesFromObject($object)->toArray();

        $this->assertArrayHasKey('objectID', $map);
        $this->assertEquals($map['objectTitle'], 'Foobar');
    }
}
