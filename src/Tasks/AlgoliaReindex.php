<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataObject;
use SilverStripe\Model\List\Map;
use SilverStripe\Versioned\Versioned;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

/**
 * Bulk reindex all objects. Note that this should be run via cli, if you can,
 * use the queuedjobs version `AlgoliaReindexAllJob`
 */
class AlgoliaReindex extends BuildTask
{
    protected static string $commandName = 'algolia:index';

    protected string $title = 'Algolia Reindex';

    protected static string $description = 'Reindex objects to Algolia';

    private static $batch_size = 20;

    /**
     * An optional array of default filters to apply when doing the reindex
     * i.e for indexing Page subclasses you may wish to exclude expired pages.
     *
     * @config
     */
    private static $reindexing_default_filters = [];

    protected $errors = [];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
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

        /** @var AlgoliaService */
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
                foreach ($classes as $candidate) {
                    if ($targetClass && $targetClass !== $candidate) {
                        // check to see if target class is a subclass of the candidate
                        if (!is_subclass_of($targetClass, $candidate)) {
                            continue;
                        } else {
                            $candidate = $targetClass;
                        }
                    }


                    $items = $this->getItems($candidate, $filter, $indexFilters);

                    if (!$subsite) {
                        $items = $items->setDataQueryParam('Subsite.filter', false);
                    }

                    $filterLabel = implode(',', array_filter(array_merge([$filter], [$indexFilters[$candidate] ?? ''])));

                    $output->writeln(sprintf(
                        '| Found %s %s remaining to index %s',
                        $items->count(),
                        $candidate,
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
        ];
    }

    /**
     * @param string $targetClass
     * @param string $filter
     * @param string[] $indexFilters
     *
     * @return \SilverStripe\ORM\DataList
     */
    public function getItems($targetClass, $filter = '', $indexFilters = [])
    {
        $inst = $targetClass::create();

        if ($inst->hasExtension(Versioned::class)) {
            $items = Versioned::get_by_stage($targetClass, 'Live', $filter);
        } else {
            $items = $inst::get();

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
     * @param DataObject $obj
     *
     * @return bool
     */
    public function indexItem($obj = null): bool
    {
        if (!$obj) {
            return false;
        } elseif (min($obj->invokeWithExtensions('canIndexInAlgolia')) === false) {
            return false;
        } else {
            if (!$obj->AlgoliaUUID) {
                $obj->assignAlgoliaUUID();
            }

            if ($obj->doImmediateIndexInAlgolia()) {
                return true;
            } else {
                return false;
            }
        }
    }


    /**
     * @param string $indexName
     * @param DataList? $items
     * @param PolyOutput $output;
     *
     * @return bool|string
     */
    public function indexItems($indexName, $items, PolyOutput $output)
    {
        $algoliaService = Injector::inst()->get(AlgoliaService::class);
        $count = 0;
        $skipped = 0;
        $total = ($items) ? $items->count() : 0;
        $batchSize = $this->config()->get('batch_size') ?? 25;
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

                if ($output) {
                    if ($pos % 50 == 0) {
                        $output->writeln(sprintf('[%s/%s]', $pos, $total));
                    } else {
                        $output->write('.');
                    }
                }

                // fetch the actual instance
                $instance = DataObject::get_by_id($item->ClassName, $item->ID);

                if (!$instance || min($instance->invokeWithExtensions('canIndexInAlgolia')) == false) {
                    $skipped++;

                    continue;
                }

                // Set AlgoliaUUID, in case it wasn't previously set
                if (!$item->AlgoliaUUID) {
                    $item->assignAlgoliaUUID();
                }

                $batchKey = get_class($item);

                if (!isset($currentBatches[$batchKey])) {
                    $currentBatches[$batchKey] = [];
                }

                try {
                    $data = $indexer->exportAttributesFromObject($item);

                    if ($data instanceof Map) {
                        $data = $data->toArray();
                    }

                    $currentBatches[$batchKey][] = $data;
                    $item->touchAlgoliaIndexedDate();
                    $count++;
                } catch (Throwable $e) {
                    Injector::inst()->get(LoggerInterface::class)->warning($e->getMessage());
                }

                if (count($currentBatches[$batchKey]) >= $batchSize) {
                    $this->indexBatch($indexName, $currentBatches[$batchKey]);

                    unset($currentBatches[$batchKey]);
                }

                if ($output) {
                    sleep(1);
                }
            }
        }

        foreach ($currentBatches as $class => $records) {
            if (count($currentBatches[$class]) > 0) {
                $this->indexBatch($indexName, $currentBatches[$class]);

                if ($output) {
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

        if ($output) {
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
     * Index a batch of changes
     *
     * @param array $items
     *
     * @return bool
     */
    public function indexBatch($indexName, $items): bool
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
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return $this
     */
    public function clearErrors()
    {
        $this->errors = [];

        return $this;
    }
}
