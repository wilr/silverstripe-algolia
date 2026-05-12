<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\SapphireTest;
use Wilr\SilverStripe\Algolia\Extensions\SubsitesVirtualPageExtension;

/**
 * Exercises the branch used when the optional subsites module is not installed.
 */
class AlgoliaSubsitesVirtualPageExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        AlgoliaTestObject::class,
    ];

    public function testExportObjectToAlgoliaPassesThroughWhenSubsiteClassMissing(): void
    {
        $this->assertFalse(\class_exists(\SilverStripe\Subsites\Model\Subsite::class));

        $owner = AlgoliaTestObject::create();
        $owner->Title = 'Owner';
        $owner->write();

        $ext = new SubsitesVirtualPageExtension();
        $ext->setOwner($owner);

        $map = $ext->exportObjectToAlgolia([
            'objectClassName' => AlgoliaTestObject::class,
            'objectTitle' => 'T',
        ]);

        $arr = $map->toArray();
        $this->assertSame(AlgoliaTestObject::class, $arr['objectClassName'] ?? null);
        $this->assertSame('T', $arr['objectTitle'] ?? null);
    }
}
