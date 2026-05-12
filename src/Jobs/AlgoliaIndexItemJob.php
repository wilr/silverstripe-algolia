<?php

namespace Wilr\Silverstripe\Algolia\Jobs;

use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension;

/**
 * Index an item (or multiple items) into Algolia async. This method works well
 * for performance and batching large indexes
 */
class AlgoliaIndexItemJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * @param array<int|string>|int|string|null $itemIds
     */
    public function __construct(?string $itemClass = null, array|string|int|null $itemIds = null)
    {
        // this value is automatically persisted between processing requests for
        // this job
        if ($itemClass) {
            $this->itemClass = $itemClass;
        }

        if ($itemIds !== null && $itemIds !== []) {
            if (!is_array($itemIds)) {
                $this->itemIds = explode(',', (string) $itemIds);
            } else {
                $this->itemIds = $itemIds;
            }
        }
    }

    /**
     * Defines the title of the job.
     *
     * @return string
     */
    public function getTitle(): string
    {
        $rawIds = $this->itemIds;
        $ids = is_array($rawIds) ? $rawIds : [];

        return sprintf(
            'Algolia reindex %s (%s)',
            (string) $this->itemClass,
            implode(', ', $ids)
        );
    }

    public function getJobType(): string
    {
        $rawIds = $this->itemIds;
        $itemIds = is_array($rawIds) ? $rawIds : [];

        $this->totalSteps = count($itemIds);

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
    public function setup(): void
    {
        parent::setup();

        $rawRemaining = $this->itemIds;
        $this->remainingIds = is_array($rawRemaining) ? $rawRemaining : [];
        $this->currentStep = 0;
        $this->totalSteps = count($this->remainingIds);
    }

    /**
     * Lets process a single node
     */
    public function process(): void
    {
        $remainingChildren = [];
        $rawRemaining = $this->remainingIds;
        if (is_array($rawRemaining)) {
            $remainingChildren = $rawRemaining;
        }

        if ($remainingChildren === []) {
            $this->isComplete = true;

            return;
        }

        $this->currentStep++;

        $id = array_shift($remainingChildren);

        $obj = DataObject::get_by_id($this->itemClass, $id);

        if (!$obj) {
            $this->addMessage('Record #'. $id . ' not found');
        } elseif (AlgoliaObjectExtension::shouldBlockIndexingForAlgolia($obj)) {
            $this->addMessage('Record #'. $id .' not indexed, canIndexInAlgolia returned false');
        } else {
            if (!$obj->AlgoliaUUID) {
                AlgoliaObjectExtension::runAssignAlgoliaUuid($obj);
            }

            if (AlgoliaObjectExtension::runDoImmediateIndexInAlgolia($obj)) {
                $this->addMessage('Record #'. $id .' successfully indexed as objectID '. $obj->AlgoliaUUID);
            } else {
                $this->addMessage('Record #'. $id .' failed to be indexed: '. $obj->AlgoliaError);
            }

            unset($obj);
        }

        $this->remainingIds = $remainingChildren;

        if (!count($remainingChildren)) {
            $this->isComplete = true;
            return;
        }
    }
}
