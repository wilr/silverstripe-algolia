<?php

namespace Wilr\Silverstripe\Algolia\Jobs;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

/**
 * Remove an item (or multiple items) from Algolia async. This method works well
 * for performance and batching large operations
 */
class AlgoliaDeleteItemJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * @param string $itemClass
     * @param array|int $itemIds
     */
    public function __construct($itemClass, $key)
    {
        $this->itemClass = $itemClass;
        $this->itemID = $key;
    }


    /**
     * Defines the title of the job.
     *
     * @return string
     */
    public function getTitle()
    {
        return sprintf(
            'Algolia remove %s',
            $this->itemID
        );
    }

    /**
     * @return int
     */
    public function getJobType()
    {
        $this->totalSteps = 1;

        return QueuedJob::IMMEDIATE;
    }

    /**
     * This is called immediately before a job begins - it gives you a chance
     * to initialise job data and make sure everything's good to go
     *
     * What we're doing in our case is to queue up the list of items we know we need to
     * process still (it's not everything - just the ones we know at the moment)
     *
     * When we go through, we'll constantly add and remove from this queue, meaning
     * we never overload it with content
     */
    public function setup()
    {

    }

    /**
     * Lets process a single node
     */
    public function process()
    {
        try {
            $indexer = Injector::inst()->create(AlgoliaIndexer::class);
            $indexer->deleteData($this->itemClass, $this->itemID);
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);
        }

        $this->isComplete = true;

        return;
    }
}
