<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Html;

/**
 * Every enqueued stylesheet and script is on the page once.
 *
 * The scaffolded themes print them themselves — `renderCss()` in their `<head>`, `renderJs()`
 * before `</body>` — and the document also put them in the head it draws, and appended its foot
 * after the theme's. So each came out twice: jQuery, select2, DataTables and pf-utils loaded twice
 * on every screen that enqueued them, and a library initialised twice binds its handlers twice.
 * A theme that prints neither still gets them from the document, once.
 */
#[CoversClass(Document::class)]
class AssetsPrintedOnceTest extends TestCase
{
    /**
     * A page drawn by a document with a theme whose layout calls renderCss()/renderJs() — or does
     * not, when `$printsAssets` is false — with a stylesheet, a head script, a footer script and
     * an inline one enqueued.
     */
    private function page(bool $printsAssets): string
    {
        $doc = new Html();
        $doc->registerStyle('pf-test-style', 'css/once.css');
        $doc->enqueueStyle('pf-test-style');
        $doc->registerScript('pf-test-head', 'js/head-once.js', [], '', false);
        $doc->enqueueScript('pf-test-head');
        $doc->registerScript('pf-test-foot', 'js/foot-once.js', [], '', true);
        $doc->enqueueScript('pf-test-foot');
        $doc->addInlineScript('window.inlineOnce = 1;');
        $doc->themeObject = new LayoutThatPrints($doc, $printsAssets);

        return (string) $doc->render();
    }

    /**
     * A theme that prints the assets: each is on the page exactly once, the stylesheet and head
     * script in the head — the head script stays there although the theme's renderJs() is at the
     * end of the body — the footer and inline scripts in the body.
     */
    public function testAThemeThatPrintsThemGetsEachOnce(): void
    {
        // Act
        $html = $this->page(true);

        // Assert
        foreach (['css/once.css', 'js/head-once.js', 'js/foot-once.js', 'window.inlineOnce = 1;'] as $asset) {
            $this->assertSame(1, substr_count($html, $asset), $asset . ' once');
        }
        // Where they belong: the stylesheet and the head script before </head> — an inline script in
        // the content can use a library loaded there — and the footer script after the content
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'css/once.css'));
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'js/head-once.js'));
        $this->assertGreaterThan(strpos($html, 'CONTENT'), strpos($html, 'js/foot-once.js'));
    }

    /** A theme that prints none of them still gets every one, once, from the document. */
    public function testAThemeThatPrintsNoneStillGetsThem(): void
    {
        // Act
        $html = $this->page(false);

        // Assert
        foreach (['css/once.css', 'js/head-once.js', 'js/foot-once.js', 'window.inlineOnce = 1;'] as $asset) {
            $this->assertSame(1, substr_count($html, $asset), $asset . ' once');
        }
    }

    /**
     * `mtime` as a version stamps a file of this site with its modification time, as
     * `assetUrl()` does — on the script whether given when registering or enqueueing, and on
     * the stylesheet — so a deploy is a new address; another site's address is left alone.
     */
    public function testMtimeVersionsAFileOfThisSite(): void
    {
        // Arrange — a file under the web root, as assetUrl() finds them
        $dir  = \Pramnos\Application\FrameworkScripts::webRoot((string) ROOT) . '/assets/pf-test';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/stamp.js', '//');
        file_put_contents($dir . '/stamp.css', '/**/');
        touch($dir . '/stamp.js', 1700000000);
        touch($dir . '/stamp.css', 1700000001);
        $doc = new Html();

        try {
            // Act
            $doc->registerScript('pf-stamp', sURL . 'assets/pf-test/stamp.js', [], 'mtime', false);
            $doc->enqueueScript('pf-stamp');
            $doc->enqueueScript('pf-stamp-2', 'assets/pf-test/stamp.js?x=1', [], 'mtime', false);
            $doc->registerScript('pf-cdn', 'https://cdn.test/lib.js', [], 'mtime', false);
            $doc->enqueueScript('pf-cdn');
            $doc->registerStyle('pf-stamp-css', sURL . 'assets/pf-test/stamp.css', [], 'mtime');
            $doc->enqueueStyle('pf-stamp-css');
            ob_start();
            $doc->renderCss();
            $doc->renderJs();
            $out = (string) ob_get_clean();
        } finally {
            @unlink($dir . '/stamp.js');
            @unlink($dir . '/stamp.css');
            @rmdir($dir);
        }

        // Assert
        $this->assertStringContainsString('assets/pf-test/stamp.js?v=1700000000"', $out);
        $this->assertStringContainsString('assets/pf-test/stamp.css?v=1700000001"', $out);
        $this->assertStringContainsString('src="https://cdn.test/lib.js"', $out, 'another site\'s file: no stamp');
        $this->assertStringNotContainsString('?v=mtime', $out);
    }

    /**
     * A layout drawn without the document's head — renderJs() called on its own — still gets the
     * head scripts from it, as it always did.
     */
    public function testRenderJsAloneCarriesTheHeadScripts(): void
    {
        // Arrange
        $doc = new Html();
        $doc->registerScript('pf-alone-head', 'js/alone.js', [], '', false);
        $doc->enqueueScript('pf-alone-head');

        // Act
        ob_start();
        $doc->renderJs();
        $out = (string) ob_get_clean();

        // Assert
        $this->assertStringContainsString('js/alone.js', $out);
    }
}

/**
 * A theme layout as the scaffolded ones are: its `<head>` calls renderCss(), its body ends with
 * renderJs() — or neither.
 */
final class LayoutThatPrints
{
    /** The layout as drawn. */
    private string $contents = '';

    public function __construct(private Document $document, private bool $printsAssets)
    {
    }

    /** Draw the layout, as Theme::loadtheme() does, with the theme's PHP running. */
    public function loadTheme(): void
    {
        ob_start();
        echo '<html><head><title>t</title>';
        if ($this->printsAssets) {
            $this->document->renderCss();
        }
        echo '</head><body><nav></nav>[MODULE]<footer></footer>';
        if ($this->printsAssets) {
            $this->document->renderJs();
        }
        echo '</body></html>';
        $this->contents = (string) ob_get_clean();
    }

    /** Everything inside <head>. */
    public function getheader(): string
    {
        return preg_match('#<head>(.*?)</head>#s', $this->contents, $m) === 1 ? $m[1] : '';
    }

    /** The body up to the content, and the content's place. */
    public function gethead(): string
    {
        $body = preg_match('#<body>(.*?)</body>#s', $this->contents, $m) === 1 ? $m[1] : '';

        return substr($body, 0, (int) strpos($body, '[MODULE]')) . 'CONTENT';
    }

    /** The body after the content. */
    public function getfoot(): string
    {
        $body = preg_match('#<body>(.*?)</body>#s', $this->contents, $m) === 1 ? $m[1] : '';

        return substr($body, (int) strpos($body, '[MODULE]') + 8);
    }
}
