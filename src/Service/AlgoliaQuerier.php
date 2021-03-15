<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\PaginatedList;

/**
 * Wraps the Algolia SDK to return Silverstripe ORM records
 */
class AlgoliaQuerier
{
    /**
     * @param string $selectedIndex
     * @param string $query
     * @param array  $searchParameters
     *
     * @return PaginatedList
     */
    public function fetchResults($selectedIndex = null, $query = '', $searchParameters = [])
    {
        $service = Injector::inst()->get(AlgoliaService::class);
        $results = false;
        
        if (!$selectedIndex) {
            if (!function_exists('array_key_first')) {
                function array_key_first(array $arr) {
                    foreach($arr as $key => $unused) {
                        return $key;
                    }
                    return NULL;
                }
            }

            $selectedIndex = array_key_first($service->indexes);
        }
        
        try {
            $selectedIndex = $service->environmentizeIndex($selectedIndex);
            $index = $service->getClient()->initIndex($selectedIndex);
            $results = $index->search($query, $searchParameters);
        } catch (Exception $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);
        }
        
        $records = ArrayList::create();

        if ($results && isset($results['hits'])) {
            foreach ($results['hits'] as $hit) {
                $className = isset($hit['objectClassName']) ? $hit['objectClassName'] : null;
                $id = isset($hit['objectSilverstripeID']) ? $hit['objectSilverstripeID'] : null;

                if (!$id || !$className) {
                    // ignore.
                    return;
                }

                try {
                    $record = $className::get()->byId($id);

                    if ($record && $record->canView()) {
                        $records->push($record);
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
}
