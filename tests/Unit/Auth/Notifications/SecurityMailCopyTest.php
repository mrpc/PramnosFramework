<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Notifications;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Auth\Notifications\NewSignInNotification;
use Pramnos\Auth\Notifications\SecurityChangeNotification;
use Pramnos\Auth\SecurityChangeNotifier;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The mail these two alerts actually send.
 *
 * `via()`, `toPush()` and the account resolution are covered by
 * {@see \Pramnos\Tests\Integration\Auth\SecurityAlertsReachPushTest}; `toMail()` — the copy a
 * person reads, and the longest method in either class — had never been called.
 *
 * Both messages arrive unbidden and describe something that may be an intrusion, so what they
 * must **not** contain is as much of the design as what they say:
 *
 *   - **no link.** An unexpected security email with something to click is the shape of the
 *     attack it warns about. Both say to open the site directly instead, and the assertion is
 *     that they keep saying it rather than growing a convenient button.
 *   - **no IP address** in the sign-in alert. Nobody recognises their own, and a person taught
 *     to compare addresses between mails will worry every few days forever.
 *   - **nothing the caller chose, unescaped.** The `detail` on a security change and the device
 *     description on a sign-in both reach an HTML body.
 *
 * Unit rather than integration: neither method touches a database, a mailer or a request, so
 * anything beyond constructing the object and reading the array back would be scaffolding.
 */
#[CoversClass(NewSignInNotification::class)]
#[CoversClass(SecurityChangeNotification::class)]
class SecurityMailCopyTest extends BaseTestCase
{
    /**
     * 14 February 2026, 09:30 UTC — fixed, and in the past.
     *
     * A fixed past date lets "the mail is dated to the sign-in and not to now" be a plain
     * assertion rather than a conditional one, and being in the past means no future run of the
     * suite can land on the same day and make it vacuous.
     */
    private const WHEN = 1771061400;

    /** A recipient object neither `toMail()` looks at; both take the notifiable and ignore it. */
    private function recipient(): object
    {
        return new class () {
            public int $userid = 41;
        };
    }

    // ── The sign-in alert ─────────────────────────────────────────────────────

    /**
     * It names the browser, the platform and the moment — the moment it was given.
     *
     * The timestamp is a constructor argument because the alert is sent from a queue worker
     * rather than from the request that triggered it. Printing `time()` would date the mail to
     * whenever the worker happened to run, and for "was this you?" the time is the one fact the
     * reader checks against their own memory.
     */
    public function testTheSignInMailNamesTheBrowserAndTheTimeItWasGiven(): void
    {
        // Arrange
        $notification = new NewSignInNotification('firefox|mac', self::WHEN, 'Example');

        // Act
        $mail = $notification->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString('Firefox on Mac', $mail['body']);
        $this->assertStringContainsString(date('j M Y, H:i T', self::WHEN), $mail['body']);
        $this->assertStringNotContainsString(
            date('j M Y'),
            $mail['body'],
            'the mail is dated to when it was sent rather than when the sign-in happened'
        );
        $this->assertStringContainsString('Example', $mail['subject']);
        $this->assertStringContainsString('Firefox on Mac', $mail['subject']);
    }

    /**
     * An unrecognised browser reads as a sentence rather than a gap.
     *
     * The fingerprint comes from a `User-Agent`, and anything can send one. The alternative is a
     * mail reading "signed in to from ****, which this account has not been used from before" —
     * which is how the reader learns the site is broken instead of that their account may be.
     */
    public function testAnUnrecognisedBrowserStillReadsAsASentence(): void
    {
        // Act
        $mail = (new NewSignInNotification('|', self::WHEN, 'Example'))->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString('an unrecognised browser', $mail['body']);
        $this->assertStringNotContainsString('<strong></strong>', $mail['body']);
    }

