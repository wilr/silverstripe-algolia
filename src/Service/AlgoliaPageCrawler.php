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

        // Enable frontend themes in order to correctly render the elements as
        // they would be for the frontend
        Config::nest();
        SSViewer::set_themes(SSViewer::config()->get('themes'));

        Requirements::clear();

        $controller = ModelAsController::controller_for($this->item);
        $current = Controller::has_curr() ? Controller::curr() : null;

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
                    $output = preg_replace('/\s+/', ' ', $nodes[0]->nodeValue);
                }
            }
        } catch (Throwable $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);
        }

        Requirements::restore();
        Config::unnest();

        return $output;
    }
}
