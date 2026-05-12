<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\Map;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

/**
 * @extends Extension<VirtualPage>
 */
class VirtualPageExtension extends Extension
{
    /**
     * @param array<string, mixed> $toIndex
     */
    public function exportObjectToAlgolia(array $toIndex): Map
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

        if (!$originalObject->exists()) {
            return $attributes;
        }

        $attributes->push('objectClassName', $originalObject->ClassName);
        $specsRaw = $originalObject->config()->get('algolia_index_fields');

        $specStrings = [];

        if (is_iterable($specsRaw)) {
            foreach ($specsRaw as $fieldSpec) {
                if (!is_scalar($fieldSpec)) {
                    continue;
                }

                $specStrings[] = (string) $fieldSpec;
            }
        }

        if ($specStrings !== []) {
            $attributes = $indexer->addSpecsToAttributes($originalObject, $attributes, $specStrings);
        }

        $originalObject->invokeWithExtensions('updateAlgoliaAttributes', $attributes);

        return $attributes;
    }
}
