<?php

namespace Wilr\SilverStripe\Algolia\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\Requirements;

class AlgoliaControllerExtension extends DataExtension
{
    public function onAfterInit()
    {
        $siteConfig = SiteConfig::current_site_config();

        $js_config = [
            'apiKeyValue'        => $siteConfig->searchAPIKey,
            'applicationIDValue' => $siteConfig->applicationID,
            'indexNameValue'     => $siteConfig->indexName
        ];

        Requirements::javascriptTemplate(
            'wilr/silverstripe-algolia:client/src/js/components/algolia-search/search-config.js',
            $js_config
        );
    }
}
