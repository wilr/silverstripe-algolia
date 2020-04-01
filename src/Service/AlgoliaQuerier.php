<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Exception;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\PaginatedList;

/**
 * Wraps the Algolia SDK to return Silverstripe ORM records
 *
 *
 */
class AlgoliaQuerier extends AlgoliaService
{
    /**
     * @param string $query
     * @param array $searchParameters
     *
     * @return PaginatedList
     */
    public function fetchResults($query, $searchParameters = [])
    {
        $index = $this->initIndex();
        $results = $index->search($query, $searchParameters);

        $records = ArrayList::create();

        if ($results && isset($results['hits'])) {
            foreach ($results['hits'] as $hit) {
                $className = isset($hit['objectClassName']) ? $hit['objectClassName'] : null;
                $id = isset($hit['objectSilverstripeUUID']) ? $hit['objectSilverstripeUUID'] : null;

                if (!$id || !$className) {
                    // ignore.
                    return;
                }

                try {
                    $record = $className::get()->byId($id);

                    if ($record && $record->canView()) {
                        $records->push($record);
                    } else {
                        // record no longer exists so trigger a delete for this
                        // old record
                        $this->cleanUpOldResult($className, $id);
                    }
                } catch (Exception $e) {
                    //
                }
            }
        }

        $output = PaginatedList::create($records)
            ->setCurrentPage($results['page'] + 1)
            ->setTotalItems($results['nbHits'])
            ->setLimitItems(false)
            ->setPageStart($results['page'] * $results['hitsPerPage'])
            ->setPageLength($results['hitsPerPage']);

        return $output;
    }

    public function cleanUpOldResult($className, $objectID)
    {
        // @todo
    }
}
