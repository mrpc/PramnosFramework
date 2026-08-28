<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\MailAction;

/**
 * One-click actions from an email, and the tokens that authorise them.
 *
 * A Gmail `ConfirmAction` needs a URL that acts on the **first** request, with no confirmation
 * page and no sign-in, because Gmail issues one POST and does not follow up. There was nowhere to
 * point one, so no confirm action was offered — and "no handler exists" is a reason to build the
 * handler, not a reason to leave the feature out.
 *
 * The token is the whole authorisation: no session, no CSRF token, because the caller is a
 * mailbox provider's server and neither exists. Everything asserted here follows from that.
 */
#[CoversClass(MailAction::class)]
class MailActionTest extends TestCase
{
    protected function setUp(): void
    {
        MailAction::reset();
    }

    protected function tearDown(): void
    {
        MailAction::reset();

        parent::tearDown();
    }

    // ── tokens ───────────────────────────────────────────────────────────────

    /**
     * A token round-trips the action and its payload.
     */
    public function testATokenCarriesTheActionAndThePayload(): void
    {
        // Act
        $token = MailAction::token('confirm-order', ['order' => 42, 'who' => 'a@example.com']);
        $claim = MailAction::verify($token);

        // Assert
        $this->assertSame('confirm-order', $claim['action']);
        $this->assertSame('42', $claim['claim']['order']);
        $this->assertSame('a@example.com', $claim['claim']['who']);
        $this->assertGreaterThan(time(), $claim['expires']);
    }

    /**
     * A tampered token does not verify.
     *
     * Every part is inside the signature, which is what makes it a token rather than a
     * suggestion.
     */
    public function testATamperedTokenIsRefused(): void
    {
        // Arrange
        $token = MailAction::token('confirm-order', ['order' => 42]);

        // Act — flip one character in the middle
        $middle = (int) (strlen($token) / 2);
        $edited = substr_replace($token, $token[$middle] === 'a' ? 'b' : 'a', $middle, 1);

        // Assert
        $this->assertNull(MailAction::verify($edited));
    }

    /**
     * The expiry is inside the signed material.
     *
     * An expiry beside the signature is one the holder can edit, which makes it advice. This is
     * asserted by building a token that has already expired and confirming it is refused.
     */
    public function testAnExpiredTokenIsRefused(): void
    {
        // Arrange — a TTL in the past
        $token = MailAction::token('confirm-order', ['order' => 42], -10);

        // Assert
        $this->assertNull(MailAction::verify($token));
        $this->assertTrue(
            MailAction::expired($token),
            'expiry is distinguishable from forgery, for the one caller that may say so'
        );
    }

    /**
     * A forged token is not reported as expired.
     *
     * `verify()` answers null for both on purpose — telling the difference would tell somebody
     * probing how close they are — and `expired()` exists only so a *page* can say "this link
     * has expired, ask for another", which is useful and safe. It must not say that about a
     * forgery.
     */
    public function testAForgeryIsNotReportedAsExpired(): void
    {
        // Assert
        $this->assertFalse(MailAction::expired('not-a-token-at-all'));
        $this->assertFalse(MailAction::expired(''));
    }

    /**
     * A payload with the token format's own separators in it survives.
     *
     * The claim is user data — an email address, a title — and a `|` or an `&` in it must not be
     * able to forge a different claim.
     */
    public function testAPayloadContainingSeparatorsSurvives(): void
    {
        // Arrange
        $awkward = 'a|b&c=d';

        // Act
        $claim = MailAction::verify(MailAction::token('x', ['v' => $awkward]));

        // Assert
        $this->assertSame($awkward, $claim['claim']['v']);
    }

    /**
     * The URL is one a mail client will not mangle.
     *
     * `+`, `/` and `=` do not survive being pasted out of some clients, so the token is
     * URL-safe base64.
     */
    public function testTheUrlIsSafeToPutInAMessage(): void
    {
        // Act
        $url = MailAction::url('confirm-order', ['order' => 42]);

        // Assert
        $this->assertStringContainsString('/mailaction?a=', $url);
        $this->assertDoesNotMatchRegularExpression('~[+/]~', explode('a=', $url)[1]);
    }

    // ── dispatch ─────────────────────────────────────────────────────────────

    /**
     * A POST performs the action.
     *
     * What Gmail sends, and the whole point.
     */
    public function testAPostPerformsTheAction(): void
    {
        // Arrange
        $ran = null;
        MailAction::register('confirm-order', function (array $claim) use (&$ran): bool {
            $ran = $claim;

            return true;
        }, false, 'Your order is confirmed.');

        // Act
        $result = MailAction::dispatch(MailAction::token('confirm-order', ['order' => 42]), true);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertSame('Your order is confirmed.', $result['message']);
        $this->assertSame(['order' => '42'], $ran);
    }

