<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

class AlgoliaTestObject extends DataObject implements TestOnly
{
    private static $db = [
        'Title' => 'Varchar',
        'OtherField' => 'Varchar',
        'NonIndexedField' => 'Varchar',
        'Active' => 'Boolean'
    ];

    private static $has_one = [
        'Author' => Member::class
    ];

    private static $many_many = [
        'RelatedTestObjects' => AlgoliaTestObject::class
    ];

    private static $algolia_index_fields = [
        'OtherField',
        'Active'
    ];

    private static $extensions = [
        AlgoliaObjectExtension::class
    ];

    private static $table_name = 'AlgoliaTestObject';


    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }


    public function canIndexInAlgolia(): bool
    {
        return ($this->Active) ? true : false;
    }
}
