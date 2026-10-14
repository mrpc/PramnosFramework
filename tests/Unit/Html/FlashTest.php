<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Flash;
use Pramnos\Http\Request;

/**
 * The flash, shown once, above every screen.
 *
 * Admin screens say "Saved." and redirect to a list that knows nothing about the save; without
 * a place that shows the flash, the message was written, carried across the redirect, and never
 * seen. What matters is that it shows exactly once — not again on the next page, and not twice
 * on a page whose view already showed it — and that what somebody typed into it is escaped.
 */
#[CoversClass(Flash::class)]
class FlashTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $_SESSION = [];
        $this->resetCapture();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        $this->resetCapture();
    }

    /** The request's capture back to "not yet read", as at the start of a request. */
    private function resetCapture(): void
    {
        foreach (['validationErrors', 'oldInput', 'flashMessages', 'flashErrors'] as $name) {
            (new \ReflectionProperty(Request::class, $name))->setValue(null, null);
        }
    }

    /**
     * What the previous request flashed is shown — errors first, escaped, each with its role —
     * and taken: a second render in the same request shows nothing.
     */
    public function testTheFlashIsShownOnceAndEscaped(): void
    {
        // Arrange — what addMessage() and addError() leave for the next request
        $_SESSION['_messages'] = ['Saved <b>Anna</b>.'];
        $_SESSION['_errors']   = ['Could not send.'];

        // Act
        $first  = Flash::render();
        $second = Flash::render();

        // Assert
        $this->assertSame(
            '<div class="pf-flash"><p class="pf-flash-error" role="alert">Could not send.</p>'
            . '<p class="pf-flash-message" role="status">Saved &lt;b&gt;Anna&lt;/b&gt;.</p></div>',
            $first
        );
        $this->assertSame('', $second);
    }

    /** Nothing flashed is nothing drawn — not an empty box a stylesheet puts margins around. */
    public function testNothingFlashedIsNothing(): void
    {
        // Act / Assert
        $this->assertSame('', Flash::render());
    }

    /**
     * A view that showed the flash itself took it: the layout, which renders after the view,
     * repeats nothing.
     */
    public function testAViewThatShowedTheFlashLeavesNothingToRepeat(): void
    {
        // Arrange
        $_SESSION['_messages'] = ['Saved.'];
        $shownByTheView = Request::getInstance()->takeMessages();

        // Act
        $layout = Flash::render();

        // Assert
        $this->assertSame(['Saved.'], $shownByTheView);
        $this->assertSame('', $layout);
    }

    /**
     * A message added during this request, without a redirect, is shown on this page and taken
     * out of the session — not shown again, out of place, on the next one.
     */
    public function testAMessageOfThisRequestIsShownNowNotNext(): void
    {
        // Arrange — the capture has run (nothing was flashed), then the controller adds one
        Request::getInstance()->messages();
        $_SESSION['_messages'] = ['Nothing changed.'];
        $_SESSION['_errors']   = 'not a list';

        // Act
        $html = Flash::render();

        // Assert
        $this->assertStringContainsString('<p class="pf-flash-message" role="status">Nothing changed.</p>', $html);
        $this->assertArrayNotHasKey('_messages', $_SESSION);
        $this->assertSame('not a list', $_SESSION['_errors'], 'what is not a list of messages is left alone');
    }

    /** Every scaffolded layout shows the flash above the screen, and every stylesheet styles it. */
    public function testEveryScaffoldedLayoutShowsIt(): void
    {
        // Arrange
        $themes = glob(ROOT . '/scaffolding/themes/*', GLOB_ONLYDIR) ?: [];
        $this->assertNotEmpty($themes);

        foreach ($themes as $theme) {
            // Act
            $layout = (string) file_get_contents($theme . '/theme.html.php');
            $css    = \Pramnos\Tests\Support\ThemeStylesheet::read($theme);

            // Assert — before [MODULE], so it sits above the screen
            $this->assertMatchesRegularExpression('/Flash::render\(\);.*\[MODULE\]/s', $layout, basename($theme));
            foreach (['.pf-flash {', '.pf-flash-message {', '.pf-flash-error {'] as $rule) {
                $this->assertStringContainsString($rule, $css, basename($theme) . ' ' . $rule);
            }
        }
    }
}
