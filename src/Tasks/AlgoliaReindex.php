<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\Concerns\UsesAlgoliaQuietOption;

/**
 * Bulk reindex all objects. Note that this should be run via cli, if you can,
 * use the queuedjobs version `AlgoliaReindexAllJob`
 */
class AlgoliaReindex extends BuildTask
{
    use UsesAlgoliaQuietOption;

    protected static string $commandName = 'algolia-index';

    protected string $title = 'Algolia Reindex';

    protected static string $description = 'Reindex objects to Algolia';

    private static int $batch_size = 20;

    /**
     * An optional array of default filters to apply when doing the reindex
     * i.e for indexing Page subclasses you may wish to exclude expired pages.
     *
     * @config
     *
     * @var array<string, mixed>
     */
    private static array $reindexing_default_filters = [];


    /**
     * @var array<int, string>
     */
    protected array $errors = [];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->applyQuietFromInput($input, $output);

        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo();

        $targetClass = '';
        $filter = '';
        $subsite = null;
        $defaultFilters = $this->config()->get('reindexing_default_filters');

        if ($input->getOption('only')) {
            $targetClass = $input->getOption('only');

            if ($defaultFilters && isset($defaultFilters[$targetClass])) {
                $filter = $defaultFilters[$targetClass];
            }
        }

        if ($input->getOption('filter')) {
            $filter = $input->getOption('filter');
        }

        if (!$input->getOption('force') && !$filter) {
            $filter = 'AlgoliaIndexed IS NULL';
        }

        if ($input->getOption('subsite')) {
            $subsite = $input->getOption('subsite');
        }

        $algoliaService = Injector::inst()->create(AlgoliaService::class);
        if ($input->getOption('clear')) {
            $indexes = $algoliaService->initIndexes();

            foreach ($indexes as $indexName => $index) {
                $index->clearObjects();
            }
        }

