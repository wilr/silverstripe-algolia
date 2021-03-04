<?php

namespace Wilr\SilverStripe\Algolia\Service;

use DOMDocument;
use DOMXPath;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\SSViewer;

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
    use Configurable;

    private $item;

    /**
     * Defines the xpath selector for the first element of content
     * that should be indexed.
     *
     * @config
     * @var    string
     */
    private static $content_xpath_selector = '//main';

    public function __construct($item)
    {
        $this->item = $item;
    }

    public function getMainContent(): string
    {
        if (!$this->item instanceof SiteTree) {
            return '';
        }

        $selector = $this->config()->get('content_xpath_selector');

        if (!$selector) {
            return '';
        }

        // Enable frontend themes in order to correctly render the elements as
        // they would be for the frontend
        Config::nest();
        SSViewer::set_themes(SSViewer::config()->get('themes'));

        $controller = ModelAsController::controller_for($this->item);
        $page = '';
        $output = '';

        try {
            /** @var DBHTMLText $page */
            $page = $controller->render();

            if ($page) {
                libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $dom->loadHTML($page->forTemplate());
                $xpath = new DOMXPath($dom);
                $nodes = $xpath->query($selector);

                if (isset($nodes[0])) {
                    $output = $nodes[0]->nodeValue;
                }
            }
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);
        }

        Config::unnest();

        return $output;
    }
}
