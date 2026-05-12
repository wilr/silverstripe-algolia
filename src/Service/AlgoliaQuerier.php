<?php

namespace Wilr\SilverStripe\Algolia\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\ORM\DataObject;
use Throwable;

/**
 * Wraps the Algolia SDK to return Silverstripe ORM records
 */
class AlgoliaQuerier
{
    /**
     * Raw Algolia SDK response payload from the previous query, when available.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $lastResult = null;

    /**
     * @param array<string, mixed> $searchParameters
     * @param array<string, mixed> $ORMFilters Filters applied to ORM results before assembling the PaginatedList.
     *
     * @return PaginatedList<ArrayList<DataObject>|ArrayList<object>, DataObject>
     */
    public function fetchResults(
        ?string $selectedIndex = null,
        string $query = '',
        array $searchParameters = [],
        array $ORMFilters = [],
    ): PaginatedList {
        $service = Injector::inst()->get(AlgoliaService::class);
        $results = false;

        if (!$selectedIndex) {
            $picked = array_key_first($service->indexes);
            if ($picked === null) {
                return PaginatedList::create(ArrayList::create());
            }
            $selectedIndex = (string) $picked;
        }

        try {
            $selectedIndexEnvironment = $service->environmentizeIndex($selectedIndex);
            $index = $service->getSearchClient()->initIndex($selectedIndexEnvironment);
            $results = $index->search($query, $searchParameters);
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);
        }

        $records = ArrayList::create();
        $totalItems = 0;

        if ($results && isset($results['hits'])) {
            $totalItems = isset($results['nbHits']) ? $results['nbHits'] : 0;

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

        $this->lastResult = is_array($results) ? $results : null;

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
     * @return array<string, mixed>|null
     */
    public function getLastResult(): ?array
    {
        return $this->lastResult;
    }
}
