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

/**
 * Reindex everything via a queued job (when AlgoliaReindex task won't do). This
 * supports reindexing via batch operations. Algolia limits apply.
 */
class AlgoliaReindexAllJob extends AbstractQueuedJob implements QueuedJob
{
    use Configurable;

    /**
     * An optional array of default filters to apply when doing the reindex
     * i.e for indexing Page subclasses you may wish to exclude expired pages.
     *
     * @config
     */
    private static $reindexing_default_filters = [];

    /**
     * @config
     */
    private static $use_batching = true;

    public function __construct($params = array())
    {
    }

    public function getTitle()
    {
        return 'Algolia re-indexing all records';
    }

    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    public function setup()
    {
        parent::setup();

        $algoliaService = Injector::inst()->create(AlgoliaService::class);
        $task = new AlgoliaReindex();

        $this->totalSteps = 0;
        $this->currentStep = 0;

        $indexData = [];

        $filters = $this->config()->get('reindexing_default_filters');
        $batchSize = $task->config()->get('batch_size');
        $batching = $this->config()->get('use_batching');

        // find all classes we have to index and add them to the indexData map
        // in groups of batch size, this setup operation does the heavy lifting
        // and process simply handles one batch at a time.
        foreach ($algoliaService->indexes as $indexName => $index) {
            $classes = (isset($index['includeClasses'])) ? $index['includeClasses'] : null;
            $indexFilters = (isset($index['includeFilters'])) ? $index['includeFilters'] : null;

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
                        $this->addMessage('[' . $indexName . '] Indexing ' . count($ids) . ' ' . $class . ' instances with filters: ' . ($filter ?: '(none)'));
                    } else {
                        $this->addMessage('[' . $indexName . '] 0 ' . $class . ' instances to index with filters: ' . ($filter ?: '(none) - skipping.'));
                    }
                }
            }
        }
        $this->totalSteps += count($indexData);
        // Store in jobData to get written to the job descriptor in DB
        if (!$this->jobData) {
            $this->jobData = new stdClass();
        }
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
    public function process()
    {
        if ($this->currentStep >= $this->totalSteps) {
            $this->isComplete = true;
            $this->addMessage('Done!');
            return;
        }
        $indexData = isset($this->jobData->IndexData) ? $this->jobData->IndexData : null;
        if (!isset($indexData[$this->currentStep])) {
            $this->isComplete = true;
            $this->addMessage('Somehow we ran out of job data before all steps were processed. So we will assume we are done!');
            $this->addMessage('Dumping out the jop data for debug purposes: ' . json_encode($indexData));
            return;
        }

        $stepData = $indexData[$this->currentStep];
        $class = $stepData['class'];

        try {
            $task = new AlgoliaReindex();

            if (isset($stepData['ids'])) {
                $summary = $task->indexItems($stepData['indexName'], DataObject::get($class)->filter('ID', $stepData['ids']), false);
                $this->addMessage($summary);
            } else {
                $item = DataObject::get($class)->byID($stepData['id']);
                if ($item) {
                    if (min($item->invokeWithExtensions('canIndexInAlgolia')) === false) {
                        $this->addMessage('Skipped indexing ' . $class . ' ' . $item->ID);
                    } elseif ($task->indexItem($item)) {
                        $this->addMessage('Successfully indexed ' . $class . ' ' . $item->ID);
                    } else {
                        $this->addMessage('Error indexing ' . $class . ' ' . $item->ID);
                    }
                } else {
                    $this->addMessage('Error indexing ' . $class . ' ' . $stepData['id'] . ' - failed to load item from DB');
                }
            }

            $errors = $task->getErrors();
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if (!empty($errors)) {
            $this->addMessage(implode(', ', $errors));
            $task->clearErrors();
        }

        $this->currentStep++;
    }
}
