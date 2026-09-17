<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

class ExplodingAlgoliaTestObject extends DataObject implements TestOnly
{
    private static $db = [
        'Title' => 'Varchar',
        'BrokenField' => ExplodingTestDBField::class,
        'OtherField' => 'Varchar',
    ];

    private static $algolia_index_fields = [
        'BrokenField',
        'OtherField',
    ];

    private static $extensions = [
        AlgoliaObjectExtension::class,
    ];

    private static $table_name = 'ExplodingAlgoliaTestObject';

    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }
}
