<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\Map;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class VirtualPageExtension extends Extension
{
    public function exportObjectToAlgolia($toIndex)
    {
        $attributes = new Map(ArrayList::create());

        foreach ($toIndex as $k => $v) {
            if ($k === 'objectClassName') {
                continue;
            }

            $attributes->push($k, $v);
        }

        /** @var AlgoliaIndexer  */
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $owner = $this->owner;

        // get original object
        $originalObject = $owner->CopyContentFrom();

        if (!$originalObject) {
            return $attributes;
        }

        $attributes->push('objectClassName', $originalObject->ClassName);
        $specs = $originalObject->config()->get('algolia_index_fields');
        $attributes = $indexer->addSpecsToAttributes($originalObject, $attributes, $specs);

        $originalObject->invokeWithExtensions('updateAlgoliaAttributes', $attributes);

        return $attributes;
    }
}
