<?php

namespace Wilr\Silverstripe\Algolia\Jobs;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;
use Wilr\SilverStripe\Algolia\Tasks\AlgoliaReindex;

/**
 * Reindex everything via a queued job (when AlgoliaReindex task won't do)
 *
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

    public function __construct($params = array())
    {
    }

    public function getTitle()
    {
        return 'Algolia reindexing everything';
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

        // find all classes we have to index and do so
        foreach ($algoliaService->indexes as $index) {
            $classes = (isset($index['includeClasses'])) ? $index['includeClasses'] : null;

            if ($classes) {
                foreach ($classes as $candidate) {
                    $filter = (isset($filters[$candidate])) ? $filters[$candidate] : '';

                    foreach ($task->getItems($candidate, $filter)->column('ID') as $id) {
                        $key = $candidate . '|'. $id;

                        $this->indexData[$key] = $key;
                        $this->totalSteps++;
                    }
                }
            }
        }
    }

    public function process()
    {
        $remainingChildren = $this->indexData;

        if (!count($remainingChildren)) {
            $this->isComplete = true;

            return;
        }

        $this->currentStep++;

        list($class, $id) = explode('|', array_shift($remainingChildren));

        $obj = DataObject::get_by_id($class, $id);

        if ($obj && $obj->canIndexInAlgolia()) {
            if (!$obj->AlgoliaUUID) {
                $obj->assignAlgoliaUUID();
            }

            if ($obj->AlgoliaUUID) {
                $obj->doImmediateIndexInAlgolia();
            }
        }

        $this->addMessage(sprintf('[%s/%s], %s', $this->currentStep, $this->totalSteps, $class . '#'. $id));

        $this->indexData = $remainingChildren;

        if (!count($remainingChildren)) {
            $this->isComplete = true;

            return;
        }
    }
}
