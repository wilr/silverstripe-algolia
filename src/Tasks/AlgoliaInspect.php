<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use Wilr\SilverStripe\Algolia\Service\AlgoliaIndexer;

class AlgoliaInspect extends BuildTask
{
    private static $segment = 'AlgoliaInspect';

    public function run($request)
    {
        $itemClass = $request->getVar('class');
        $itemId = $request->getVar('id');

        if (!$itemClass || !$itemId) {
            echo 'Missing class or id parameters';
            exit();
        }

        $item = $itemClass::get()->byId($itemId);

        if (!$item || !$item->canView()) {
            echo 'Missing or unviewable object '. $itemClass . ' #'. $itemId;
            exit();
        }

        $indexer = Injector::inst()->create(AlgoliaIndexer::class);
        $indexer->getService()->syncSettings();

        echo '### LOCAL FIELDS' . PHP_EOL;
        echo '<pre>';
        print_r($indexer->exportAttributesFromObject($item));

        echo '### REMOTE FIELDS ###' . PHP_EOL;
        print_r($indexer->getObject($item));

        echo '### INDEX SETTINGS ### '. PHP_EOL;
        foreach ($item->getAlgoliaIndexes() as $index) {
            print_r($index->getSettings());
        }

        echo '### ALGOLIA STATUS ### '. PHP_EOL;
        echo 'Error: '. $item->AlgoliaError . PHP_EOL;
        echo 'LastIndexed: ' . $item->AlgoliaIndexed . PHP_EOL;
        echo 'Algolia UUID: '. $item->AlgoliaUUID . PHP_EOL;

        echo PHP_EOL . 'Done.' . PHP_EOL;
    }
}
