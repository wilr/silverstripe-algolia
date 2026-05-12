<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\Map;
use SilverStripe\Subsites\Model\Subsite;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

/**
 * @extends Extension<VirtualPage>
 */
class SubsitesVirtualPageExtension extends Extension
{
    /**
     * @param array<string, mixed> $toIndex
     */
    public function exportObjectToAlgolia(array $toIndex): Map
    {
        if (!class_exists(Subsite::class)) {
            $attributes = new Map(ArrayList::create());

            foreach ($toIndex as $k => $v) {
                $attributes->push($k, $v);
            }

            return $attributes;
        }
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
        $result = Subsite::withDisabledSubsiteFilter(function () use ($owner, $attributes, $indexer) {
            $originalObject = $owner->CopyContentFrom();

            if (!$originalObject->exists()) {
                return $attributes;
            }

            $attributes->push('objectClassName', $originalObject->ClassName);
            $attributes->push('objectSubsiteID', $owner->SubsiteID);

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
        });

        $attributes->push('SubsiteID', $this->owner->SubsiteID);

        return $result;
    }
}
