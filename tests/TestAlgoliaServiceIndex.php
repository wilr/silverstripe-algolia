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

    /**
     * @param array<int, array<string, mixed>> $objects
     */
    public function saveObjects($objects, $requestOptions = array())
    {
        foreach ($objects as $object) {
            $this->saveObject($object, $requestOptions);
        }

        return new TestAlgoliaServiceResponse();
    }

    public function clearObjects($requestOptions = array())
    {
        $this->objects = [];

        return new TestAlgoliaServiceResponse();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getObject($objectId, $requestOptions = array())
    {
        return $this->objects[$objectId] ?? null;
    }

    /**
     * @param array<string, mixed> $requestOptions
     * @return array<string, mixed>
     */
    public function getSettings($requestOptions = [])
    {
        return [];
    }
}
