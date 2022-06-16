<?php

namespace Wilr\Silverstripe\Algolia\Jobs;

use Exception;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaReindex;

/**
 * Reindex everything via a queued job (when AlgoliaReindex task won't do). This
 * supports reindexing via batch operations. Algolia limits apply.
 */
class AlgoliaReindexAllJob extends AbstractQueuedJob implements QueuedJob
{
    use Configurable;

    public $indexData = [];

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

        $filters = $this->config()->get('reindexing_default_filters');

        // find all classes we have to index and add them to the indexData map
        // in groups of batch size, this setup operation does the heavy lifting
        // and process simply handles one batch at a time.
        foreach ($algoliaService->indexes as $index) {
            $classes = (isset($index['includeClasses'])) ? $index['includeClasses'] : null;

            if ($classes) {
                foreach ($classes as $candidate) {
                    $filter = (isset($filters[$candidate])) ? $filters[$candidate] : '';
                    $count = 0;

                    foreach ($task->getItems($candidate, $filter)->column('ID') as $id) {
                        $count++;

                        if (!isset($this->indexData[$candidate])) {
                            $this->indexData[$candidate] = [];
                        }

                        $this->indexData[$candidate][] = $id;
                        $this->totalSteps++;
                    }

                    $this->addMessage('Indexing '. $count . ' '. $candidate . ' instances with filters '. $filter);
                }
            }
        }
    }

    /**
     * Index data is in groups of 20.
     */
    public function process()
    {
        $remainingChildren = $this->indexData;

        if (!$remainingChildren || empty($remainingChildren)) {
            $this->isComplete = true;
            $this->addMessage('Done!');

            return;
        }

        $task = new AlgoliaReindex();

        $batchSize = $task->config()->get('batch_size');
        $batching = $this->config()->get('use_batching');

        foreach ($remainingChildren as $class => $ids) {
            $take = array_slice($ids, 0, $batchSize);
            $this->indexData[$class] = array_slice($ids, $batchSize);

            if (!empty($take)) {
                $this->currentStep += count($take);
                $errors = [];

                try {
                    if ($batching) {
                        if ($task->indexItems($class, '', DataObject::get($class)->filter('ID', $take), false)) {
                            $this->addMessage('Successfully indexing '. $class . ' ['. implode(', ', $take) . ']');
                        } else {
                            $this->addMessage('Error indexing '. $class . ' ['. implode(', ', $take) . ']');
                        }
                    } else {
                        $items = DataObject::get($class)->filter('ID', $take);

                        foreach ($items as $item) {
                            if ($task->indexItem($item)) {
                                $this->addMessage('Successfully indexed '. $class . ' ['. $item->ID . ']');
                            } else {
                                $this->addMessage('Error indexing '. $class . ' ['. $item->ID . ']');
                            }
                        }
                    }

                    $errors = $task->getErrors();
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }

                if (!empty($errors)) {
                    $this->addMessage(implode(', ', $errors));
                    $task->clearErrors();
                }
            } else {
                unset($this->indexData[$class]);
            }
        }
    }
}
