<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\Map;
use SilverStripe\ORM\DataObject;
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
