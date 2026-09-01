<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Notifications;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Notifications\NewDeviceAuthLinkNotification;
use Pramnos\Auth\Notifications\PlainAddress;
use Pramnos\Auth\Notifications\SecondFactorCodeNotification;

/**
 * The two authentication mails a person is *waiting* for — both at 0%, never executed.
 *
 * These are the counterpart to the alerts covered earlier: those arrive unbidden and therefore
 * carry no link, while these are the reply to something the reader did seconds ago and are looking
 * at a form for. That difference is the whole design, and it is asserted here from both sides:
 *
 *   - the **code** mail carries six digits and nothing to click, because a code has to be typed
 *     into a page the person already has open — so it is useless to whoever else reads the mailbox
 *     unless they also have the password;
 *   - the **link** mail does carry one, and therefore has to make its own context obvious. If it
 *     did not, it would train the reader to click links in authentication mail they did *not*
 *     expect, which is the attack the alert refuses to participate in.
 *
 * Both say how long they last, because somebody reading twenty minutes later needs to know why it
 * stopped working rather than concluding the site is broken. Both say what to do if it was not
 * them — the only signal available that somebody else has their password.
 *
 * `PlainAddress` is here too: the notifiable that is nothing but an address, for the one mail that
 * must reach somewhere the account no longer points at.
 */
#[CoversClass(SecondFactorCodeNotification::class)]
#[CoversClass(NewDeviceAuthLinkNotification::class)]
#[CoversClass(PlainAddress::class)]
class AuthMailCopyTest extends TestCase
{
    /** A recipient neither mail looks at; both take the notifiable and ignore it. */
    private function recipient(): object
    {
        return new class () {
            public int $userid = 41;

            public string $email = 'somebody@example.test';
        };
    }

    // ── The code mail ─────────────────────────────────────────────────────────

    /**
     * The code is in the body **and** in the subject.
     *
     * On a phone the subject line is often all the reader sees before switching back to the form,
     * so putting it there saves opening the mail at all. It is the one thing they are looking for.
     */
    public function testTheCodeIsInTheBodyAndTheSubject(): void
    {
        // Act
        $mail = (new SecondFactorCodeNotification('123456', 600, 'Example'))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString('123456', $mail['body']);
        $this->assertStringContainsString('123456', $mail['subject'], 'not visible without opening');
        $this->assertStringContainsString('Example', $mail['subject']);
    }

    /**
     * It carries nothing to click.
     *
     * The rule this mail shares with the sign-in alert, for a sharper reason: a link that signs
     * somebody in is the most valuable thing an attacker can have forwarded. A code cannot be used
     * by whoever else reads the mailbox unless they also hold the password.
     */
    public function testTheCodeMailCarriesNothingToClick(): void
    {
        // Act
        $mail = (new SecondFactorCodeNotification('123456', 600, 'Example'))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringNotContainsString('<a ', $mail['body'], 'the code mail grew a link');
        $this->assertStringNotContainsString('http', $mail['body'], 'the code mail grew a URL');
        $this->assertStringContainsString(
            'rather than following a link in an email',
            $mail['body'],
            'it no longer tells the reader to open the site themselves'
        );
    }

    /**
     * It says how long the code lasts, in minutes, and gets the singular right.
     *
     * A reader who comes back after the code expired needs to know that is what happened. "Expires
     * in 1 minutes" is the kind of thing that makes a person doubt the rest of the message.
     */
    public function testItSaysHowLongTheCodeLastsWithTheRightPlural(): void
    {
        // Act & Assert
        $ten = (new SecondFactorCodeNotification('123456', 600))->toMail($this->recipient());
        $this->assertStringContainsString('10 minutes', $ten['body']);

        $one = (new SecondFactorCodeNotification('123456', 60))->toMail($this->recipient());
        $this->assertStringContainsString('one minute', $one['body']);
        $this->assertStringNotContainsString('1 minutes', $one['body']);

        // Under a minute rounds up rather than saying zero.
        $short = (new SecondFactorCodeNotification('123456', 20))->toMail($this->recipient());
        $this->assertStringContainsString('one minute', $short['body'], 'a TTL under a minute said 0');
    }

