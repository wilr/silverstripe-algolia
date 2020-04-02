<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
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
        'ID', 'Title', 'ClassName', 'LastEdited'
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
        $searchIndexes = $this->getService()->initIndexes($item);
        $fields = $this->exportAttributesFromObject($item);

        foreach ($searchIndexes as $searchIndex) {
            $searchIndex->saveObject($fields->toArray());
        }

        return $this;
    }

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

        $attributes = new Map(ArrayList::create());

        foreach ($toIndex as $k => $v) {
            $attributes->push($k, $v);
        }

        $specs = $item->config()->get('algolia_index_fields');

        if ($specs) {
            foreach ($specs as $attributeName) {
                if (in_array($attributeName, $this->config()->get('attributes_blacklisted'))) {
                    continue;
                }

                $dbField = $item->relObject($attributeName);

                if ($dbField && $dbField->exists()) {
                    if ($dbField instanceof RelationList || $dbField instanceof DataObject) {
                        // has-many, many-many, has-one
                        $this->exportAttributesFromRelationship($item, $attributeName, $attributes);
                    } else {
                        // db-field
                        $attributes->push($attributeName, $dbField->forTemplate());
                    }
                }
            }
        }

        $item->invokeWithExtensions('updateAlgoliaAttributes', $attributes);

        return $attributes;
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
    public function deleteItem($itemClass, $itemId)
    {
        $searchIndexes = $this->getService()->initIndexes($itemClass);
        $key =  strtolower($itemClass . '.'. $itemId);

        foreach ($searchIndexes as $searchIndex) {
            $searchIndex->deleteObject($key);
        }
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

    /**
     * @param DataObject $item
     *
     * @return array
     */
    public function getObject($item)
    {
        $id = $this->generateUniqueID($item);

        $indexes = $this->getService()->initIndexes($item);

        foreach ($indexes as $index) {
            $output = $index->getObject($id);

            if ($output) {
                return $output;
            }
        }
    }

    /**
     * Sync setting from YAML configuration into Algolia.
     *
     * This runs automatically on dev/build operations.
     */
    public function syncSettings()
    {
        $config = $this->config('index_class_mapping');

        foreach ($config as $index => $data) {
            $indexName = $this->getService()->environmentizeIndex($index);

            if (isset($data['indexSettings'])) {
                $index = $this->getService()->getClient()->initIndex($indexName);

                if ($index) {
                    try {
                        $index->setSettings($data);
                    } catch (Exception $e) {
                        Injector::inst()->create(LoggerInterface::class)->error($e);
                    }
                }
            }
        }
    }
}