    /**
     * A GET performs nothing by default.
     *
     * The safety property, and not ceremony. A GET is issued by things nobody asked for: a link
     * scanner in a corporate mail gateway, a client prefetching to build a preview, an antivirus
     * proxy. If a GET acted, those would act — so the default is to ask, and the caller is told
     * 405, which the controller turns into a page with a button.
     */
    public function testAGetPerformsNothingUnlessTheActionAllowsIt(): void
    {
        // Arrange
        $ran = false;
        MailAction::register('confirm-order', function () use (&$ran): bool {
            $ran = true;

            return true;
        });

        // Act
        $result = MailAction::dispatch(MailAction::token('confirm-order'), false);

        // Assert
        $this->assertSame(405, $result['status']);
        $this->assertFalse($ran, 'a scanner following the link must not confirm the order');
    }

    /**
     * An action can opt in to acting on a GET.
     *
     * Some effects are safe to trigger that way — confirming an address is the obvious one,
     * since whoever holds the message has already proved the point.
     */
    public function testAnActionCanOptInToActingOnAGet(): void
    {
        // Arrange
        MailAction::register('verify-address', static fn (): bool => true, true, 'Confirmed.');

        // Act
        $result = MailAction::dispatch(MailAction::token('verify-address'), false);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertSame('Confirmed.', $result['message']);
    }

    /**
     * A handler that returns false is a 500, so a provider retries.
     *
     * The usual reason a handler fails is a database that was briefly away, and that is exactly
     * the case retrying fixes. A 200 would lose the action silently.
     */
    public function testAFailedHandlerIsRetryable(): void
    {
        // Arrange
        MailAction::register('confirm-order', static fn (): bool => false);

        // Act
        $result = MailAction::dispatch(MailAction::token('confirm-order'), true);

        // Assert
        $this->assertSame(500, $result['status']);
    }

    /**
     * A handler that throws is caught, logged, and reported as retryable.
     *
     * An uncaught exception here is a 500 page rendered to Google's servers, and whatever the
     * framework's error handler decides to print in it.
     */
    public function testAThrowingHandlerIsCaught(): void
    {
        // Arrange
        MailAction::register('confirm-order', static function (): bool {
            throw new \RuntimeException('the database went away');
        });

        // Act
        $result = MailAction::dispatch(MailAction::token('confirm-order'), true);

        // Assert
        $this->assertSame(500, $result['status']);
        $this->assertStringNotContainsString(
            'database went away',
            $result['message'],
            'the reader is not shown the internals'
        );
    }

    /**
     * A valid token for an unregistered action says where to look.
     *
     * Almost always a handler registered in a service provider that did not run — a feature
     * switched off, a provider removed. Reporting "not valid" would send somebody to inspect the
     * token instead of the registration.
     */
    public function testAValidTokenForNoHandlerSaysWhereToLook(): void
    {
        // Act
        $result = MailAction::dispatch(MailAction::token('nobody-handles-this'), true);

        // Assert
        $this->assertSame(501, $result['status']);
        $this->assertStringContainsString('service provider', $result['message']);
        $this->assertSame('nobody-handles-this', $result['action']);
    }

    /**
     * An invalid token is 400 and an expired one is 410.
     *
     * Different answers because they mean different things to a provider: one will never work,
     * the other worked yesterday.
     */
    public function testInvalidAndExpiredAreDistinguishedForTheCaller(): void
    {
        // Arrange
        MailAction::register('confirm-order', static fn (): bool => true);

        // Act
        $invalid = MailAction::dispatch('rubbish', true);
        $stale   = MailAction::dispatch(MailAction::token('confirm-order', [], -10), true);

        // Assert
        $this->assertSame(400, $invalid['status']);
        $this->assertSame(410, $stale['status']);
        $this->assertStringContainsString('expired', $stale['message']);
    }

    /**
     * The action name in the URL is normalised, and matching follows it.
     *
     * A name with a space or a capital would otherwise register under one string and be looked
     * up under another — a handler that exists and never runs.
     */
    public function testTheActionNameIsNormalisedConsistently(): void
    {
        // Arrange
        MailAction::register('Confirm Order', static fn (): bool => true);

        // Act
        $result = MailAction::dispatch(MailAction::token('confirm-order'), true);

        // Assert
        $this->assertTrue(MailAction::has('Confirm Order'));
        $this->assertTrue(MailAction::has('confirm-order'));
        $this->assertSame(200, $result['status']);
        $this->assertSame(['confirm-order'], MailAction::registered());
    }

    /**
     * A token that is not base64, or has the wrong number of parts, is refused.
     *
     * The shapes a truncated link arrives in. A mail client wrapping a long URL cuts it, and the
     * result is not a forgery attempt — it is the most common invalid token there is, and it must
     * be refused the same way as one.
     */
    public function testAMalformedTokenIsRefusedInEveryShape(): void
    {
        // Assert
        $this->assertNull(MailAction::verify('!!! not base64 !!!'));
        $this->assertNull(MailAction::verify(base64_encode('only|three|parts')));
        $this->assertNull(MailAction::verify(''));

        // …and none of them is reported as expired, which is a different sentence
        $this->assertFalse(MailAction::expired(base64_encode('only|three|parts')));
    }
}
