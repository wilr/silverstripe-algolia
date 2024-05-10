<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Exception;
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
     *
     * @return PaginatedList
     */
    public function fetchResults($selectedIndex = null, $query = '', $searchParameters = [])
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
            $index = $service->getClient()->initIndex($selectedIndex);
            $results = $index->search($query, $searchParameters);
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);
        }

        $records = ArrayList::create();

        if ($results && isset($results['hits'])) {
            foreach ($results['hits'] as $hit) {
                $className = isset($hit['objectClassName']) ? $hit['objectClassName'] : null;
                $id = isset($hit['objectSilverstripeID']) ? $hit['objectSilverstripeID'] : null;

                if (!$id || !$className) {
                    continue;
                }

                try {
                    $record = $className::get()->byId($id);

                    if ($record && $record->canView()) {
                        // Snippet results are cofigured in Algolia to be returned.
                        // Since the following result is actually fetched from the DB
                        // I need to concatenate the aogolias snippeted result to the $hit to be returned
                        if(isset($hit["_snippetResult"]) && isset($hit["_snippetResult"]['Content'])) {
                            $record->snippetResult = $hit["_snippetResult"]['Content']["value"];
                        }
                        if(isset($hit["_snippetResult"]) && isset($hit["_snippetResult"]['objectContent'])) {
                            $record->snippetResult = $hit["_snippetResult"]['objectContent']["value"];
                        }

                        if(isset($hit["objectLink"])) {
                            $record->AlgoliaLink = $hit["objectLink"];
                        }

                        $records->push($record);
                    }
                } catch (Throwable $e) {
                    Injector::inst()->get(LoggerInterface::class)->notice($e);
                }
            }
        }

        //Custom sorting results so Guide pages are always first.
        //This will unset all results that aren't guide pages
        //Then instantly re add to array which places them at the bottom.
        foreach($records as $key => $wanted) {
            if($wanted->ClassName != 'IntranetGuidePage') {
                unset($records[$key]);
            }
            $records[$key] = $wanted;
        }

        $this->lastResult = $results;

        $output = PaginatedList::create($records);

        if ($results) {
            $output = $output->setCurrentPage($results['page'] + 1)
                ->setTotalItems($results['nbHits'])
                ->setLimitItems(false)
                ->setPageStart($results['page'] * $results['hitsPerPage'])
                ->setPageLength($results['hitsPerPage']);
        }

        // add raw output from algoia for manipulation
        $output->raw = $results;

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