    /**
     * The device description can only ever be one of a closed set of labels.
     *
     * `SignInFingerprint::describe()` maps to a whitelist, so nothing from the `User-Agent`
     * header reaches the body — which is the reason the mail is safe, and the reason the
     * `htmlspecialchars()` in `toMail()` is defence in depth rather than the defence. Asserted
     * with a fingerprint carrying markup, because it is the day somebody widens `describe()` to
     * pass an unknown label through that this needs to fail.
     */
    public function testMarkupInTheFingerprintCannotReachTheBody(): void
    {
        // Act
        $mail = (new NewSignInNotification('<script>alert(1)</script>|"onload="', self::WHEN, 'X'))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringNotContainsString('<script>', $mail['body']);
        $this->assertStringNotContainsString('onload=', $mail['body']);
        $this->assertStringContainsString('an unrecognised browser', $mail['body']);
    }

    /**
     * No IP address, asserted because it reads like an omission.
     *
     * The address is in the audit log, which is the right place for it: an administrator
     * investigating an incident can act on one. A person reading their own mail cannot.
     */
    public function testTheSignInMailNamesNoAddress(): void
    {
        // Arrange — an address is available in the request while this is built.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        // Act
        $mail = (new NewSignInNotification('chrome|windows', self::WHEN, 'Example'))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringNotContainsString('203.0.113.9', $mail['body']);
        $this->assertDoesNotMatchRegularExpression(
            '~\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b~',
            $mail['body'],
            'the mail prints something shaped like an IP address'
        );

        unset($_SERVER['REMOTE_ADDR']);
    }

    /**
     * It is the one framework notification that belongs to a list.
     *
     * These alerts can be turned off on the account's privacy screen, so there is a real
     * preference for an unsubscribe header to act on. Everything else the framework sends is
     * transactional, and a password reset offering to unsubscribe is an offer to disable the only
     * way back into the account — which is why the security-change alert has no such method.
     */
    public function testTheSignInAlertBelongsToAListAndTheChangeAlertDoesNot(): void
    {
        // Act & Assert
        $this->assertSame('newsignin', (new NewSignInNotification('chrome|mac'))->unsubscribeList());
        $this->assertFalse(
            method_exists(SecurityChangeNotification::class, 'unsubscribeList'),
            'a security change is transactional; it must not offer a way to switch it off'
        );
    }

    /** The fingerprint is readable back, which is how a caller deduplicates alerts. */
    public function testTheFingerprintIsReadableBack(): void
    {
        // Act & Assert
        $this->assertSame('safari|ios', (new NewSignInNotification('safari|ios'))->fingerprint());
    }

    /**
     * Given no timestamp, it dates itself to now rather than to the epoch.
     *
     * `0` is the default so a caller with nothing to pass need not write `time()` at every call
     * site — and `date()` of `0` is "1 Jan 1970", a mail whose one checkable fact is 56 years
     * wrong.
     */
    public function testWithNoTimestampItDatesItselfToNow(): void
    {
        // Act
        $mail = (new NewSignInNotification('chrome|mac', 0, 'Example'))->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString(date('j M Y'), $mail['body']);
        $this->assertStringNotContainsString('1970', $mail['body']);
    }

    // ── The security-change alert ─────────────────────────────────────────────

