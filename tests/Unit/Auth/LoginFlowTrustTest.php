<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Auth\Factors\PushApprovalSecondFactor;
use Pramnos\Auth\PushApprovals;
use Pramnos\Auth\SecondFactorRegistry;
use Pramnos\Auth\TrustedDevices;

require_once __DIR__ . '/LoginFlowTest.php';

/**
 * The login flow's half of "don't ask again on this device" and the phone prompt.
 *
 * Separate from LoginFlowTest so the contract reads in one place: a trusted browser is let
 * through on its password; a browser is trusted only after a real second factor and only
 * when the box was left ticked; the phone prompt goes out as the step-up begins and is
 * never the only way through.
 */
class LoginFlowTrustTest extends TestCase
{
    private TrustingLoginFlow $flow;

    /** @var array<string, mixed>|null */
    private ?array $savedInstances = null;

    private mixed $savedSmtpHost = null;

    private mixed $savedPush = null;

    protected function setUp(): void
    {
        $_SESSION = [];
        SecondFactorRegistry::reset();

        $stub = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => ['twofactor_methods' => ['totp'], 'security' => []]];
        $reflection = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $reflection->setValue(null, ['default' => $stub] + $this->savedInstances);

        $this->savedSmtpHost = Settings::getSetting('smtp_host');
        Settings::setSetting('smtp_host', '127.0.0.1', false);
        // The phone prompt switched on, which is also what lets the mailed code through as
        // its fallback — see SecondFactorRegistry::all().
        $this->savedPush = Settings::getSetting(PushApprovals::ENABLED_SETTING);
        Settings::setSetting(PushApprovals::ENABLED_SETTING, '1', false);

        $this->flow = new TrustingLoginFlow();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        SecondFactorRegistry::reset();
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))
            ->setValue(null, $this->savedInstances);
        Settings::setSetting('smtp_host', (string) $this->savedSmtpHost, false);
        Settings::setSetting(PushApprovals::ENABLED_SETTING, (string) $this->savedPush, false);
    }

    /**
     * A browser the account trusted is let through on the password — the second step is
     * skipped, the login is recorded as carried by the trusted device, and nothing is
     * trusted afresh.
     */
    public function testATrustedBrowserSkipsTheSecondStep(): void
    {
        // Arrange
        $this->flow->fakeAuth->response     = ['status' => true, 'uid' => 7];
        $this->flow->fakeTwoFactor->enabled = true;
        $this->flow->devices->trusted       = true;

        // Act
        $result = $this->flow->attempt('alice', 'secret', true);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame([7, true], $this->flow->fakeAuth->loginArgs);
        $this->assertContains('trusted_device', $this->flow->fakeAuth->methodCalls);
        $this->assertSame([], $this->flow->devices->trustedFor, 'a trusted browser is not trusted again');
    }

    /**
     * The box is shown ticked unless the person unticks it, and the page sends it with the
     * form: finishing the second step with it sent ticked trusts the browser, once. Unticked,
     * it does not.
     */
    public function testFinishingTheSecondStepTrustsTheBrowserUnlessUnticked(): void
    {
        // Arrange
        $this->flow->fakeAuth->response      = ['status' => true, 'uid' => 7];
        $this->flow->fakeTwoFactor->enabled  = true;
        $this->flow->fakeTwoFactor->verifies = true;
        $this->flow->attempt('alice', 'secret', true);

        // Assert — ticked by default, as the page shows it
        $this->assertTrue($this->flow->trustChoice());
        $this->assertTrue($this->flow->canTrustDevice());
        $this->assertSame(30, $this->flow->trustDays());

        // Act — the form carries the ticked box
        $this->flow->chooseTrust(true);
        $this->flow->completeTwoFactor('123456');

        // Assert — trusted once, and the factor that did it is recorded with it
        $this->assertSame([7], $this->flow->devices->trustedFor);
        $this->assertSame(['twofactor'], $this->flow->devices->via);

        // Arrange — again, unticked
        $this->flow->devices->trustedFor = [];
        $this->flow->attempt('alice', 'secret', true);
        $this->flow->chooseTrust(false);
        $this->assertFalse($this->flow->trustChoice(), 'the page remembers it was unticked');

        // Act
        $this->flow->completeTwoFactor('123456');

        // Assert — not trusted, and the choice does not outlive the sign-in it was for
        $this->assertSame([], $this->flow->devices->trustedFor);
        $this->assertTrue($this->flow->trustChoice());
    }

    /**
     * A page that never sent the box trusts nothing — no JavaScript to copy it into the form,
     * or an application's own two-step view that predates it. Showing it ticked is not the
     * person choosing it; and a choice from an earlier step-up does not carry over.
     */
    public function testNothingIsTrustedUnlessThePageSentTheBox(): void
    {
        // Arrange — a stale choice from before, then a step-up whose page sends nothing
        $this->flow->chooseTrust(true);
        $this->flow->fakeAuth->response      = ['status' => true, 'uid' => 7];
        $this->flow->fakeTwoFactor->enabled  = true;
        $this->flow->fakeTwoFactor->verifies = true;
        $this->flow->attempt('alice', 'secret', true);

        // Act
        $result = $this->flow->completeTwoFactor('123456');

        // Assert — signed in, not trusted
        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $this->flow->devices->trustedFor);
    }

    /**
     * The password alone is never grounds for trust, whatever the session says.
     */
    public function testAPasswordAloneTrustsNothing(): void
    {
        // Arrange — no second factor, a stale "trust" left in the session
        $this->flow->fakeAuth->response = ['status' => true, 'uid' => 7];
        $this->flow->chooseTrust(true);

        // Act
        $result = $this->flow->attempt('alice', 'secret', true);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $this->flow->devices->trustedFor);
        $this->assertFalse($this->flow->canTrustDevice(), 'nothing pending, nothing to offer');
    }

    /**
     * An account with a trusted phone is sent the prompt as the step-up begins — nothing to
     * start, as with Google's — and it is offered first.
     */
    public function testThePhonePromptGoesOutAsTheStepUpBegins(): void
    {
        // Arrange
        $this->flow->fakeAuth->response     = ['status' => true, 'uid' => 7];
        $this->flow->fakeTwoFactor->enabled = true;
        $this->flow->push->available        = true;

        // Act
        $result = $this->flow->attempt('alice', 'secret', true);

        // Assert
        $this->assertTrue($result->needsStepUp());
        $this->assertSame('push', $result->stepUpMethods[0], 'strongest first');
        $this->assertContains('twofactor', $result->stepUpMethods);
        $this->assertSame([7], $this->flow->push->started);
        $this->assertSame('sent', $this->flow->pushStatus()['state']);
    }

    /**
     * The prompt is never the only way through: an account whose only factor would be the
     * phone is also offered a mailed code.
     */
    public function testThePhonePromptIsNeverTheOnlyWay(): void
    {
        // Arrange — the phone and nothing else
        $this->flow->fakeAuth->response = ['status' => true, 'uid' => 7];
        $this->flow->push->available    = true;

        // Act
        $result = $this->flow->attempt('alice', 'secret', true);

        // Assert
        $this->assertSame(['push', 'email'], $result->stepUpMethods);
    }

    /**
     * Without a pending sign-in there is no prompt to report, and the flow says so rather
     * than asking the service about nobody.
     */
    public function testNoPendingSignInHasNoPrompt(): void
    {
        // Act
        $status = $this->flow->pushStatus();

        // Assert
        $this->assertSame('none', $status['state']);
        $this->assertSame([], $this->flow->push->asked);
    }

    /** The real collaborators are what the flow uses when a subclass does not replace them. */
    public function testTheDefaultCollaboratorsAreTheRealServices(): void
    {
        // Arrange
        $flow = new class extends \Pramnos\Auth\LoginFlow {
            public function devices(): TrustedDevices
            {
                return $this->trustedDevices();
            }

            public function approvals(): PushApprovals
            {
                return $this->pushApprovals();
            }
        };

        // Act & Assert
        $this->assertInstanceOf(TrustedDevices::class, $flow->devices());
        $this->assertInstanceOf(PushApprovals::class, $flow->approvals());
    }
}

