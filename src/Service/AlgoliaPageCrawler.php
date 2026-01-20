<?php

namespace Wilr\SilverStripe\Algolia\Service;

use DOMXPath;
use Masterminds\HTML5;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Throwable;

/**
 * Fetches the main content off the page to index. This handles more complex
 * templates. Main content should be low-weighted as depending on your
 * front-end the <main> element may contain other information which should
 * not be indexed.
 */
class AlgoliaPageCrawler
{
    use Configurable;

    private $item;

    private static $content_cutoff_bytes = 100000;

    /**
     * Defines the xpath selector for the first element of content
     * that should be indexed. If blank, defaults to the `main` element
     *
     * @config
     * @var string
     */
    private static $content_xpath_selector = '';

    /**
     * @config
     *
     * @var string
     */
    private static $content_element_tag = 'main';


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
        $useXpath = true;

        if (!$selector) {
            $useXpath = false;
            $selector = $this->config()->get('content_element_tag');
        }

        $originalStage = Versioned::get_stage();
        //Always set to live to ensure we don't pick up draft content in our render eg. draft elemental blocks
        Versioned::set_stage(Versioned::LIVE);

        // Enable frontend themes in order to correctly render the elements as
        // they would be for the frontend
        Config::nest();
        $oldThemes = SSViewer::get_themes();
        SSViewer::set_themes(SSViewer::config()->get('themes'));

        Requirements::clear();

        $controller = ModelAsController::controller_for($this->item);
        $current = Controller::curr();

        if ($current) {
            $controller->setRequest($current->getRequest());
        } else {
            $request = new HTTPRequest('GET', $this->item->Link());
            $request->setSession(new Session([]));

            $controller->setRequest($request);
            $controller->pushCurrent();
        }

        $page = '';
        $output = '';

        try {
            /** @var DBHTMLText $page */
            $page = $controller->render();
            if ($page) {
                libxml_use_internal_errors(true);
                $html5 = new HTML5();

                $dom = $html5->loadHTML($page->forTemplate());

                if ($useXpath) {
                    $xpath = new DOMXPath($dom);
                    $nodes = $xpath->query($selector);
                } else {
                    $nodes = $dom->getElementsByTagName($selector);
                }

                if (isset($nodes[0])) {
                    $output = $this->processMainContent($nodes[0]->nodeValue);
                }
            }
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e);
        }

        SSViewer::set_themes($oldThemes);
        Requirements::restore();
        Config::unnest();

        if ($originalStage) {
            Versioned::set_stage($originalStage);
        }

        if ($this->config()->get('content_cutoff_bytes')) {
            $output = mb_strcut($output, 0, $this->config()->get('content_cutoff_bytes') - 1);
        }

        return $output;
    }

    /**
     * Process page DOM content
     *
     * @param string $content DOM node content
     */
    private function processMainContent($content): string
    {
        // Clean up the DOM content
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // set cutoff to allow room for other fields
        $cutoff = $this->config()->get('content_cutoff_bytes') - 20000;

        // If content is still too large, truncate it
        if (strlen($content) >= $cutoff) {
            $content = mb_strcut($content, 0, $cutoff);
        }

        return $content;
    }
}
