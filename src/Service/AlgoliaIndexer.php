<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Map;
use SilverStripe\ORM\RelationList;
use stdClass;

/**
 * Handles all the index management and communication with Algolia. Note that
 * any checking of records should be performed by the caller of these methods as
 * no permission checking is done by this class
 */
class AlgoliaIndexer
{
    use Configurable;

    /**
     * Include rendered markup from the object's `Link` method in the index.
     *
     * @config
     */
    private static $include_page_content = true;

    /**
     * @config
     */
    private static $attributes_blacklisted = [
        'ID', 'Title', 'ClassName', 'LastEdited', 'Created'
    ];

    /**
     * @config
     */
    private static $max_field_size_bytes = 10000;

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
        $searchIndexes = $this->getService()->initIndexes($item);
        $fields = $this->exportAttributesFromObject($item);

        foreach ($searchIndexes as $searchIndex) {
            $searchIndex->saveObject($fields->toArray());
        }

        return $this;
    }

    /**
     * @return AlgoliaService
     */
    public function getService()
    {
        return Injector::inst()->get(AlgoliaService::class);
    }

    /**
     * Index multiple items of the same class at a time.
     *
     * @param DataObject[] $items
     *
     * @return $this
     */
    public function indexItems($items)
    {
        $searchIndexes = $this->getService()->initIndexes($items->first());
        $data = [];

        foreach ($items as $item) {
            $data[] = $this->exportAttributesFromObject($item)->toArray();
        }

        foreach ($searchIndexes as $searchIndex) {
            $searchIndex->saveObjects($data);
        }

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
            'objectID' => $item->AlgoliaUUID,
            'objectSilverstripeID' => $item->ID,
            'objectTitle' => (string) $item->Title,
            'objectClassName' => get_class($item),
            'objectClassNameHierarchy' => array_values(ClassInfo::ancestry(get_class($item))),
            'objectLastEdited' => $item->dbObject('LastEdited')->getTimestamp(),
            'objectCreated' => $item->dbObject('Created')->getTimestamp(),
            'objectLink' => str_replace(['?stage=Stage', '?stage=Live'], '', $item->AbsoluteLink())
        ];

        if ($this->config()->get('include_page_content')) {
            $toIndex['objectForTemplate'] =
                Injector::inst()->create(AlgoliaPageCrawler::class, $item)->getMainContent();
        }

        $item->invokeWithExtensions('onBeforeAttributesFromObject');

        $attributes = new Map(ArrayList::create());

        foreach ($toIndex as $k => $v) {
            $attributes->push($k, $v);
        }

        $specs = $item->config()->get('algolia_index_fields');

        if ($specs) {
            $maxFieldSize = $this->config()->get('max_field_size_bytes');

            foreach ($specs as $attributeName) {
                if (in_array($attributeName, $this->config()->get('attributes_blacklisted'))) {
                    continue;
                }

                // fetch the db object, or fallback to the getters but prefer
                // the db object
                try {
                    $dbField = $item->relObject($attributeName);
                } catch (LogicException $e) {
                    $dbField = $item->{$attributeName};
                }

                if (!$dbField) {
                    continue;
                }

                if (is_string($dbField) || is_array($dbField)) {
                    $attributes->push($attributeName, $dbField);
                } elseif ($dbField->exists() || $dbField instanceof DBBoolean) {
                    if ($dbField instanceof RelationList || $dbField instanceof DataObject) {
                        // has-many, many-many, has-one
                        $this->exportAttributesFromRelationship($item, $attributeName, $attributes);
                    } else {
                        // db-field, if it's a date then use the timestamp since we need it
                        $hasContent = true;

                        switch (get_class($dbField)) {
                            case DBDate::class:
                            case DBDatetime::class:
                                $value = $dbField->getTimestamp();
                                break;
                            case DBBoolean::class:
                                $value = $dbField->getValue();
                                break;
                            case DBHTMLText::class:
                                $fieldData = $dbField->Plain();
                                $fieldLength = mb_strlen($fieldData, '8bit');

                                if ($fieldLength > $maxFieldSize) {
                                    $maxIterations = 100;
                                    $i = 0;

                                    while ($hasContent && $i < $maxIterations) {
                                        $block = mb_strcut(
                                            $fieldData,
                                            $i * $maxFieldSize,
                                            $maxFieldSize - 1
                                        );

                                        if ($block) {
                                            $attributes->push($attributeName .'_Block'. $i, $block);
                                        } else {
                                            $hasContent = false;
                                        }

                                        $i++;
                                    }
                                } else {
                                    $value = $fieldData;
                                }
                                break;
                            default:
                                $value = @$dbField->forTemplate();
                        }

                        if ($hasContent) {
                            $attributes->push($attributeName, $value);
                        }
                    }
                }
            }
        }

        $item->invokeWithExtensions('updateAlgoliaAttributes', $attributes);

        return $attributes;
    }

    /**
     * Retrieve all the attributes from the related object that we want to add
     * to this record. As the related record may not have the
     *
     * @param DataObject            $item
     * @param string                $relationship
     * @param \SilverStripe\ORM\Map $attributes
     */
    public function exportAttributesFromRelationship($item, $relationship, $attributes)
    {
        try {
            $data = [];

            $related = $item->{$relationship}();

            if (!$related || !$related->exists()) {
                return;
            }

            if (is_iterable($related)) {
                foreach ($related as $relatedObj) {
                    $relationshipAttributes = new Map(ArrayList::create());
                    $relationshipAttributes->push('objectID', $relatedObj->ID);
                    $relationshipAttributes->push('objectTitle', $relatedObj->Title);

                    if ($item->hasMethod('updateAlgoliaRelationshipAttributes')) {
                        $item->updateAlgoliaRelationshipAttributes($relationshipAttributes, $relatedObj);
                    }

                    $data[] = $relationshipAttributes->toArray();
                }
            } else {
                $relationshipAttributes = new Map(ArrayList::create());
                $relationshipAttributes->push('objectID', $related->ID);
                $relationshipAttributes->push('objectTitle', $related->Title);

                if ($item->hasMethod('updateAlgoliaRelationshipAttributes')) {
                    $item->updateAlgoliaRelationshipAttributes($relationshipAttributes, $related);
                }

                $data = $relationshipAttributes->toArray();
            }

            $attributes->push($relationship, $data);
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);
        }
    }

    /**
     * Remove an item ID from the index. As this would usually be when an object
     * is deleted in Silverstripe we cannot rely on the object existing.
     *
     * @param string $itemClass
     * @param int $itemUUID
     */
    public function deleteItem($itemClass, $itemUUID)
    {
        $item = DataObject::get_one($itemClass, ['AlgoliaUUID' => $itemUUID]);

        if (!$item || !$item->isInDB()) {
            return false;
        }

        $searchIndexes = $this->getService()->initIndexes();

        foreach ($searchIndexes as $key => $searchIndex) {
            $searchIndex->deleteObject($item->AlgoliaUUID);
        }

        return true;
    }

    /**
     * Generates a unique ID for this item. If using a single index with
     * different dataobjects such as products and pages they potentially would
     * have the same ID. Uses the classname and the ID.
     *
     * @deprecated
     * @param      DataObject $item
     *
     * @return string
     */
    public function generateUniqueID($item)
    {
        return strtolower(str_replace('\\', '_', get_class($item)) . '_'. $item->ID);
    }

    /**
     * @param DataObject $item
     *
     * @return array
     */
    public function getObject($item)
    {
        $indexes = $this->getService()->initIndexes($item);

        foreach ($indexes as $index) {
            try {
                $output = $index->getObject($item);
                if ($output) {
                    return $output;
                }
            } catch (NotFoundException $ex) {
            }
        }
    }
}
