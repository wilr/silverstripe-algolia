<?php

namespace Wilr\SilverStripe\Algolia\Service;

use DOMDocument;
use DOMXPath;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\SiteConfig\SiteConfig;

class AlgoliaIndexer
{
    private $item;

    private $apiKey;

    private $applicationID;

    private $indexName;

    public function __construct(SiteTree $item)
    {
        $siteConfig = SiteConfig::current_site_config();

        $this->item = $item;
        $this->apiKey = $siteConfig->adminAPIKey;
        $this->applicationID = $siteConfig->applicationID;
        $this->indexName = $siteConfig->indexName;
    }

    public function indexData()
    {
        $item = $this->item;

        $client = new \AlgoliaSearch\Client($this->applicationID, $this->apiKey);
        $searchIndex = $client->initIndex($this->indexName);

        if (!$item->ShowInSearch) {
            $this->deleteData();

            return;
        }

        $toIndex = [
            'objectID' => $item->ID,
            'objectTitle' => $item->Title,
            'objectLastEdited' => $item->dbObject('LastEdited')->Rfc822()
        ];

        foreach ($item->db() as $k => $v) {
            if (in_array($k, ['ID', 'Title', 'AlgoliaIndexed', 'CanViewType', 'CanEditType', 'Locale'])) {
                continue;
            }

            // don't index int's and booleans for now.
            if (strpos($v, 'Boolean') !== false || strpos($v, 'Int')) {
                continue;
            }

            try {
                $toIndex[$k] = $item->dbObject($k)->forTemplate();
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
            }
        }

        $toIndex['objectLink'] = str_replace(['?stage=Stage', '?stage=Live'], '', $item->AbsoluteLink());
        $toIndex['objectForTemplate'] = $this->getMainContent();

        if (!$toIndex['objectForTemplate'] && isset($toIndex['Content'])) {
            // default to content
            $toIndex['objectForTemplate'] = $toIndex['Content'];
        }

        $searchIndex->addObject($toIndex);
    }

    public function getMainContent()
    {
        $controller = ModelAsController::controller_for($this->item);
        $page = '';

        try {
            $page = $controller->render();
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);
        }

        $output = '';

        // just get the interal content for the page.
        if ($page) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($page->forTemplate());
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query("//div[@id='main-content']");

            if ($nodes) {
                $output = $nodes[0]->nodeValue;
            } else {
                $nodes = $xpath->query("//div[@id='main-page']");

                if ($nodes) {
                    $output = $nodes[0]->nodeValue;
                }
            }
        }

        return $output;
    }

    public function deleteData()
    {
        $item = $this->item;

        $client = new \AlgoliaSearch\Client($this->applicationID, $this->apiKey);
        $searchIndex = $client->initIndex($this->indexName);

        $searchIndex->deleteObject($item->ID);
    }
}
