<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Emailclick;
use Pramnos\Application\Controllers\Emailpixel;
use Pramnos\Application\Controllers\EmailsController;

/**
 * The two public endpoints tracking needs, and the column that reports what they collected.
 *
 * Bundled controllers, so an application that switches tracking on has no route to write. The
 * previous version of this feature asked for both the route and the table in a doc-block, which
 * is precisely why it never worked anywhere.
 */
#[CoversClass(Emailpixel::class)]
#[CoversClass(Emailclick::class)]
#[CoversClass(EmailsController::class)]
class TrackingControllersTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];

        parent::tearDown();
    }

    /**
     * The pixel answers with an image whatever happened behind it.
     *
     * An unknown id, a database that is away, a message that was never tracked — none of them is
     * the reader's problem, and a broken image in the middle of a message is a worse outcome than
     * a lost measurement.
     */
    public function testThePixelAlwaysAnswersWithAnImage(): void
    {
        // Arrange
        $_GET['t'] = $_REQUEST['t'] = 'an-id-that-does-not-exist';
        \Pramnos\Http\Request::create('emailpixel', 'GET');

        // Act
        ob_start();
        (new Emailpixel())->display();
        $body = (string) ob_get_clean();

        // Assert — the GIF header, and nothing else
        $this->assertStringStartsWith('GIF89a', $body);
        $this->assertLessThan(64, strlen($body), 'a 1x1 GIF, not a page');
    }

    /**
     * The pixel is a single action, and public.
     *
     * A login requirement here would break every message: the fetch comes from a mail client, or
     * from a provider's proxy, with no session at all.
     */
    public function testThePixelEndpointIsPublic(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Application/Controllers/Emailpixel.php'
        );

        // Assert
        $this->assertSame(['display'], array_values(array_unique((new Emailpixel())->actions)));

        foreach (['requireLogin', 'requireMinUserType'] as $guard) {
            $this->assertStringNotContainsString($guard, $source);
        }

        // Self-contained, or the site's layout follows the image bytes
        $this->assertStringContainsString("getDocument('raw')", $source);
    }

    /**
     * A link that does not verify goes to the front page, not to a redirect.
     *
     * There is nowhere safe to send somebody holding an unreadable token: the destination is the
     * one thing the signature protects. The site's own front page is a place they meant to reach
     * and is not somewhere an attacker chose.
     */
    public function testAnUnreadableLinkDoesNotRedirect(): void
    {
        // Arrange
        $_GET['c'] = $_REQUEST['c'] = 'not-a-real-token';
        \Pramnos\Http\Request::create('emailclick', 'GET');

        // Act
        ob_start();
        (new Emailclick())->display();
        $body = (string) ob_get_clean();

        // Assert
        $this->assertStringContainsString('This link could not be read', $body);
        $this->assertStringContainsString('Go to the site', $body);
        $this->assertStringNotContainsString('<meta http-equiv="refresh"', $body);
    }

    /**
     * A valid link redirects to exactly the destination that was signed.
     *
     * The one question this controller has to answer correctly. The destination is inside the
     * token, so this also confirms it survives the round trip intact — query string and all,
     * which is where a naive implementation loses it.
     */
    public function testAValidLinkRedirectsToTheSignedDestination(): void
    {
        // Arrange
        $destination = 'https://example.com/offer?id=9&utm=mail';
        $token = urldecode(explode(
            'c=',
            \Pramnos\Email\Tracking::link('some-id', $destination)
        )[1]);

        $_GET['c'] = $_REQUEST['c'] = $token;
        \Pramnos\Http\Request::create('emailclick', 'GET');

        $probe = new class extends Emailclick {
            public string $sentTo = '';

            protected function sendTo(string $destination): void
            {
                $this->sentTo = $destination;
            }
        };

        // Act
        ob_start();
        $probe->display();
        $body = (string) ob_get_clean();

        // Assert
        $this->assertSame($destination, $probe->sentTo);
        $this->assertSame('', $body, 'a redirect has no body');
    }

    /**
     * Sending them on is a no-op once the response has started.
     *
     * The pixel and the click share a request lifecycle with whatever the framework has already
     * printed; a `header()` after output is a warning in the log and nothing else, so the
     * condition is checked rather than the warning being tolerated.
     */
    public function testTheRedirectIsSkippedOnceOutputHasStarted(): void
    {
        // Arrange
        $probe = new class extends Emailclick {
            public function send(string $destination): void
            {
                $this->sendTo($destination);
            }
        };

        // Act — with a buffer open, `headers_sent()` is still false, so this exercises the
        // real path; the assertion is that it neither throws nor prints.
        ob_start();
        $probe->send('https://example.com/x');
        $printed = (string) ob_get_clean();

        // Assert
        $this->assertSame('', $printed, 'a redirect writes headers, not a body');
    }

    /**
     * The redirect is temporary on purpose.
     *
     * A 301 would be cached by the browser, and the second click on the same link would never
     * reach us to be counted — which is the entire job.
     */
    public function testTheRedirectIsNotPermanent(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Application/Controllers/Emailclick.php'
        );

        // Assert
        $this->assertStringContainsString('http_response_code(302)', $source);
        $this->assertStringNotContainsString('http_response_code(301)', $source);
        $this->assertStringContainsString('Referrer-Policy: no-referrer', $source);
    }

    // ── the administration column ────────────────────────────────────────────

    /** The controller with its database lookup replaced by a given row. */
    private function screen(?array $row): EmailsController
    {
        return new class ($row) extends EmailsController {
            public function __construct(private ?array $row)
            {
            }

            protected function trackingFor(int $mailId): ?array
            {
                return $this->row;
            }

            public function cell(int $mailId): string
            {
                return $this->trackingCell($mailId);
            }
        };
    }

    /**
     * An untracked message shows a dash, not a zero.
     *
     * "0 opens" is a measurement. A dash is the honest rendering of "nobody measured this", and
     * most messages are not tracked.
     */
    public function testAnUntrackedMessageShowsADash(): void
    {
        // Act
        $cell = $this->screen(null)->cell(7);

        // Assert
        $this->assertStringContainsString('—', $cell);
        $this->assertStringContainsString('not tracked', $cell);
        $this->assertStringNotContainsString('0 opened', $cell);
    }

    /**
     * Prefetches are shown apart from opens, and never added to them.
     *
     * The whole point of the two columns. A message with one real open and forty Apple
     * prefetches must not read as forty-one.
     */
    public function testPrefetchesAreShownApartFromOpens(): void
    {
        // Act
        $cell = $this->screen([
            'opens' => 1, 'proxy_opens' => 40, 'clicks' => 0,
        ])->cell(7);

        // Assert
        $this->assertStringContainsString('1 opened', $cell);
        $this->assertStringContainsString('40 prefetched', $cell);
        $this->assertStringNotContainsString('41', $cell);
        $this->assertStringContainsString('Not a reader', $cell, 'the tooltip says why');
    }

    /**
     * Clicks come first, because a click is the only one of the three that is a person.
     */
    public function testClicksAreShownFirst(): void
    {
        // Act
        $cell = $this->screen([
            'opens' => 3, 'proxy_opens' => 2, 'clicks' => 1,
        ])->cell(7);

        // Assert
        $this->assertLessThan(
            strpos($cell, 'opened'),
            strpos($cell, 'clicked'),
            'the number worth reading is the first one on the row'
        );
    }

    /**
     * A tracked message with nothing back says so, rather than showing an empty cell.
     *
     * "Tracked and silent" and "not tracked" are different facts, and an empty cell would be
     * read as the second.
     */
    public function testATrackedMessageWithNothingBackSaysSo(): void
    {
        // Act
        $cell = $this->screen(['opens' => 0, 'proxy_opens' => 0, 'clicks' => 0])->cell(7);

        // Assert
        $this->assertStringContainsString('tracked', $cell);
        $this->assertStringNotContainsString('not tracked', $cell);
    }

    /**
     * Every column on the emails screen is visible.
     *
     * `addColumn`'s second argument is `bVisible`, and it goes straight into DataTables' column
     * config. Two columns on this screen had it `false`: the new Opens column, which was added
     * and never appeared, and the actions column beside it — the view and resend icons — which
     * had been invisible since it was written. Nothing about the code reads as "hidden"; the
     * argument is a bare `false` in a row of them.
     */
    public function testEveryColumnOnTheEmailsScreenIsVisible(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Application/Controllers/EmailsController.php'
        );

        $calls = [];
        preg_match_all("~addColumn\(\s*'([^']*)'\s*,\s*(true|false)~", $source, $calls, PREG_SET_ORDER);

        // Assert
        $this->assertNotEmpty($calls, 'the columns are declared with literal arguments');

        foreach ($calls as [, $label, $visible]) {
            $this->assertSame(
                'true',
                $visible,
                'the "' . $label . '" column is declared hidden'
            );
        }
    }
}
