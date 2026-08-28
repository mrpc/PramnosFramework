<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Email\Email;
use Pramnos\Email\Tracking;

/**
 * Opens and clicks, for mail the reader agreed to receive.
 *
 * The framework has had `Email::enableTracking()` for years and it never worked: no migration
 * created the table, no route served the pixel, so the insert failed into a `catch` and the pixel
 * pointed at a 404. This is the missing half — and the half that decides *when* it applies.
 *
 * Two things are asserted more carefully than the rest. That tracking is **off** unless three
 * separate conditions hold, because a pixel in a password reset is a pixel in the most sensitive
 * message a system sends. And that an open and a proxy fetch are counted apart, because adding
 * them together is how a message nobody read is reported at a 70% open rate.
 */
#[CoversClass(Tracking::class)]
#[CoversClass(Email::class)]
class TrackingTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    /** @var array<string, mixed>|null */
    private ?array $savedInstances = null;

    /**
     * Switch the installation-wide setting.
     *
     * Through a stub application registered as the current instance, which is how the rest of
     * this suite configures `applicationInfo` — there is no application in a unit test, and the
     * alternative is a test that skips on the machine it was written for.
     */
    private function setting(?bool $on): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };

        $stub->applicationInfo = $on === null ? [] : ['email' => ['tracking' => $on]];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');

        if ($this->savedInstances === null) {
            $this->savedInstances = $reflection->getValue() ?? [];
        }

        $instances = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    // ── the three gates ──────────────────────────────────────────────────────

    /**
     * Absent configuration means off.
     *
     * Not "off unless someone turned it on somewhere" — a project that says nothing about
     * tracking is a project that does not track.
     */
    public function testAbsentConfigurationMeansOff(): void
    {
        // Arrange
        $this->setting(null);

        // Assert
        $this->assertFalse(Tracking::enabled());
        $this->assertFalse(Tracking::allowed('newsletter'));
    }

    /**
     * Transactional mail is never tracked, whatever the setting says.
     *
     * The gate that matters most. A message with no list is a password reset or a second-factor
     * code; nobody consented to anything, and those are the messages where a remote image is
     * least acceptable.
     */
    public function testTransactionalMailIsNeverTracked(): void
    {
        // Arrange — tracking on, installation-wide
        $this->setting(true);

        // Assert
        $this->assertTrue(Tracking::enabled());
        $this->assertFalse(Tracking::allowed(''), 'no list means no consent');
        $this->assertFalse(Tracking::allowed('   '));
        $this->assertTrue(Tracking::allowed('newsletter'));
    }

    /**
     * Asking on the message is the third gate, and it is not enough on its own.
     */
    public function testAskingIsNotEnough(): void
    {
        // Arrange — the caller asks, the installation says no
        $this->setting(false);

        $mail = new class extends Email {
            public function apply(string $body): string
            {
                return $this->applyTracking($body);
            }
        };
        $mail->enableTracking();
        $mail->unsubscribeList = 'newsletter';

        // Act
        $body = $mail->apply('<p>Hello.</p>');

        // Assert
        $this->assertSame('<p>Hello.</p>', $body, 'no pixel, no wrapped links, nothing');
        // The id still exists — it is generated when tracking is *asked for*, so an application
        // may store it beside its own record. What the gates decide is whether anything is
        // measured, not whether the caller gets an identifier.
        $this->assertNotSame('', $mail->trackingId);
    }

    /**
     * A message that did not ask is untouched even when everything else is on.
     */
    public function testAMessageThatDidNotAskIsUntouched(): void
    {
        // Arrange
        $this->setting(true);

        $mail = new class extends Email {
            public function apply(string $body): string
            {
                return $this->applyTracking($body);
            }
        };
        $mail->unsubscribeList = 'newsletter';

        // Act & Assert
        $this->assertSame('<p>Hello.</p>', $mail->apply('<p>Hello.</p>'));
    }

    // ── the pixel ────────────────────────────────────────────────────────────

    /**
     * The pixel is invisible whether or not images load.
     *
     * An empty `alt` and a 1×1 size, so a client with images blocked shows nothing rather than a
     * broken-image icon in the middle of the message.
     */
    public function testThePixelIsInvisibleEitherWay(): void
    {
        // Act
        $pixel = Tracking::pixel('abc123');

        // Assert
        $this->assertStringContainsString('alt=""', $pixel);
        $this->assertStringContainsString('width="1" height="1"', $pixel);
        $this->assertStringContainsString(Tracking::PIXEL_PATH . '?t=abc123', $pixel);
        $this->assertSame('', Tracking::pixel(''), 'nothing to track, nothing to embed');
    }

    // ── proxies ──────────────────────────────────────────────────────────────

    /**
     * A mailbox provider's fetch is not a person.
     *
     * Apple Mail Privacy Protection fetches every remote image on delivery, whether or not
     * anybody ever opens the message. Counting those as opens reports an open for every Apple
     * recipient, minutes after sending — which is most of a consumer list.
     */
    public function testAProviderFetchIsRecognised(): void
    {
        // Assert — by user agent
        $this->assertTrue(Tracking::looksLikeAProxy('Mozilla/5.0 GoogleImageProxy', ''));
        $this->assertTrue(Tracking::looksLikeAProxy('YahooMailProxy/1.0', ''));

        // …and by network, which is how Apple's fetches are caught: they identify as Safari
        $this->assertTrue(Tracking::looksLikeAProxy('Mozilla/5.0 (Macintosh) Safari/605', '17.58.1.2'));
        $this->assertTrue(Tracking::looksLikeAProxy('', '66.249.84.1'));

        // A person
        $this->assertFalse(
            Tracking::looksLikeAProxy('Mozilla/5.0 (iPhone) Safari/604.1', '85.72.1.2')
        );
    }

    // ── links ────────────────────────────────────────────────────────────────

    /**
     * The destination lives inside the signed token, not in the URL.
     *
     * A tracker that reads its destination from a query parameter is an open redirect — and an
     * open redirect on a domain that sends mail is a phishing kit somebody else gets to use: the
     * link comes from your domain, in a message that looks like yours, and lands wherever the
     * attacker chose.
     */
    public function testTheDestinationIsSignedRatherThanPassed(): void
    {
        // Act
        $link = Tracking::link('abc123', 'https://example.com/offer?id=9');

        // Assert
        $this->assertStringContainsString(Tracking::CLICK_PATH . '?c=', $link);
        $this->assertStringNotContainsString('example.com', $link, 'not in the URL');

        // …and it comes back out only through the signature
        $this->assertSame(
            'https://example.com/offer?id=9',
            \Pramnos\Email\MailAction::verify(
                urldecode(explode('c=', $link)[1])
            )['claim']['u']
        );
    }

    /**
     * An edited token yields no destination at all.
     */
    public function testAnEditedLinkGoesNowhere(): void
    {
        // Arrange
        $link  = Tracking::link('abc123', 'https://example.com/offer');
        $token = urldecode(explode('c=', $link)[1]);

        // Act — flip a character
        $edited = substr_replace($token, $token[10] === 'a' ? 'b' : 'a', 10, 1);

        // Assert
        $this->assertSame('', Tracking::recordClick($edited));
        $this->assertSame('', Tracking::recordClick('not-a-token'));
    }

    /**
     * A token for a different action is not a click.
     *
     * The click token is signed by the same machinery as the one-click mail actions, so the
     * action name is what keeps them apart. Without that check, a `revoke-sessions` token would
     * be accepted as a redirect — and its payload has no URL in it, but the principle is the
     * point.
     */
    public function testATokenForAnotherActionIsRefused(): void
    {
        // Arrange
        $token = \Pramnos\Email\MailAction::token('revoke-sessions', ['user' => 2]);

        // Assert
        $this->assertSame('', Tracking::recordClick($token));
    }

    /**
     * A destination that is not http(s) is refused.
     *
     * `javascript:` in a redirect is the other half of the open-redirect problem, and the token
     * is signed by *us* — so this guards against our own builder being handed one.
     */
    public function testANonHttpDestinationIsRefused(): void
    {
        // Arrange
        $token = \Pramnos\Email\MailAction::token('click', [
            't' => 'abc',
            'u' => 'javascript:alert(1)',
        ]);

        // Assert
        $this->assertSame('', Tracking::recordClick($token));
    }

    /**
     * Every link is wrapped — except the unsubscribe one.
     *
     * Not an oversight. A reader unsubscribing is exercising a right, and routing that through a
     * tracker is both distasteful and a way to break the one link a mailbox provider tests.
     */
    public function testTheUnsubscribeLinkIsLeftAlone(): void
    {
        // Arrange
        $unsubscribe = 'https://example.com/unsubscribe?u=xyz';
        $html = '<p><a href="https://example.com/offer">Offer</a> '
            . '<a href="' . $unsubscribe . '">Unsubscribe</a> '
            . '<a href="mailto:help@example.com">Help</a> '
            . '<a href="#top">Top</a></p>';

        // Act
        $wrapped = Tracking::wrapLinks($html, 'abc123', $unsubscribe);

        // Assert
        $this->assertStringContainsString('href="' . $unsubscribe . '"', $wrapped);
        $this->assertStringContainsString('mailto:help@example.com', $wrapped);
        $this->assertStringContainsString('href="#top"', $wrapped);
        $this->assertStringContainsString(Tracking::CLICK_PATH, $wrapped);
        $this->assertStringNotContainsString('href="https://example.com/offer"', $wrapped);
    }

    /**
     * Wrapping twice does not double-wrap.
     *
     * A body can be rendered more than once — a preview, a resend from the outbox — and a link
     * wrapped around a wrapped link records one click and loses the destination.
     */
    public function testWrappingIsNotAppliedTwice(): void
    {
        // Arrange
        $once = Tracking::wrapLinks('<a href="https://example.com/x">x</a>', 'abc123');

        // Act
        $twice = Tracking::wrapLinks($once, 'abc123');

        // Assert
        $this->assertSame($once, $twice);
    }

    /**
     * Nothing to track means nothing is changed.
     */
    public function testWithoutATrackingIdNothingIsRewritten(): void
    {
        // Arrange
        $html = '<a href="https://example.com/x">x</a>';

        // Assert
        $this->assertSame($html, Tracking::wrapLinks($html, ''));
        $this->assertSame('https://example.com/x', Tracking::link('', 'https://example.com/x'));
    }
}