/** Trusted devices without a database: what is trusted, and what was asked to be. */
class FakeTrustedDevices extends TrustedDevices
{
    public bool $trusted = false;

    public bool $allowed = true;

    /** @var list<int> */
    public array $trustedFor = [];

    /** @var list<string> Which factor each trust was for. */
    public array $via = [];

    public function trustVia(int $userId, string $method): ?int
    {
        $this->via[] = $method;

        return parent::trustVia($userId, $method);
    }

    public function allowedFor(int $userId): bool
    {
        return $this->allowed && $userId > 0;
    }

    public function isTrusted(int $userId): bool
    {
        return $this->trusted;
    }

    public function trust(int $userId): ?int
    {
        $this->trustedFor[] = $userId;

        return 1;
    }

    public function days(): int
    {
        return 30;
    }
}

/** The phone prompt without push: whether it can be sent, and what was started. */
class FakePushApprovals extends PushApprovals
{
    public bool $available = false;

    /** @var list<int> */
    public array $started = [];

    /** @var list<int> */
    public array $asked = [];

    public function availableFor(int $userId): bool
    {
        return $this->available;
    }

    public function start(int $userId): bool
    {
        $this->started[] = $userId;

        return true;
    }

    public function status(int $userId): array
    {
        $this->asked[] = $userId;

        return ['state' => $this->started === [] ? 'none' : 'sent', 'number' => null, 'devices' => ['Safari on iPhone or iPad'], 'expires_in' => 120];
    }
}

class TrustingLoginFlow extends TestableLoginFlow
{
    public FakeTrustedDevices $devices;

    public FakePushApprovals $push;

    public function __construct()
    {
        parent::__construct();

        $this->devices = new FakeTrustedDevices();
        $this->push    = new FakePushApprovals($this->devices);

        SecondFactorRegistry::register(new PushApprovalSecondFactor($this->push));
    }

    protected function trustedDevices(): TrustedDevices
    {
        return $this->devices;
    }

    protected function pushApprovals(): PushApprovals
    {
        return $this->push;
    }
}
