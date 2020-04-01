<?php

namespace Wilr\SilverStripe\Algolia\Service;

use DOMDocument;
use DOMXPath;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;

/**
 * Fetches the main content off the page to index. This handles more complex
 * templates. Main content should be low-weighted as depending on your
 * front-end the <main> element may contain other information which should
 * not be indexed.
 *
 * @todo allow filtering
 */
class AlgoliaPageCrawler
{
    private $item;

    public function __construct($item)
    {
        $this->item = $item;
    }

    public function getMainContent()
    {
        if (!$this->item instanceof SiteTree) {
            return '';
        }

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
            $nodes = $xpath->query("//main");

            if ($nodes) {
                $output = $nodes[0]->nodeValue;
            }
        }

        return $output;
    }
}
