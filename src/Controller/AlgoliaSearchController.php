<?php

namespace Wilr\SilverStripe\Algolia\Controller;

use PageController;
use SilverStripe\Core\Convert;
use SilverStripe\View\ArrayData;

class AlgoliaSearchController extends PageController
{
    private static $result_template = 'Page_results';

    private static $allowed_actions = [
        'index'
    ];

    public function index()
    {
        $q = $this->request->getVar('Search');

        return $this->customise(new ArrayData([
            'Title' => sprintf(_t(__CLASS__.'.Results', 'Search results'), Convert::raw2att($q)),
            'SearchQuery' => $q
        ]))->renderWith([$this->config()->get('result_template'), 'Page']);
    }
}
