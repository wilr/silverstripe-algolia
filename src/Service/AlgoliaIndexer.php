<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Model\List\Map;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBForeignKey;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\RelationList;
use Throwable;

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
    private static bool $include_page_content = true;

    /**
     * @config
     */
    private static array $attributes_blacklisted = [
        'ID',
        'Title',
        'ClassName',
        'LastEdited',
        'Created'
    ];

    /**
     * @config
     */
    private static int $max_field_size_bytes = 10000;

    /**
     * Add the provided item to the Algolia index.
     *
     * Callee should check whether this object should be indexed at all. Calls
     * {@link exportAttributesFromObject()} to determine what data should be
     * indexed
     *
     * @param DataObject $item
     *
     * @return boolean
     */
    public function indexItem(DataObject $item): bool
    {
        $searchIndexes = $this->getService()->initIndexes($item);

        try {
            $fields = $this->exportAttributesFromObject($item);
        } catch (Exception $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);

            return false;
        }

        $fields = $fields->toArray();

        if ($searchIndexes) {
            $output = true;
            foreach ($searchIndexes as $searchIndex) {
                $result = $searchIndex->saveObject($fields, [
                    'autoGenerateObjectIDIfNotExist' => true
                ]);

                if (!$result->valid()) {
                    $output = false;
                }
            }

            return $output;
        }

        return false;
    }


    public function getService(): AlgoliaService
    {
        return Injector::inst()->get(AlgoliaService::class);
    }

    /**
     * Index multiple items of the same class at a time.
     *
     * @param DataList<DataObject> $items
     */
    public function indexItems(DataList $items): self
    {
        $sample = $items->first();
        $searchIndexes = $this->getService()->initIndexes($sample);
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
     * Generates a map of all the fields and values which will be sent. Two ways
     * to modifty the attributes sent to algolia. Either define the properties
     * via the config API
     *
     * ```
     * private static $algolia_index_fields = [
     *  'MyCustomField'
     * ];
     * ```
     *
     * Or, use exportObjectToAlgolia to return an Map. You can chose to include
     * the default fields or not.
     *
     * ```
     * class MyObject extends DataObject
     * {
     *  public function exportObjectToAlgolia($data)
     *  {
     *      $data = array_merge($data, [
     *          'MyCustomField' => $this->MyCustomField()
     *      ]);
     *      $map = new Map(ArrayList::create());
     *      foreach ($data as $k => $v) {
     *          $map->push($k, $v);
     *      }
     *      return $map;
     *  }
     * }
     * ```
     *
     */
    public function exportAttributesFromObject(DataObject $item): Map
    {
        $toIndex = [
            'objectID' => $item->AlgoliaUUID,
            'objectSilverstripeID' => $item->ID,
            'objectIndexedTimestamp' => date('c'),
            'objectTitle' => (string) $item->Title,
            'objectClassName' => get_class($item),
            'objectClassNameHierarchy' => array_values(ClassInfo::ancestry($item)),
            'objectLastEdited' => (($lf = $item->dbObject('LastEdited')) instanceof DBDate) ? $lf->getTimestamp() : 0,
            'objectCreated' => (($cf = $item->dbObject('Created')) instanceof DBDate) ? $cf->getTimestamp() : 0
        ];

        $objectLinkPayload = '';

        if ($item->hasMethod('AbsoluteLink')) {
            $objectLinkPayload = trim((string) $item->AbsoluteLink());
        } elseif ($item->hasMethod('Link')) {
            $objectLinkPayload = trim((string) $item->Link());
        }

        if ($objectLinkPayload !== '') {
            $toIndex['objectLink'] = str_replace(['?stage=Stage', '?stage=Live'], '', $objectLinkPayload);
        }

        if ($item->hasMethod('exportObjectToAlgolia')) {
            return $item->exportObjectToAlgolia($toIndex);
        }

        if ($this->config()->get('include_page_content')) {
            $toIndex['objectForTemplate'] =
                Injector::inst()->create(AlgoliaPageCrawler::class, $item)->getMainContent();
        }

        $item->invokeWithExtensions('onBeforeAttributesFromObject');

        $attributes = new Map(ArrayList::create());

        foreach ($toIndex as $k => $v) {
            $attributes->push($k, $v);
        }

        $specsRaw = $item->config()->get('algolia_index_fields');

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
            $attributes = $this->addSpecsToAttributes($item, $attributes, $specStrings);
        }

        $item->invokeWithExtensions('updateAlgoliaAttributes', $attributes);

        return $attributes;
    }



    /**
     * @param array<int, string> $specs
     */
    public function addSpecsToAttributes(DataObject $item, Map $attributes, array $specs): Map
    {
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
            } elseif ($dbField instanceof DBForeignKey) {
                $attributes->push($attributeName, $dbField->Value);
            } elseif ($dbField->exists() || $dbField instanceof DBBoolean) {
                if ($dbField instanceof RelationList || $dbField instanceof DataObject) {
                    // has-many, many-many, has-one
                    $this->exportAttributesFromRelationship($item, $attributeName, $attributes);
                } else {
                    // db-field, if it's a date then use the timestamp since we need it
                    $hasContent = true;
                    $value = '';

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
                                        $attributes->push($attributeName . '_Block' . $i, $block);
                                    } else {
                                        $hasContent = false;
                                    }

                                    $i++;
                                }
                                $hasContent = false;
                            } else {
                                $value = $fieldData;
                            }
                            break;
                        default:
                            $value = (string) $dbField->forTemplate();
                            break;
                    }

                    if ($hasContent) {
                        $attributes->push($attributeName, $value);
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * Retrieve all the attributes from the related object that we want to add
     * to this record.
     */
    public function exportAttributesFromRelationship(DataObject $item, string $relationship, Map $attributes): void
    {
        try {
            $data = [];

            $related = $item->relObject($relationship);

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
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);
        }
    }

    /**
     * Remove an item ID from the index. As this would usually be when an object
     * is deleted in Silverstripe we cannot rely on the object existing.
     *
     * @param string $itemUUID
     */
    public function deleteItem(string $itemClass, string $itemUUID): bool
    {
        if (!$itemUUID) {
            return false;
        }

        $searchIndexes = $this->getService()->initIndexes($itemClass);

        foreach ($searchIndexes as $key => $searchIndex) {
            try {
                $searchIndex->deleteObject($itemUUID);
            } catch (Throwable $e) {
                // do nothing
            }
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
        return strtolower(str_replace('\\', '_', get_class($item)) . '_' . $item->ID);
    }

    /**
     * @return array<string, mixed>
     */
    public function getObject(DataObject $item): array
    {
        $indexes = $this->getService()->initIndexes($item);

        if (!$item->AlgoliaUUID) {
            return [];
        }

        foreach ($indexes as $index) {
            try {
                $output = $index->getObject($item->AlgoliaUUID);

                if ($output) {
                    return $output;
                }
            } catch (NotFoundException $ex) {
            }
        }

        return [];
    }
}