        // find all classes we have to index and do so
        foreach ($algoliaService->indexes as $indexName => $index) {
            $environmentizedIndexName = $algoliaService->environmentizeIndex($indexName);

            $output->writeln('Updating index ' . $environmentizedIndexName);

            $classes = (isset($index['includeClasses'])) ? $index['includeClasses'] : null;
            $indexFilters = (isset($index['includeFilter'])) ? $index['includeFilter'] : [];

            if ($classes) {
                foreach ($classes as $configuredCandidate) {
                    if (!$this->looksLikeConfiguredDataObjectSubclass($configuredCandidate)) {
                        continue;
                    }


                    $effectiveCandidate = $configuredCandidate;

                    if ($targetClass !== '' && $targetClass !== $configuredCandidate) {
                        // check to see if target class is a subclass of the candidate
                        if (!is_subclass_of($targetClass, $configuredCandidate)) {
                            continue;
                        }

                        if (!is_string($targetClass)
                            || !class_exists($targetClass)
                            || !is_subclass_of($targetClass, DataObject::class)
                        ) {
                            continue;
                        }

                        $effectiveCandidate = $targetClass;
                    }


                    $items = $this->getItems($effectiveCandidate, $filter, $indexFilters);

                    if (!$subsite) {
                        $items = $items->setDataQueryParam('Subsite.filter', false);
                    }

                    $indexFilterSnippet = ($indexFilters[$effectiveCandidate] ?? '');
                    $filterLabel = implode(',', array_filter(
                        array_merge([$filter], [$indexFilterSnippet])
                    ));

                    $output->writeln(sprintf(
                        '| Found %s %s remaining to index %s',
                        $items->count(),
                        $effectiveCandidate,
                        $filterLabel ? 'which match filters ' .  $filterLabel : ''
                    ));

                    if ($items->exists()) {
                        $this->indexItems($indexName, $items, $output);
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('only', null, InputOption::VALUE_OPTIONAL, 'Only index objects of this class'),
            new InputOption('filter', null, InputOption::VALUE_OPTIONAL, 'Filter to apply when fetching objects'),
            new InputOption('force', null, InputOption::VALUE_NONE, 'Force indexing of all objects'),
            new InputOption('subsite', null, InputOption::VALUE_OPTIONAL, 'Only index objects from this subsite'),
            new InputOption('clear', null, InputOption::VALUE_NONE, 'Clear all indexes before reindexing'),
            $this->algoliaQuietInputOption(),
        ];
    }

    /**
     * @param class-string<DataObject> $targetClass
     * @param string $filter
     * @param array<string, mixed> $indexFilters
     *
     * @return DataList<DataObject>
     */
    public function getItems(string $targetClass, string $filter = '', array $indexFilters = []): DataList
    {
        if (!class_exists($targetClass) || !is_subclass_of($targetClass, DataObject::class)) {
            throw new \InvalidArgumentException(sprintf('%s must be a valid DataObject subclass', $targetClass));
        }

        $inst = $targetClass::create();

        if ($inst->hasExtension(Versioned::class)) {
            $items = Versioned::get_by_stage($targetClass, Versioned::LIVE, $filter);
        } else {
            $items = $targetClass::get();

            if ($filter) {
                $items = $items->where($filter);
            }
        }

        if (isset($indexFilters[$targetClass])) {
            $items = $items->where($indexFilters[$targetClass]);
        }


        return $items;
    }


    /**
     * @return bool
     */
    public function indexItem(?DataObject $obj = null): bool
    {
        if (!$obj) {
            return false;
        }

        if (AlgoliaObjectExtension::shouldBlockIndexingForAlgolia($obj)) {
            return false;
        }

        if (!$obj->AlgoliaUUID) {
            AlgoliaObjectExtension::runAssignAlgoliaUuid($obj);
        }

        return AlgoliaObjectExtension::runDoImmediateIndexInAlgolia($obj);
    }


    /**
     * @param DataList<DataObject>|null $items
     *
     * @return bool|string Summary text, or false when there is nothing to index
     */
    public function indexItems(string $indexName, ?DataList $items = null, ?PolyOutput $output = null): bool|string
    {
        $algoliaService = Injector::inst()->get(AlgoliaService::class);
        $count = 0;
        $skipped = 0;
        $total = ($items instanceof DataList) ? $items->count() : 0;
        $batchSize = max(1, (int) $this->config()->get('batch_size'));
        $batchesTotal = ($total > 0) ? (ceil($total / $batchSize)) : 0;
        $indexer = Injector::inst()->get(AlgoliaIndexer::class);
        $pos = 0;

        if ($total < 1) {
            return false;
        }

        $currentBatches = [];

        for ($i = 0; $i < $batchesTotal; $i++) {
            $limitedSize = $items->sort('ID', 'DESC')->limit($batchSize, $i * $batchSize);

            foreach ($limitedSize as $item) {
                $pos++;

                if ($output && !$output->isQuiet() && !$output->isSilent()) {
                    if ($pos % 50 == 0) {
                        $output->writeln(sprintf('[%s/%s]', $pos, $total));
                    } else {
                        $output->write('.');
                    }
                }

                // fetch the actual instance
                $instance = DataObject::get($item->ClassName)->byID($item->ID);

                if (!$instance || AlgoliaObjectExtension::shouldBlockIndexingForAlgolia($instance)) {
                    $skipped++;

                    continue;
                }

                // Set AlgoliaUUID, in case it wasn't previously set
                if (!$item->AlgoliaUUID) {
                    AlgoliaObjectExtension::runAssignAlgoliaUuid($item);
                }

                $batchKey = $item::class;

                if (!isset($currentBatches[$batchKey])) {
                    $currentBatches[$batchKey] = [];
                }

                try {
                    $data = $indexer->exportAttributesFromObject($item)->toArray();

                    $currentBatches[$batchKey][] = $data;
                    AlgoliaObjectExtension::runTouchAlgoliaIndexedDate($item);
                    $count++;
                } catch (Throwable $e) {
                    Injector::inst()->get(LoggerInterface::class)->warning($e->getMessage());
                }

                if (count($currentBatches[$batchKey]) >= $batchSize) {
                    $this->indexBatch($indexName, $currentBatches[$batchKey]);

                    unset($currentBatches[$batchKey]);
                }

                if ($output && !$output->isQuiet() && !$output->isSilent()) {
                    sleep(1);
                }
            }
        }

        foreach ($currentBatches as $class => $records) {
            if (count($currentBatches[$class]) > 0) {
                $this->indexBatch($indexName, $currentBatches[$class]);

                if ($output && !$output->isQuiet() && !$output->isSilent()) {
                    sleep(1);
                }
            }
        }

        $summary = sprintf(
            "%sNumber of objects indexed in %s: %s, Skipped %s",
            PHP_EOL,
            $indexName,
            $count,
            $skipped
        );

        if ($output && !$output->isQuiet() && !$output->isSilent()) {
            $output->writeln($summary);

            $output->writeln(sprintf(
                "See index at <a href='https://www.algolia.com/apps/%s/explorer/indices' target='_blank'>" .
                    "algolia.com/apps/%s/explorer/indices</a>",
                $algoliaService->applicationId,
                $algoliaService->applicationId
            ));
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function indexBatch(string $indexName, array $items): bool
    {
        $service = Injector::inst()->create(AlgoliaService::class);
        $index = $service->getIndexByName($indexName);

        try {
            $result = $index->saveObjects($items, [
                'autoGenerateObjectIDIfNotExist' => true
            ]);

            if (!$result->valid()) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);

            if (Director::isDev()) {
                Debug::message($e->getMessage());
            }

            $this->errors[] = $e->getMessage();

            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return array_values($this->errors);
    }

    /**
     * @param mixed $configuredCandidate Candidate class name from Algolia YAML entry.
     */
    private function looksLikeConfiguredDataObjectSubclass(mixed $configuredCandidate): bool
    {
        return is_string($configuredCandidate)
            && class_exists($configuredCandidate)
            && is_subclass_of($configuredCandidate, DataObject::class);
    }

    public function clearErrors(): static
    {
        $this->errors = [];

        return $this;
    }
}
