<?php

namespace Wilr\SilverStripe\Algolia\Tests;

use Masterminds\HTML5;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wilr\SilverStripe\Algolia\Service\AlgoliaPageCrawler;

class AlgoliaPageCrawlerTest extends TestCase
{
    public function testBlockElementsAreSeparatedInExtractedContent(): void
    {
        $content = $this->extractFromMain(
            '<h2>Discover our awesome herd of elephants</h2><p>Please come check them out now!</p>'
        );

        $this->assertSame(
            'Discover our awesome herd of elephants Please come check them out now!',
            $content
        );
    }

    public function testInlineElementsDoNotForceExtraWordBreaks(): void
    {
        $content = $this->extractFromMain('<p>Find <strong>elephants</strong><em>now</em>.</p>');

        $this->assertSame('Find elephantsnow.', $content);
    }

    public function testLineBreakElementAddsSeparator(): void
    {
        $content = $this->extractFromMain('<p>Alpha<br>Beta</p>');

        $this->assertSame('Alpha Beta', $content);
    }

    public function testScriptStyleAndNoscriptContentIsIgnored(): void
    {
        $content = $this->extractFromMain(
            '<p>Visible</p><script>alert("x")</script><style>.x{display:none;}</style><noscript>No JS</noscript><p>Content</p>'
        );

        $this->assertSame('Visible Content', $content);
    }

    public function testNestedBlockElementsAreSeparated(): void
    {
        $content = $this->extractFromMain(
            '<section><div><h3>Heading</h3><p>Paragraph one</p></div><div><p>Paragraph two</p></div></section>'
        );

        $this->assertSame('Heading Paragraph one Paragraph two', $content);
    }

    public function testListItemsAreSeparated(): void
    {
        $content = $this->extractFromMain('<ul><li>Elephants</li><li>Giraffes</li><li>Lions</li></ul>');

        $this->assertSame('Elephants Giraffes Lions', $content);
    }

    public function testTemplateTagContentIsIgnored(): void
    {
        $content = $this->extractFromMain(
            '<p>Before</p><template><p>Hidden text</p></template><p>After</p>'
        );

        $this->assertSame('Before After', $content);
    }

    private function extractFromMain(string $html): string
    {
        $crawler = new AlgoliaPageCrawler(null);
        $dom = (new HTML5())->loadHTML('<main>' . $html . '</main>');
        $mainNode = $dom->getElementsByTagName('main')->item(0);

        $extractMethod = new ReflectionMethod(AlgoliaPageCrawler::class, 'extractNodeText');
        $extractMethod->setAccessible(true);

        return preg_replace('/\s+/', ' ', trim($extractMethod->invoke($crawler, $mainNode)));
    }
}
