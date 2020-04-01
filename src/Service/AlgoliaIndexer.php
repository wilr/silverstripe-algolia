<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Map;
use stdClass;

/**
 * Handles all the index management and communication with Algolia. Note that
 * any checking of records should be performed by the caller of these methods as
 * no permission checking is done by this class
 */
class AlgoliaIndexer extends AlgoliaService
{

    /**
     * Include rendered markup from the object's `Link` method in the index.
     *
     * @config
     */
    private static $include_page_content = true;

    /**
     * Attributes we don't want to add to Algolia. Either because they are
     * generally useless or because they are 'special' and we use the Special
     * attribute name e.g `objectID`.
     *
     * @config
     */
    private static $attributes_blacklisted = [
        'ID', 'Title', 'LastEdited', 'AlgoliaIndexed', 'Sort', 'ShowInSearch',
        'NextReviewDate', 'ReviewPeriodDays', 'ShareTokenSalt', 'ContentReviewType'
    ];

    /**
     * General relationships that we never want to index.
     *
     * @config
     */
    private static $relationships_blacklisted = [
        'FileTracking', 'LinkTracking', 'Parent', 'UnPublishJob', 'PublishJob',
        'BackLinks', 'ContentReviewUsers', 'ContentReviewGroups', 'RelatedPagesThrough',
        'VirtualPages', 'ReviewLogs',
    ];

    /**
     * Add the provided item to the Algolia index.
     *
     * Callee should check whether this object should be indexed at all. Calls
     * {@link exportAttributesFromObject()} to determine what data should be
     * indexed
     *
     * @param DataObject $item
     *
     * @return $this
     */
    public function indexItem($item)
    {
        $searchIndex = $this->initIndex();
        $fields = $this->exportAttributesFromObject($item);

        $searchIndex->saveObject($fields->toArray());

        return $this;
    }

    /**
     * Index multiple items at a time.
     *
     * @param DataObject[] $items
     *
     * @return $this
     */
    public function indexItems($items)
    {
        $searchIndex = $this->initIndex();
        $data = [];

        foreach ($items as $item) {
            $data[] = $this->exportAttributesFromObject($item)->toArray();
        }

        $searchIndex->saveObjects($data);

        return $this;
    }

    /**
     * Generates a map of all the fields and values which will be sent.
     *
     * @param DataObject
     *
     * @return SilverStripe\ORM\Map
     */
    public function exportAttributesFromObject($item)
    {
        $toIndex = [
            'objectID' => $this->generateUniqueID($item),
            'objectSilverstripeUUID' => $item->ID,
            'objectTitle' => (string) $item->Title,
            'objectClassName' => get_class($item),
            'objectClassNameHierachy' => array_values(ClassInfo::ancestry(get_class($item))),
            'objectLastEdited' => $item->dbObject('LastEdited')->Rfc822(),
            'objectLink' => str_replace(['?stage=Stage', '?stage=Live'], '', $item->AbsoluteLink())
        ];

        if ($this->config()->get('include_page_content')) {
            $toIndex['objectForTemplate'] =
                Injector::inst()->create(AlgoliaPageCrawler::class, $item)->getMainContent();
        }

        $specs = DataObject::getSchema()->fieldSpecs(get_class($item));
        $fields = new Map(ArrayList::create());

        foreach ($toIndex as $k => $v) {
            $fields->push($k, $v);
        }

        $checkItemShouldInclude = $item->hasMethod('shouldIncludeAttributeInAlgolia');

        foreach ($specs as $k => $v) {
            if (in_array($k, $this->config()->get('attributes_blacklisted'))) {
                continue;
            }

            if ($checkItemShouldInclude && ($item->shouldIncludeAttributeInAlgolia($k) === false)) {
                continue;
            }

            try {
                $obj = $item->dbObject($k);

                if ($obj) {
                    $fields->push($k, $obj->forTemplate());
                }
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
            }
        }

        if ($manyMany = $item->manyMany()) {
            foreach ($manyMany as $relationship => $class) {
                $this->exportAttributesFromRelationship($item, $relationship, $fields);
            }
        }

        if ($hasMany = $item->hasMany()) {
            foreach ($hasMany as $relationship => $class) {
                $this->exportAttributesFromRelationship($item, $relationship, $fields);
            }
        }

        if ($hasOne = $item->hasOne()) {
            foreach ($hasOne as $relationship => $class) {
                $this->exportAttributesFromRelationship($item, $relationship, $fields);
            }
        }


        $item->invokeWithExtensions('updateAlgoliaAttributes', $fields);

        return $fields;
    }

    /**
     * Retrieve all the attributes from the related object that we want to add
     * to this record.
     *
     * @param DataObject $item
     * @param string $relationship
     * @param \SilverStripe\ORM\Map $attributes
     */
    public function exportAttributesFromRelationship($item, $relationship, $attributes)
    {
        if (in_array($relationship, $this->config()->get('relationships_blacklisted'))) {
            return;
        }

        if ($item->hasMethod('shouldIncludeRelationshipInAlgolia') && $item->shouldIncludeRelationshipInAlgolia($relationship) === false) {
            return;
        }

        try {
            $data = [];

            $related = $item->{$relationship}();

            if (!$related || !$related->exists()) {
                return;
            }

            if (is_iterable($related)) {
                foreach ($related as $relatedObj) {
                    $relationshipAttributes = new Map(ArrayList::create());
                    $relationshipAttributes->push('ID', $relatedObj->ID);
                    $relationshipAttributes->push('Title', $relatedObj->Title);

                    if ($item->hasMethod('updateAlgoliaRelationshipAttributes')) {
                        $item->updateAlgoliaRelationshipAttributes($relationshipAttributes, $relatedObj);
                    }

                    $data[] = $relationshipAttributes->toArray();
                }
            } else {
                $relationshipAttributes = new Map(ArrayList::create());
                $relationshipAttributes->push('ID', $related->ID);
                $relationshipAttributes->push('Title', $related->Title);

                if ($item->hasMethod('updateAlgoliaRelationshipAttributes')) {
                    $item->updateAlgoliaRelationshipAttributes($relationshipAttributes, $related);
                }

                $data = $relationshipAttributes->toArray();
            }

            $attributes->push('relation'. $relationship, $data);
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);
        }
    }

    /**
     * Remove an item ID from the index. As this would usually be when an object
     * is deleted in Silverstripe we cannot rely on the object existing.
     *
     * @param string $itemClass
     * @param int $itemId
     */
    public function deleteObject($itemClass, $itemId)
    {
        $searchIndex = $this->initIndex();
        $key =  strtolower($itemClass . '.'. $itemId);

        $searchIndex->deleteObject($key);
    }

    /**
     * Generates a unique ID for this item. If using a single index with
     * different dataobjects such as products and pages they potentially would
     * have the same ID. Uses the classname and the ID.
     *
     * @param DataObject $item
     *
     * @return string
     */
    public function generateUniqueID($item)
    {
        return strtolower(get_class($item) . '.'. $item->ID);
    }
}
