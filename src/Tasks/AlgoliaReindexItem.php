<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;

class AlgoliaReindexItem extends BuildTask
{
    protected $title = 'Algolia Reindex Item';

    protected $description = 'Algolia reindex a single item';

    private static $segment = 'AlgoliaReindexItem';

    protected $errors = [];

    public function run($request)
    {
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo();

        if ($request->getVar('class')) {
            $targetClass = $request->getVar('class');
        } else {
            echo 'Missing class';
            exit;
        }

        if ($request->getVar('id')) {
            $id = $request->getVar('id');
        } else {
            echo 'Missing class';
            exit;
        }

        $obj = DataObject::get($targetClass)->byID($id);

        if (!$obj) {
            echo 'Object not found';
            exit;
        }

        $result = $obj->doImmediateIndexInAlgolia();

        echo sprintf('Indexed: %s, UUID: %s, Err: %s', $result, $obj->AlgoliaUUID, $obj->AlgoliaError);
    }
}
