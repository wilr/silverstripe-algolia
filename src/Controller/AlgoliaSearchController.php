<?php

namespace Wilr\SilverStripe\Algolia\Controller;

use PageController;
use SilverStripe\Core\Convert;
use SilverStripe\View\ArrayData;

class AlgoliaSearchController extends PageController
{
    private static $allowed_actions = [
        'index'
    ];

    public function index()
    {
        $q = $this->request->getVar('Search');

        return $this->customise(new ArrayData([
            'Title' => sprintf('Search results', Convert::raw2att($q)),
            'SearchQuery' => $q
        ]))->renderWith(['Page_results', 'Page']);
    }
}
