<?php

use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

class AlgoliaReindex extends BuildTask
{
    protected $title = 'Algolia Reindex';

    protected $description = 'Algolia Reindex';

    public function run($request)
    {
        $siteConfig = SiteConfig::current_site_config();

        $items = Versioned::get_by_stage(
            SiteTree::class,
            'Live', 'AlgoliaIndexed IS NULL OR AlgoliaIndexed < (NOW() - INTERVAL 2 HOUR)'
        );

        $count = 0;
        $skipped = 0;
        $errored = 0;
        $total = $items->count();

        Debug::message('Found '. $total . ' pages to index');
        $pos = 0;

        foreach ($items as $item) {
            $pos++;

            // fetch the actual instance
            $instance = DataObject::get_by_id($item->ClassName, $item->ID);

            if (!$instance) {
                $skipped++;

                continue;
            }

            echo '.';

            if ($pos % 100 == 0) {
                echo sprintf(' [%s/%s]%s', $pos, $total, PHP_EOL);
            }

            try {
                if ($instance->indexInAlgolia()) {
                    DB::query(sprintf('UPDATE Page SET AlgoliaIndexed = NOW() WHERE ID = %s', $instance->ID));
                    DB::query(sprintf('UPDATE Page_Live SET AlgoliaIndexed = NOW() WHERE ID = %s', $instance->ID));

                    $count++;
                } else {
                    $skipped++;
                }
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);

                $errored++;

                if (Director::isDev()) {
                    Debug::message($e->getMessage());
                }
            }
        }

        Debug::message("Number of pages indexed: " . $count . ', '. $errored . ' Errors raised');

        Debug::message(sprintf(
            "See index at <a href='https://www.algolia.com/apps/%s/explorer/browse/%s' target='_blank'>".
            "algolia.com/apps/%s/explorer/browse/%s</a>",
            $siteConfig->applicationID,
            $siteConfig->indexName,
            $siteConfig->applicationID,
            $siteConfig->indexName
        ));
    }
}
