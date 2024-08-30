<?php

namespace Wilr\SilverStripe\Algolia\Tasks;

use SilverStripe\CMS\Model\VirtualPage;
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
            echo 'Missing class argument';
            exit;
        }

        if ($request->getVar('id')) {
            $id = $request->getVar('id');
        } else {
            echo 'Missing id argument';
            exit;
        }

        $obj = DataObject::get($targetClass)->byID($id);

        if (!$obj) {
            echo 'Object not found';
            exit;
        }

        // Set AlgoliaUUID, in case it wasn't previously set
        if (!$obj->AlgoliaUUID) {
            echo 'No AlgoliaUUID set on object, generating one...' . PHP_EOL;
            $obj->assignAlgoliaUUID(true);
        }


        $result = $obj->doImmediateIndexInAlgolia();

        echo sprintf(
            'Indexed: %s%sUUID: %s%s%s',
            $result ? 'true' : 'false',
            PHP_EOL,
            $obj->AlgoliaUUID ? $obj->AlgoliaUUID : 'No ID set',
            PHP_EOL,
            $obj->AlgoliaError ? 'Error from Algolia: ' . $obj->AlgoliaError : ''
        );
    }
}