    /**
     * A nonsensical lifetime falls back to the default rather than promising nothing.
     *
     * `0` or a negative reaches this from a misconfigured setting. "Expires in 0 minutes" is a mail
     * that tells the reader the code is already dead, about a code that works.
     */
    public function testANonsensicalLifetimeFallsBack(): void
    {
        // Act & Assert
        foreach ([0, -60] as $ttl) {
            $mail = (new SecondFactorCodeNotification('123456', $ttl))->toMail($this->recipient());

            $this->assertStringContainsString('10 minutes', $mail['body'], 'ttl ' . $ttl);
        }
    }

    /**
     * The code is escaped on its way into the HTML.
     *
     * It is generated six digits, so nothing hostile can reach here today — and this object's
     * whole purpose is to carry a value into a mail body, which is the place where trusting the
     * caller stops being free.
     */
    public function testTheCodeIsEscaped(): void
    {
        // Act
        $mail = (new SecondFactorCodeNotification('<script>alert(1)</script>'))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringNotContainsString('<script>', $mail['body']);
        $this->assertStringContainsString('&lt;script&gt;', $mail['body']);
    }

    /** Mail only: a database notification would show the code to the half-finished session. */
    public function testTheCodeGoesByMailAlone(): void
    {
        // Act & Assert
        $this->assertSame(
            ['mail'],
            (new SecondFactorCodeNotification('123456'))->via($this->recipient()),
            'the code would appear in the panel of the session the factor exists to stop'
        );
    }

    /** The code is readable back, so a test can assert what was sent without parsing HTML. */
    public function testTheCodeIsReadableBack(): void
    {
        // Act & Assert
        $this->assertSame('123456', (new SecondFactorCodeNotification('123456'))->code());
    }

    // ── The link mail ─────────────────────────────────────────────────────────

    /**
     * It carries the link, and says which device asked for it.
     *
     * The device is the context that makes the link safe to offer: a reader who recognises "Firefox
     * on Windows" as themselves is not being trained to click anything that arrives — and one who
     * does not recognise it has been told, in the only mail that could tell them.
     */
    public function testTheLinkMailCarriesTheLinkAndNamesTheDevice(): void
    {
        // Act
        $mail = (new NewDeviceAuthLinkNotification(
            'https://example.test/auth/abc123',
            900,
            'Firefox on Windows',
            'Example'
        ))->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString('https://example.test/auth/abc123', $mail['body']);
        $this->assertStringContainsString('<a href="https://example.test/auth/abc123"', $mail['body']);
        $this->assertStringContainsString('Firefox on Windows', $mail['body']);
        $this->assertStringContainsString('Firefox on Windows', $mail['subject']);
    }

    /**
     * It says the sign-in cannot continue without the link.
     *
     * The instruction for the reader who was not signing in, and it is "do nothing" — which is
     * both the correct action and reassuring, because the alternative reading of an unexpected
     * sign-in mail is panic. And then the part that matters: somebody has the password.
     */
    public function testItSaysDoingNothingIsEnoughAndThatThePasswordIsKnown(): void
    {
        // Act
        $mail = (new NewDeviceAuthLinkNotification('https://example.test/auth/abc', 900))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringContainsString('do nothing', $mail['body']);
        $this->assertStringContainsString(
            'somebody has your password',
            $mail['body'],
            'the reader is not told the thing that matters'
        );
        $this->assertStringContainsString('change it', $mail['body']);
    }

