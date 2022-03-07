<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use Algolia\AlgoliaSearch\SearchIndex;
use SilverStripe\Dev\TestOnly;

class TestAlgoliaServiceIndex extends SearchIndex implements TestOnly
{
    private $objects = [];

    public function setSettings($settings, $requestOptions = array())
    {
        return $settings;
    }

    public function search($query, $requestOptions = array())
    {
        return [
            'hits' => [],
            'page' => 1,
            'nbHits' => 1,
            'hitsPerPage' => 10
        ];
    }

    public function deleteObject($objectId, $requestOptions = array())
    {
        if (isset($this->objects[$objectId])) {
            unset($this->objects[$objectId]);
        }
    }

    public function saveObject($object, $requestOptions = array())
    {
        $this->objects[$object['objectID']] = $object;

        return new TestAlgoliaServiceResponse();
    }
}
