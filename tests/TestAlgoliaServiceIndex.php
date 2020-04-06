<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\TestOnly;

class TestAlgoliaServiceIndex implements TestOnly
{
    public function setSettings($settings)
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
}
