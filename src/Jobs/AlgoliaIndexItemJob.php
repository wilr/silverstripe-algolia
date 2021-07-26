<?php

namespace Wilr\Silverstripe\Algolia\Jobs;

use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Index an item (or multiple items) into Algolia async. This method works well
 * for performance and batching large indexes
 */
class AlgoliaIndexItemJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * @param string    $itemClass
     * @param array|int $itemIds
     */
    public function __construct($itemClass = null, $itemIds = null)
    {
        // this value is automatically persisted between processing requests for
        // this job
        if ($itemClass) {
            $this->itemClass = $itemClass;
        }

        if ($itemIds) {
            if (!is_array($itemIds)) {
                $this->itemIds = [$itemIds];
            } else {
                $this->itemIds = $itemIds;
            }
        }

        $this->remainingIds = $this->itemIds;
    }


    /**
     * Defines the title of the job.
     *
     * @return string
     */
    public function getTitle()
    {
        return sprintf(
            'Algolia reindex %s (%s)',
            $this->itemClass,
            implode(', ', $this->itemIds)
        );
    }

    /**
     * @return int
     */
    public function getJobType()
    {
        $this->totalSteps = count($this->itemIds);

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
        if (!count($this->remainingIds)) {
            $this->isComplete = true;

            return;
        }
    }

    /**
     * Lets process a single node
     */
    public function process()
    {
        $remainingChildren = $this->remainingIds;

        if (!count($remainingChildren)) {
            $this->isComplete = true;

            return;
        }

        $this->currentStep++;

        $id = array_shift($remainingChildren);

        $obj = DataObject::get_by_id($this->itemClass, $id);

        if (!$obj) {
            $this->addMessage('Record #'. $id . ' not found');
        } elseif (!$obj->canIndexInAlgolia()) {
            $this->addMessage('Record #'. $id .' not indexed, canIndexInAlgolia returned false');
        } else {
            if (!$obj->AlgoliaUUID) {
                $obj->assignAlgoliaUUID();
            }
            $obj->doImmediateIndexInAlgolia();

            $this->addMessage('Record #'. $id .' indexed as objectID '. $obj->AlgoliaUUID);

            unset($obj);
        }

        $this->remainingChildren = $remainingChildren;

        if (!count($remainingChildren)) {
            $this->isComplete = true;
            return;
        }
    }
}
