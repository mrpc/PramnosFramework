<?php

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;

/**
 * Unit tests for Document::$robots / setRobots() — the home the design gives
 * a page for its indexing decision (docs/Hreflang-And-Sitemap-Design.md §1),
 * so that one value drives both the <meta name="robots"> tag and the
 * X-Robots-Tag header instead of the two being able to disagree.
 */
#[CoversClass(Document::class)]
class DocumentRobotsTest extends TestCase
{
    protected $document;

    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://example.com/');
        }

        $this->document = new class extends Document {
            public function render() { return ''; }
        };
        $this->document->type = 'html';
    }

    /**
     * A page that never calls setRobots() has a null $robots and emits no
     * <meta name="robots"> tag at all — absence already means indexable, so
     * nothing should be stated.
     */
    public function testNoMetaTagByDefault(): void
    {
        // Assert
        $this->assertNull($this->document->robots);
        $this->assertSame('', $this->renderRobots());
    }

    /**
     * setRobots() is chainable and the directive reaches the rendered tag
     * unchanged.
     */
    public function testSetRobotsEmitsTheDirectiveInTheMetaTag(): void
    {
        // Act
        $returned = $this->document->setRobots('noindex, nofollow');

        // Assert — chainable
        $this->assertSame($this->document, $returned);
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow" />',
            $this->renderRobots()
        );
    }

    /**
     * A directive containing a double quote is escaped the same way every
     * other head value is — confirms robotsHeadMarkup() routes through the
     * shared escaper rather than concatenating a caller's value raw.
     */
    public function testDirectiveIsEscaped(): void
    {
        // Arrange
        $this->document->setRobots('noindex"><script>alert(1)</script>');

        // Act
        $markup = $this->renderRobots();

        // Assert
        $this->assertStringNotContainsString('<script>', $markup);
    }

    /**
     * setRobots('') — an empty directive — is treated the same as never
     * having called it: no tag, rather than a `content=""` claim that this
     * page made a decision about indexing with nothing in it.
     */
    public function testBlankDirectiveEmitsNoTag(): void
    {
        // Act
        $this->document->setRobots('');

        // Assert
        $this->assertSame('', $this->renderRobots());
    }

    /**
     * End to end through Html::render(), in the same process as every
     * other test here: the <meta> tag reaches the rendered page. Not
     * isolated, unlike the header check below, so this runs — and counts
     * toward coverage — regardless of whether an earlier test already
     * flipped the process-wide headers_sent() flag.
     */
    public function testHtmlRenderEmitsTheMetaTag(): void
    {
        // Arrange
        \Pramnos\Framework\Factory::getLanguage()->addlang(['CHARSET' => 'UTF-8']);
        Document::_setContent('');
        $html = new \Pramnos\Document\DocumentTypes\Html();
        $html->title  = 'Standalone page';
        $html->robots = 'noindex, nofollow';

        // Act
        $output = $html->render();

        // Cleanup
        Document::_setContent('');

        // Assert
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow" />',
            $output
        );
    }

    /**
     * End to end through Html::render(): the <meta> tag this class builds
     * actually reaches the rendered page, and the matching X-Robots-Tag
     * header — sent earlier in render(), from the same $robots value — is
     * actually sent, not only that the line executes without error.
     *
     * Runs in its own process: headers_sent() is true for the rest of a
     * PHPUnit process once any earlier test has produced output, which
     * would make the header-sending branch untestable here otherwise.
     */
    #[RunInSeparateProcess]
    public function testHtmlRenderEmitsTheMetaTagAndTheHeaderFromTheSameProperty(): void
    {
        // Arrange
        \Pramnos\Framework\Factory::getLanguage()->addlang(['CHARSET' => 'UTF-8']);
        Document::_setContent('');
        $html = new \Pramnos\Document\DocumentTypes\Html();
        $html->title  = 'Standalone page';
        $html->robots = 'noindex, nofollow';

        // Act
        $output = $html->render();

        // Cleanup
        Document::_setContent('');

        // Assert — the <meta> tag reached the body
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow" />',
            $output
        );

        // Assert — the header was actually sent, not only built. xdebug_get_headers()
        // only captures anything when xdebug.mode includes 'develop'; ./dockertest
        // --nocoverage runs with it off (that is the point of --nocoverage), where the
        // function exists but always returns []. Nothing captured at all means this
        // environment cannot answer the question, rather than that the header was
        // missing, so the extra check only asserts where it actually can.
        $headers = function_exists('xdebug_get_headers') ? xdebug_get_headers() : [];
        if ($headers !== []) {
            $this->assertNotEmpty(
                array_filter($headers, fn (string $h): bool => stripos($h, 'X-Robots-Tag: noindex, nofollow') === 0),
                'X-Robots-Tag header must be sent from the same $robots value as the <meta> tag.'
            );
        }
    }

    /**
     * Invokes the protected robotsHeadMarkup() method directly, the same
     * pattern used elsewhere in this suite for protected head-rendering
     * methods.
     */
    private function renderRobots(): string
    {
        return (string) (new \ReflectionMethod(Document::class, 'robotsHeadMarkup'))
            ->invoke($this->document);
    }
}
