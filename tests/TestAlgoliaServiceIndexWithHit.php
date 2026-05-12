<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use SilverStripe\Dev\TestOnly;

/**
 * Mock index that returns a configurable Algolia hit for {@see AlgoliaQuerier::fetchResults} tests.
 */
class TestAlgoliaServiceIndexWithHit extends TestAlgoliaServiceIndex implements TestOnly
{
    public static int $objectSilverstripeId = 0;

    /**
     * @param array<string, mixed> $requestOptions
     * @return array<string, mixed>
     */
    public function search($query, $requestOptions = [])
    {
        $id = self::$objectSilverstripeId;

        return [
            'hits' => [
                [
                    'objectClassName' => AlgoliaTestObject::class,
                    'objectSilverstripeID' => $id,
                ],
            ],
            'page' => 0,
            'nbHits' => 1,
            'hitsPerPage' => 10,
        ];
    }
}