    /** It works once, and says so, with the same singular care. */
    public function testItSaysItWorksOnceAndForHowLong(): void
    {
        // Act & Assert
        $long = (new NewDeviceAuthLinkNotification('https://example.test/a', 900))
            ->toMail($this->recipient());
        $this->assertStringContainsString('works once', $long['body']);
        $this->assertStringContainsString('15 minutes', $long['body']);

        $short = (new NewDeviceAuthLinkNotification('https://example.test/a', 60))
            ->toMail($this->recipient());
        $this->assertStringContainsString('one minute', $short['body']);
        $this->assertStringNotContainsString('1 minutes', $short['body']);
    }

    /**
     * The URL is escaped into both the attribute and the visible text.
     *
     * It is shown as well as linked, so a reader can see where it goes — which is the point of
     * printing it — and that means it lands in the document twice. A single-use auth URL carries a
     * token, and a token with a quote in it would end the attribute.
     */
    public function testTheUrlIsEscapedWhereverItAppears(): void
    {
        // Act
        $mail = (new NewDeviceAuthLinkNotification('https://example.test/a?t=x"><b>bold'))
            ->toMail($this->recipient());

        // Assert
        $this->assertStringNotContainsString('"><b>bold', $mail['body'], 'the URL broke out');
        $this->assertStringContainsString('&quot;', $mail['body']);
    }

    /** The device description is escaped too — it is derived from a `User-Agent`. */
    public function testTheDeviceDescriptionIsEscaped(): void
    {
        // Act
        $mail = (new NewDeviceAuthLinkNotification(
            'https://example.test/a',
            900,
            '<script>alert(1)</script>'
        ))->toMail($this->recipient());

        // Assert
        $this->assertStringNotContainsString('<script>', $mail['body']);
    }

    /**
     * With no device given, it describes the current request's own.
     *
     * The service that sends this knows the fingerprint; a caller that does not should still get a
     * sentence rather than "signed in from <strong></strong>", which reads as a broken template.
     */
    public function testWithNoDeviceItDescribesTheCurrentOne(): void
    {
        // Arrange
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36';

        try {
            // Act
            $mail = (new NewDeviceAuthLinkNotification('https://example.test/a'))
                ->toMail($this->recipient());

            // Assert
            $this->assertStringNotContainsString('<strong></strong>', $mail['body']);
            $this->assertStringContainsString('Chrome', $mail['body']);
        } finally {
            unset($_SERVER['HTTP_USER_AGENT']);
        }
    }

    /** Mail only, for the same reason as the code. */
    public function testTheLinkGoesByMailAlone(): void
    {
        // Act & Assert
        $this->assertSame(
            ['mail'],
            (new NewDeviceAuthLinkNotification('https://example.test/a'))->via($this->recipient())
        );
    }

    /** And the URL is readable back. */
    public function testTheUrlIsReadableBack(): void
    {
        // Act & Assert
        $this->assertSame(
            'https://example.test/a',
            (new NewDeviceAuthLinkNotification('https://example.test/a'))->url()
        );
    }

    // ── The notifiable that is only an address ────────────────────────────────

    /**
     * `PlainAddress` routes mail and nothing else.
     *
     * It exists for the one notification that must reach somewhere the account no longer points
     * at: telling the *previous* address that the account's email was changed. When a stolen
     * session changes the address first, that mail is the only signal the real owner gets — so it
     * cannot be routed through the user object, because the user object now points at the
     * attacker's mailbox.
     *
     * Everything other than mail answers null, because there is no account behind it: a database
     * notification would need a user id, and a broadcast would need a channel.
     */
    public function testAPlainAddressRoutesMailAndNothingElse(): void
    {
        // Arrange
        $notifiable = new PlainAddress('previous@example.test');

        // Act & Assert
        $this->assertSame('previous@example.test', $notifiable->routeNotificationFor('mail'));
        $this->assertSame(
            'previous@example.test',
            $notifiable->email,
            "the mail channel's own fallback reads the property"
        );

        foreach (['database', 'push', 'broadcast', ''] as $channel) {
            $this->assertNull(
                $notifiable->routeNotificationFor($channel),
                $channel . ' was given a route it cannot have without an account'
            );
        }
    }
}
