<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Auth\NewSignInAlert;

/**
 * What a sign-in from an unrecognised device is made to satisfy.
 *
 * `notify` — mail the owner and let the login through — was the only answer available, and
 * it is the weakest useful one: by the time the mail arrives, whoever had the password is
 * already inside. These are the readings that stop it instead.
 *
 * The property worth testing hardest is not that each option demands the right thing. It
 * is that **none of them can demand something the account cannot do**: a policy that asks
 * for a passkey from a user base that has none is not a security setting, it is an outage
 * with a checkbox. Every strict reading therefore has a fallback, and the last fallback is
 * a mailed code, because a mailbox is the one factor every account has.
 */
#[CoversClass(NewSignInAlert::class)]
class NewSignInActionTest extends TestCase
{
    /** Whether the device is new is the caller's answer — see `requiredFor()`. */
    private const FRESH = true;
    private const KNOWN = false;

    private mixed $savedAction = null;

    protected function setUp(): void
    {
        $this->savedAction = Settings::getSetting(NewSignInAlert::ACTION_SETTING);
    }

    protected function tearDown(): void
    {
        Settings::setSetting(NewSignInAlert::ACTION_SETTING, (string) $this->savedAction, false);
        parent::tearDown();
    }

    private function withAction(string $action): void
    {
        Settings::setSetting(NewSignInAlert::ACTION_SETTING, $action, false);
    }

    /**
     * Nothing configured is the behaviour every installation already had.
     */
    public function testTheDefaultIsNotifyOnly(): void
    {
        // Arrange
        $this->withAction('');

        // Act & Assert
        $this->assertSame('notify', NewSignInAlert::action());
    }

    /**
     * A value nobody recognises falls back to `notify`, not to the strictest reading.
     *
     * The direction of that fallback is the decision. A typo in a settings row must not be
     * the thing that starts demanding passkeys from everybody — and the setting is on a
     * screen, where a value that visibly does nothing gets noticed and fixed.
     */
    public function testAnUnknownActionFallsBackToNotify(): void
    {
        // Arrange
        $this->withAction('require_everything');

        // Act & Assert
        $this->assertSame('notify', NewSignInAlert::action());
    }

    /**
     * Every configured action is accepted as written.
     */
    public function testEveryDocumentedActionIsAccepted(): void
    {
        foreach (NewSignInAlert::ACTIONS as $action) {
            // Arrange
            $this->withAction($action);

            // Act & Assert
            $this->assertSame($action, NewSignInAlert::action());
        }
    }

    /**
     * A device the account has used before demands nothing, whatever the action.
     *
     * The point of the whole feature: it costs a step only on a browser the account has
     * not been seen from, so it is not a second factor imposed on every sign-in.
     */
    public function testARecognisedDeviceIsNeverAsked(): void
    {
        foreach (NewSignInAlert::ACTIONS as $action) {
            // Arrange
            $this->withAction($action);

            // Act & Assert
            $this->assertSame(
                [],
                NewSignInAlert::requiredFor(7, self::KNOWN, false, false),
                $action . ' must not question a device with history'
            );
        }
    }

    /**
     * `notify` demands nothing, whatever the account has.
     */
    public function testNotifyDemandsNothing(): void
    {
        // Arrange
        $this->withAction('notify');

        // Act & Assert
        $this->assertSame([], NewSignInAlert::requiredFor(7, self::FRESH, true, true));
    }

    /**
     * A passkey is asked for when the account has one — with the app beside it.
     *
     * A passkey left at home must not strand somebody who is carrying a second factor the
     * site already trusts, so the app is offered as well rather than instead.
     */
    public function testRequirePasskeyAsksForOneWhenThereIsOne(): void
    {
        // Arrange
        $this->withAction('require_passkey');

        // Act & Assert
        $this->assertSame(['passkey'], NewSignInAlert::requiredFor(7, self::FRESH, false, true));
        $this->assertSame(
            ['passkey', 'twofactor'],
            NewSignInAlert::requiredFor(7, self::FRESH, true, true)
        );
    }

    /**
     * And it drops to what the account *does* have when there is no passkey.
     *
     * This is the test that stops the setting being an outage: the strictest option, on an
     * account that cannot satisfy it, still resolves to something satisfiable.
     */
    public function testRequirePasskeyFallsBackRatherThanLockingOut(): void
    {
        // Arrange
        $this->withAction('require_passkey');

        // Act & Assert — an app if there is one, a mailed code if there is not
        $this->assertSame(['twofactor'], NewSignInAlert::requiredFor(7, self::FRESH, true, false));
        $this->assertSame(['email'], NewSignInAlert::requiredFor(7, self::FRESH, false, false));
    }

    /**
     * `require_2fa` imposes a mailed code on an account that has no factor at all.
     *
     * Regardless of that account's own email-factor switch, because the demand is the
     * site's rather than the account's — and an account with nothing set up is exactly the
     * one a stolen password threatens most. Without this, `require_2fa` would mean nothing
     * for the accounts that need it.
     */
    public function testRequireTwoFactorImposesACodeWhenThereIsNoFactor(): void
    {
        // Arrange
        $this->withAction('require_2fa');

        // Act & Assert
        $this->assertSame(['email'], NewSignInAlert::requiredFor(7, self::FRESH, false, false));
        $this->assertSame(['twofactor'], NewSignInAlert::requiredFor(7, self::FRESH, true, false));
        $this->assertSame(
            ['twofactor', 'passkey'],
            NewSignInAlert::requiredFor(7, self::FRESH, true, true)
        );
    }

    /**
     * `authlink` needs no fallback: every account has a mailbox.
     */
    public function testTheAuthLinkIsAlwaysSatisfiable(): void
    {
        // Arrange
        $this->withAction('authlink');

        // Act & Assert — the same answer whatever the account has
        $this->assertSame(['authlink'], NewSignInAlert::requiredFor(7, self::FRESH, false, false));
        $this->assertSame(['authlink'], NewSignInAlert::requiredFor(7, self::FRESH, true, true));
    }

    /**
     * The system account is never asked for anything.
     *
     * `userid` 1 is the client-credentials machine account: there is nobody to read a
     * mailbox and no browser to recognise, so a demand there is a broken integration
     * rather than a protected user.
     */
    public function testTheSystemAccountIsExempt(): void
    {
        // Arrange
        $this->withAction('require_2fa');

        // Act & Assert
        $this->assertSame([], NewSignInAlert::requiredFor(1, self::FRESH, false, false));
    }
}
