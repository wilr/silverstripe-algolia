<?php

namespace Wilr\Silverstripe\Algolia\Jobs;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use stdClass;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Throwable;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaReindex;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

/**
 * Reindex everything via a queued job (when AlgoliaReindex task won't do). This
 * supports reindexing via batch operations. Algolia limits apply.
 */
class AlgoliaReindexAllJob extends AbstractQueuedJob implements QueuedJob
{
    use Configurable;

    /**
     * @config
     *
     * @var array<string, mixed>
     */
    private static array $reindexing_default_filters = [];

    /**
     * @config
     */
    private static bool $use_batching = true;

    public function getTitle(): string
    {
        return 'Algolia re-indexing all records';
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function setup(): void
    {
        parent::setup();

        $algoliaService = Injector::inst()->get(AlgoliaService::class);
        $task = AlgoliaReindex::create();

        $this->totalSteps = 0;
        $this->currentStep = 0;

        $indexData = [];

        $filters = $this->config()->get('reindexing_default_filters');
        $batchCfg = $task->config()->get('batch_size');
        $batchSize = max(1, (int) $batchCfg);
        $batching = $this->config()->get('use_batching');

        // find all classes we have to index and add them to the indexData map
        // in groups of batch size, this setup operation does the heavy lifting
        // and process simply handles one batch at a time.
        foreach ($algoliaService->indexes as $indexName => $index) {
            $classes = (isset($index['includeClasses'])) ? $index['includeClasses'] : null;
            $indexFilters = (isset($index['includeFilters'])) ? $index['includeFilters'] : [];

            if ($classes) {
                foreach ($classes as $class) {
                    $filter = (isset($filters[$class])) ? $filters[$class] : '';
                    $ids = $task->getItems($class, $filter, $indexFilters)->column('ID');
                    if (count($ids)) {
                        if ($batching && $batchSize > 1) {
                            foreach (array_chunk($ids, $batchSize) as $chunk) {
                                $indexData[] = [
                                    'indexName' => $indexName,
                                    'class' => $class,
                                    'ids' => $chunk,
                                ];
                            }
                        } else {
                            foreach ($ids as $id) {
                                $indexData[] = [
                                    'indexName' => $indexName,
                                    'class' => $class,
                                    'id' => $id,
                                ];
                            }
                        }
                        $filterShown = ($filter ?: '(none)');
                        $this->addMessage(sprintf(
                            '[%s] Indexing %d %s instances with filters: %s',
                            $indexName,
                            count($ids),
                            $class,
                            $filterShown
                        ));
                    } else {
                        $emptyFilterShown = ($filter ?: '(none) - skipping.');
                        $this->addMessage(sprintf(
                            '[%s] 0 %s instances to index with filters: %s',
                            $indexName,
                            $class,
                            $emptyFilterShown
                        ));
                    }
                }
            }
        }
        $this->totalSteps += count($indexData);
        // Store in jobData to get written to the job descriptor in DB
        $this->jobData->IndexData = $indexData;
    }

    /**
     * Index data is an array of steps to process, each step either looks like this with batching:
     * [
     *   'indexName' => string,
     *   'class' => string,
     *   'ids' => array of int,
     * ]
     * or this without batching:
     * [
     *   'indexName' => string,
     *   'class' => string,
     *   'id' => int,
     * ]
     * We process one step / batch / id per call.
     */
    public function process(): void
    {
        if ($this->currentStep >= $this->totalSteps) {
            $this->isComplete = true;
            $this->addMessage('Done!');

            return;
        }

        $indexData = isset($this->jobData->IndexData) ? $this->jobData->IndexData : null;
        if ($indexData === null || !isset($indexData[$this->currentStep])) {
            $this->isComplete = true;
            $this->addMessage(
                'Somehow we ran out of job data before all steps were processed. So we will assume we are done!'
            );
            $this->addMessage(
                'Dumping out the jop data for debug purposes: ' . json_encode($indexData)
            );

            return;
        }

        $stepData = $indexData[$this->currentStep];
        $class = $stepData['class'];

        $errors = [];
        $task = null;

        try {
            $task = AlgoliaReindex::create();

            if (isset($stepData['ids'])) {
                $items = DataObject::get($class)->filter('ID', $stepData['ids']);
                $summary = $task->indexItems((string) $stepData['indexName'], $items, null);

                if (is_string($summary)) {
                    $this->addMessage($summary);
                }
            } else {
                $item = DataObject::get($class)->byID((int) $stepData['id']);

                if ($item) {
                    if (AlgoliaObjectExtension::shouldBlockIndexingForAlgolia($item)) {
                        $this->addMessage(sprintf('Skipped indexing %s %s', $class, (string) $item->ID));
                    } elseif ($task->indexItem($item)) {
                        $this->addMessage(sprintf('Successfully indexed %s %s', $class, (string) $item->ID));
                    } else {
                        $this->addMessage(sprintf('Error indexing %s %s', $class, (string) $item->ID));
                    }
                } else {
                    $this->addMessage(sprintf(
                        'Error indexing %s %s - failed to load item from DB',
                        $class,
                        (string) $stepData['id']
                    ));
                }
            }

            $errors = $task->getErrors();
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if ($errors !== []) {
            $this->addMessage(implode(', ', $errors));
            if ($task !== null) {
                $task->clearErrors();
            }
        }

        $this->currentStep++;
    }
}
