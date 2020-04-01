<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

class AlgoliaTestObject extends DataObject implements TestOnly
{
    private static $db = [
        'Title' => 'Varchar',
        'Active' => 'Boolean'
    ];

    private static $has_one = [
        'Author' => Member::class
    ];

    private static $many_many = [
        'RelatedTestObjects' => AlgoliaTestObject::class
    ];

    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }
}
