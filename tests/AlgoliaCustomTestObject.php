<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Map;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

class AlgoliaCustomTestObject extends DataObject implements TestOnly
{
    private static $db = [
        'Title' => 'Varchar'
    ];

    private static $extensions = [
        AlgoliaObjectExtension::class
    ];

    private static $table_name = 'AlgoliaCustomTestObject';


    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }


    public function exportObjectToAlgolia($data)
    {
        $data = array_merge($data, [
            'MyCustomField' => 'MyCustomFieldValue'
        ]);

        $map = new Map(ArrayList::create());

        foreach ($data as $k => $v) {
            $map->push($k, $v);
        }

        return $map;
    }
}
