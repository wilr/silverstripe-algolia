<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\PaginatedList;
use Throwable;

/**
 * Wraps the Algolia SDK to return Silverstripe ORM records
 */
class AlgoliaQuerier
{
    /**
     * @var array|null $lastResult
     */
    protected $lastResult = null;

    /**
     * @param string $selectedIndex
     * @param string $query
     * @param array  $searchParameters
     * @param array $ORMFilters This argument is used to filter ORM objects prior to returning the results as a PaginatedList
     *
     * @return PaginatedList
     */
    public function fetchResults($selectedIndex = null, $query = '', $searchParameters = [], $ORMFilters = [])
    {
        $service = Injector::inst()->get(AlgoliaService::class);
        $results = false;

        if (!$selectedIndex) {
            if (!function_exists('array_key_first')) {
                function array_key_first(array $arr)
                {
                    foreach ($arr as $key => $unused) {
                        return $key;
                    }
                    return null;
                }
            }

            $selectedIndex = array_key_first($service->indexes);
        }

        try {
            $selectedIndex = $service->environmentizeIndex($selectedIndex);
            $index = $service->getSearchClient()->initIndex($selectedIndex);
            $results = $index->search($query, $searchParameters);
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);
        }

        $records = ArrayList::create();
        $totalItems = $results['nbHits'] ?? 0;

        if ($results && isset($results['hits'])) {
            foreach ($results['hits'] as $hit) {
                $className = isset($hit['objectClassName']) ? $hit['objectClassName'] : null;
                $id = isset($hit['objectSilverstripeID']) ? $hit['objectSilverstripeID'] : null;

                if (!$id || !$className) {
                    $totalItems--;
                    continue;
                }

                try {
                    $record = $className::get()->byId($id);

                    if ($record && $record->canView()) {
                        $records->push($record);
                    } else {
                        $totalItems--;
                    }
                } catch (Throwable $e) {
                    Injector::inst()->get(LoggerInterface::class)->notice($e);
                }
            }
        }

        $this->lastResult = $results;

        if (!empty($ORMFilters)) {
            $records = $records->filter($ORMFilters);
        }

        $output = PaginatedList::create($records);

        if ($results) {
            $output = $output->setCurrentPage($results['page'] + 1)
                ->setTotalItems($totalItems)
                ->setLimitItems(false)
                ->setPageStart($results['page'] * $results['hitsPerPage'])
                ->setPageLength($results['hitsPerPage']);
        }

        return $output;
    }

    /**
     * @return array|null
     */
    public function getLastResult()
    {
        return $this->lastResult;
    }
}
