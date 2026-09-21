<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Control\Director;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Model\List\Map;
use SilverStripe\ORM\DataObject;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

/**
 * Raises a PHP Error (not an Exception) from updateAlgoliaAttributes(), which runs
 * outside the per-attribute guard in AlgoliaIndexer::addSpecsToAttributes().
 */
class FatalHookAlgoliaTestObject extends DataObject implements TestOnly
{
    private static $db = [
        'Title' => 'Varchar',
        'Broken' => 'Boolean',
    ];

    private static $extensions = [
        AlgoliaObjectExtension::class,
    ];

    private static $table_name = 'FatalHookAlgoliaTestObject';

    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }

    /**
     * @param Map $attributes
     */
    public function updateAlgoliaAttributes($attributes): void
    {
        if (!$this->Broken) {
            $attributes->push('_tags', ['fine']);

            return;
        }

        $notAnArray = 'a string';

        // TypeError: Cannot access offset of type string on string
        $attributes->push('_tags', [$notAnArray['objectTitle']]);
    }
}
