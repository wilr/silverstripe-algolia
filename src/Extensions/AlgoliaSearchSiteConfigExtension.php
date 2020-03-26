<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataExtension;

class AlgoliaSearchSiteConfigExtension extends DataExtension
{
    private static $db = [
        'adminAPIKey' => 'Varchar(150)',
        'searchAPIKey' => 'Varchar(150)',
        'applicationID' => 'Varchar(50)',
        'indexName' => 'Varchar(100)'
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab('Root.Search', [
            TabSet::create('Algolia',
                Tab::create('API Configuration',
                    HeaderField::create('WidgetInstructions', 'API Configuration', 3),
                    TextField::create('adminAPIKey', 'Admin API Key'),
                    TextField::create('searchAPIKey', 'Search API Key'),
                    TextField::create('applicationID', 'Application ID'),
                    TextField::create('indexName', 'Index Name'),
                    LiteralField::create(
                        'algoliaLink',
                        '<a href="https://www.algolia.com/users/sign_in" target="_blank">Algolia.com</a> (opens in new tab)'
                    )
                )
            ),
        ]);
    }
}