    /**
     * Every kind of change has a headline in the words the reader would use.
     *
     * Not the constant and not the method name: `FACTOR_ADDED` tells a person nothing about
     * whether to worry. Each is asserted separately because the `match` has one arm per kind, and
     * a copied arm returning the wrong sentence is invisible — the mail still arrives, still
     * reads well, and describes something that did not happen.
     *
     * That the constant itself never reaches the reader is asserted separately, in
     * {@see testAnUnknownKindStillSaysSomething} — several of these constants are ordinary
     * English words that the copy is entitled to use.
     *
     * @param string $kind   One of the notifier's constants
     * @param string $expect A phrase only that kind's headline contains
     */
    #[DataProvider('changeKinds')]
    public function testEachKindOfChangeSaysWhatChanged(string $kind, string $expect): void
    {
        // Act
        $mail = (new SecurityChangeNotification($kind))->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString($expect, $mail['body']);
        $this->assertStringContainsString($expect, $mail['subject']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function changeKinds(): array
    {
        return [
            'password'        => [SecurityChangeNotifier::PASSWORD, 'password was changed'],
            'email'          => [SecurityChangeNotifier::EMAIL, 'email address on your account'],
            'factor added'    => [SecurityChangeNotifier::FACTOR_ADDED, 'second sign-in step was added'],
            'factor removed'  => [SecurityChangeNotifier::FACTOR_REMOVED, 'second sign-in step was removed'],
            'passkey added'   => [SecurityChangeNotifier::PASSKEY_ADDED, 'passkey was added'],
            'passkey removed' => [SecurityChangeNotifier::PASSKEY_REMOVED, 'passkey was removed'],
        ];
    }

    /**
     * A kind nobody has written copy for still says something true.
     *
     * The `default` arm exists so that adding a constant to the notifier without adding a
     * sentence here sends a vague mail rather than an empty one. Vague is recoverable — the
     * reader still knows to look at their account.
     */
    public function testAnUnknownKindStillSaysSomething(): void
    {
        // Act
        $mail = (new SecurityChangeNotification('recovery_codes_regenerated'))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString('security settings were changed', $mail['body']);
        $this->assertStringNotContainsString(
            'recovery_codes_regenerated',
            $mail['body'],
            'the unrecognised constant was printed at the reader'
        );
    }

    /**
     * The detail is escaped, because it is the one part a caller composes.
     *
     * `$detail` is passed by whatever made the change — "TOTP", or the name of a passkey the
     * account holder typed. A passkey called `<img onerror=...>` is a stored string that ends up
     * inside an HTML mail body, and a mail client that renders it is doing what mail clients do.
     */
    public function testTheDetailIsEscaped(): void
    {
        // Act
        $mail = (new SecurityChangeNotification(
            SecurityChangeNotifier::PASSKEY_ADDED,
            '<img src=x onerror="alert(1)">'
        ))->toMail($this->recipient());

        // Assert
        $this->assertStringNotContainsString('<img', $mail['body']);
        $this->assertStringContainsString('&lt;img', $mail['body']);
    }

    /**
     * The copy to a former address explains why it arrived there.
     *
     * The most important mail this class sends: when a stolen session changes the address first,
     * this is the only one that reaches the owner. Without the explanation the reader is being
     * told about an account they now appear to have no connection to — which reads as a
     * misdirected email and gets deleted.
     */
    public function testTheFormerAddressCopyExplainsWhyItArrived(): void
    {
        // Act
        $former = (new SecurityChangeNotification(SecurityChangeNotifier::EMAIL, '', true))
            ->toMail($this->recipient());
        $current = (new SecurityChangeNotification(SecurityChangeNotifier::EMAIL, '', false))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString('no longer uses this email address', $former['body']);
        $this->assertStringNotContainsString(
            'no longer uses this email address',
            $current['body'],
            'the address that still owns the account was told it does not'
        );
    }

    /**
     * Neither mail carries a link, and both still say why.
     *
     * The decision most likely to be undone by somebody being helpful. A "review this sign-in"
     * button is the same thing as the phishing mail the message warns about, only larger and
     * easier to press.
     */
    public function testNeitherMailCarriesALink(): void
    {
        // Arrange — each message words the instruction its own way.
        $messages = [
            'sign-in' => [
                (new NewSignInNotification('chrome|mac', self::WHEN, 'Example'))
                    ->toMail($this->recipient())['body'],
                'rather than following a link in an email',
            ],
            'change'  => [
                (new SecurityChangeNotification(SecurityChangeNotifier::PASSWORD))
                    ->toMail($this->recipient())['body'],
                'not a link in an email',
            ],
        ];

        // Act & Assert
        foreach ($messages as $which => [$body, $instruction]) {
            $this->assertStringNotContainsString('<a ', $body, $which . ' grew a link');
            $this->assertStringNotContainsString('http', $body, $which . ' grew a URL');
            $this->assertStringContainsString(
                $instruction,
                $body,
                $which . ' no longer tells the reader to open the site themselves'
            );
        }
    }
}
