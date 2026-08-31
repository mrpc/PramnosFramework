<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Html;

/**
 * A page head states what is true and stays quiet about the rest.
 *
 * `<meta name="description" content="">` is not the absence of a description. It is a claim that
 * this page has none — and a crawler that reads it does not fall back to the page text, it records
 * the claim. An application that simply never set the property said nothing of the sort.
 *
 * The rule is one `Seo::jsonLd()` has documented as "absent is not empty" since it was written,
 * and one this renderer broke on every page it produced.
 */
#[CoversClass(Html::class)]
#[CoversClass(Document::class)]
class HeadIsHonestTest extends TestCase
{
    private function render(callable $setup): string
    {
        Document::reset();
        $doc = new Html();
        $setup($doc);

        return (string) $doc->render();
    }

    /**
     * Nothing set, nothing claimed.
     */
    public function testAnUnsetPropertyEmitsNoTag(): void
    {
        // Act
        $html = $this->render(static function (Html $doc): void {
            $doc->title = 'A page';
        });

        /*
         * Assert — the tags nothing can supply a value for.
         *
         * `og:title` and `og:site_name` are deliberately derived from the title, and `og:type`
         * has a real default of `website`. Those are values the framework *has*, not claims it
         * invents. The four below are the ones that were being emitted empty.
         */
        /*
         * `og:url` is not on this list any more.
         *
         * It defaults to `sURL` — the site's own base — which is a value the framework *has*, not
         * a claim it invents, so emitting it is right. It only looked like an empty tag while the
         * test bootstrap defined `sURL` as `''`, and that emptiness was itself the bug: every
         * absolute URL the framework builds is `sURL . something`, so with no base a test could
         * not tell a correct absolute URL from a relative one.
         */
        foreach (['name="description"',
                  'property="og:image"', 'property="og:description"'] as $tag) {
            $this->assertStringNotContainsString($tag, $html, $tag . ' was emitted empty');
        }
    }

    /**
     * And what is set is emitted.
     *
     * The other half, so the rule above cannot be satisfied by emitting nothing ever.
     */
    public function testAValueThatWasSetIsEmitted(): void
    {
        // Act
        $html = $this->render(static function (Html $doc): void {
            $doc->description = 'What this page is about.';
            $doc->og_url      = 'https://example.com/page';
        });

        // Assert
        $this->assertStringContainsString(
            '<meta name="description" content="What this page is about." />', $html
        );
        $this->assertStringContainsString('property="og:url"', $html);
    }

    /**
     * The viewport is on the document, not on whichever theme remembered it.
     *
     * It was in the scaffolded themes and nowhere else, so a theme that omitted it produced a page
     * Google labels not mobile-friendly with no signal that anything was missing.
     */
    public function testEveryPageDeclaresAViewport(): void
    {
        // Act
        $html = $this->render(static fn (Html $doc) => null);

        // Assert
        $this->assertStringContainsString(
            '<meta name="viewport" content="width=device-width, initial-scale=1">', $html
        );
    }

    /**
     * A large-image card is claimed only when there is an image for it.
     *
     * X reads the OpenGraph tags for everything else; without a card type it renders a small
     * thumbnail. Promising `summary_large_image` with no image renders worse than claiming
     * nothing.
     */
    public function testTheTwitterCardFollowsTheImage(): void
    {
        // Act
        $without = $this->render(static fn (Html $doc) => null);
        $with    = $this->render(static function (Html $doc): void {
            $doc->og_image = 'https://example.com/card.png';
        });

        // Assert
        $this->assertStringNotContainsString('twitter:card', $without);
        $this->assertStringContainsString('content="summary_large_image"', $with);
    }

    /**
     * The RDFa namespaces from 2010 are gone.
     *
     * Nothing has parsed them since Facebook moved to `<meta property>`, and they were the first
     * hundred bytes of every page this framework has ever rendered.
     */
    public function testTheHtmlTagCarriesNoDeadNamespaces(): void
    {
        // Act
        $html = $this->render(static fn (Html $doc) => null);

        // Assert
        $this->assertStringNotContainsString('xmlns:og', $html);
        $this->assertStringNotContainsString('xmlns:fb', $html);
    }
}
