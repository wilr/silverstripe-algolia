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
        $itemClass = $request->getVar('ClassName');
        $itemId = $request->getVar('ID');

        if (!$itemClass || !$itemId) {
            return $this->httpError(400, 'Missing ClassName or ID');
        }

        $item = $itemClass::get()->byId($itemId);

        if (!$item || !$item->canView()) {
            return $this->httpError(404, 'Missing or unviewable item');
        }

        $indexer = Injector::inst()->create(AlgoliaIndexer::class);
        $indexer->syncSettings();

        echo '### LOCAL FIELDS' . PHP_EOL;
        echo '<pre>';
        print_r($indexer->exportAttributesFromObject($item));

        echo '### REMOTE FIELDS ###' . PHP_EOL;
        print_r($indexer->getObject($item));

        echo '### INDEX SETTINGS ### '. PHP_EOL;
        foreach ($item->getAlgoliaIndexes() as $index) {
            print_r($index->getSettings());
        }

        echo PHP_EOL . 'Done.' . PHP_EOL;
    }
}
