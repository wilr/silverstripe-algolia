<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use Wilr\SilverStripe\Algolia\Service\AlgoliaService;

/**
 * Syncs index settings to Algolia.
 *
 * Note this runs on dev/build automatically but is provided seperately for
 * uses where dev/build is slow (e.g 100,000+ record tables)
 */
class AlgoliaConfigure extends BuildTask
{
    protected $title = 'Algolia Configure';

    protected $description = 'Sync Algolia index configuration';

    private static $segment = 'AlgoliaConfigure';

    public function run($request)
    {
        $service = Injector::inst()->get(AlgoliaService::class);

        if ($service->syncSettings()) {
            echo 'Success.' . PHP_EOL;
        } else {
            echo 'An error occurred while syncing the settings. Please check your error logs.'. PHP_EOL;
        }

        echo 'Done.';
    }
}
