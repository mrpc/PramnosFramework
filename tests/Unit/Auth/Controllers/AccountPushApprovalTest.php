<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\LoginFlowResult;
use Pramnos\Auth\PushApprovals;
use Pramnos\Auth\TrustedDevices;

require_once __DIR__ . '/AccountControllerTest.php';

/**
 * The Account controller's half of trusted devices and the phone prompt.
 *
 * What it owns is the wiring, and the tests hold it to that: the box's state reaches the
 * flow from every form; the phone prompt finishes without a code; the waiting page can ask
 * where the prompt stands; the phone's page answers only through the service; and a device
 * can be forgotten from the security page, only by POST with the session's token.
 */
class AccountPushApprovalTest extends TestCase
{
    private PushAccount $c;

    protected function setUp(): void
    {
        $_POST    = [];
        $_GET     = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->c = new PushAccount(null);
    }

    protected function tearDown(): void
    {
        $_POST    = [];
        $_GET     = [];
        $_SESSION = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * "Don't ask again" travels with every form as 1 or 0 and reaches the flow; a form
     * without it leaves the choice alone.
     */
    public function testTheTrustBoxReachesTheFlowFromAnyForm(): void
    {
        // Arrange
        $this->c->flow->pending = 7;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Act — unticked, ticked, absent
        foreach (['0', '1', null] as $state) {
            $_POST = ['code' => '000000'] + ($state === null ? [] : ['trust_device' => $state]);
            $this->c->verify();
        }

        // Assert
        $this->assertSame([false, true], $this->c->flow->trustChoices);
    }

    /**
     * The phone prompt has nothing to type: an empty code with method=push goes to the flow
     * rather than being refused as missing — and a refusal says the phone has not approved.
     */
    public function testThePhonePromptFinishesWithoutACode(): void
    {
        // Arrange
        $this->c->flow->pending = 7;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['method' => 'push', 'code' => ''];

        // Act
        $out = $this->c->verify();

        // Assert
        $this->assertSame('VIEW:login_2fa', $out);
        $this->assertSame(['push', ''], $this->c->flow->completedFactor);
        $this->assertSame('push_not_approved', $this->c->view->props['error']);

        // And approved, it signs in.
        $this->c->flow->factorResult = LoginFlowResult::success(7);
        $this->assertNull($this->c->verify());
        $this->assertNotSame([], $this->c->redirects);
    }

    /**
     * "Send it again" says it went, or that it could not — in the prompt's own words, not
     * the mailed code's.
     */
    public function testSendingThePromptAgainSaysWhatHappened(): void
    {
        // Arrange
        $this->c->flow->pending = 7;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['send_factor' => 'push'];

        // Act
        $this->c->flow->sends = true;
        $this->c->verify();
        $sent = $this->c->view->props['notice'] ?? null;
        $this->c->flow->sends = false;
        $this->c->verify();

        // Assert
        $this->assertSame('push_sent', $sent);
        $this->assertSame('push_failed', $this->c->view->props['error']);
    }

    /**
     * The waiting page asks where its prompt stands; without a pending sign-in there is
     * nothing to answer about.
     */
    public function testTheWaitingPageCanAskWhereThePromptStands(): void
    {
        // Act
        $none = $this->c->pushstatus();
        $this->c->flow->pending = 7;
        $some = $this->c->pushstatus();

        // Assert
        $this->assertSame(401, $none->getStatusCode());
        $this->assertSame(200, $some->getStatusCode());
        $this->assertStringContainsString('"state":"delivered"', $some->getBody());
    }

    /**
     * The step-up page is told whether to lead with the phone, what it went to, and whether
     * and for how long to offer "don't ask again".
     */
    public function testTheStepUpPageIsToldAboutThePhoneAndTheBox(): void
    {
        // Arrange
        $this->c->flow->pending = 7;
        $this->c->flow->methods = ['push', 'twofactor'];

        // Act
        $this->c->verify();

        // Assert
        $props = $this->c->view->props;
        $this->assertTrue($props['pushFactor']);
        $this->assertSame('delivered', $props['pushStatus']['state']);
        $this->assertTrue($props['canTrust']);
        $this->assertSame(30, $props['trustDays']);
        $this->assertTrue($props['trustChosen']);
        $this->assertFalse($props['pushOffered'], 'push is not set up in a unit test');
    }

    /**
     * The phone's page shows the ask and records the answer through the service — with the
     * number picked, as an integer — and a failed session token changes nothing.
     */
    public function testThePhonesPageAnswersThroughTheService(): void
    {
        // Arrange
        $this->c->userId = 7;
        $_GET = ['token' => 'tok'];

        // Act — looked at
        $out = $this->c->approve();

        // Assert
        $this->assertSame('VIEW:approve', $out);
        $this->assertSame(['tok', 7], $this->c->approvals->looked[0]);
        $this->assertSame('tok', $this->c->view->props['token']);

        // Act — answered with a number
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => 'tok', 'decision' => 'approved', 'number' => '47'];
        $this->c->approve();

        // Assert
        $this->assertSame(['tok', 7, 'approved', 47], $this->c->approvals->decided[0]);
        $this->assertSame('approved', $this->c->view->props['result']);

        // Act — without a number, and then with a failed token
        $_POST = ['token' => 'tok', 'decision' => 'denied'];
        $this->c->approve();
        $this->c->csrf = false;
        $this->c->approve();

        // Assert
        $this->assertSame(['tok', 7, 'denied', null], $this->c->approvals->decided[1]);
        $this->assertCount(2, $this->c->approvals->decided, 'the failed token decided nothing');
        $this->assertSame('invalid_token', $this->c->view->props['error']);
    }

    /**
     * A browser that may not answer is shown so, and cannot answer by posting anyway.
     */
    public function testABrowserThatMayNotAnswerCannot(): void
    {
        // Arrange
        $this->c->userId = 7;
        $this->c->approvals->answerable = false;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => 'tok', 'decision' => 'approved'];

        // Act
        $this->c->approve();

        // Assert
        $this->assertNull($this->c->view->props['approval']);
        $this->assertSame([], $this->c->approvals->decided);
    }

    /**
     * A device is forgotten by POST with the session's token — one, or all of them — and
     * the page returns to the security screen either way.
     */
    public function testDevicesAreForgottenFromTheSecurityPage(): void
    {
        // Arrange
        $this->c->userId = 7;

        // Act — a GET does nothing
        $this->c->revokedevice();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['device' => '3'];
        $this->c->revokedevice();
        $_POST = ['all' => '1'];
        $this->c->revokedevice();

        // Assert
        $this->assertSame([[7, 3]], $this->c->devices->revoked);
        $this->assertSame([7], $this->c->devices->revokedAll);
        $this->assertCount(3, $this->c->redirects);
        $this->assertStringEndsWith('/security', (string) $this->c->redirects[0]);
    }

    /** The passkey's "don't ask again" rides on its address, since its body is the assertion. */
    public function testThePasskeyCarriesTheTrustBoxOnItsAddress(): void
    {
        // Arrange
        $this->c->flow->pending = 7;
        $_SESSION['account_stepup_passkey_challenge'] = 'chal';
        $_GET = ['trust' => '0'];
        $this->c->flow->passkeyResult = LoginFlowResult::success(7);

        // Act
        $this->c->passkeyVerify();

        // Assert
        $this->assertSame([false], $this->c->flow->trustChoices);
    }

    /** The real services are what the controller uses when nothing replaces them. */
    public function testTheDefaultServicesAreTheRealOnes(): void
    {
        // Arrange
        $account = new class extends \Pramnos\Auth\Controllers\Account {
            public function __construct()
            {
            }

            public function services(): array
            {
                return [$this->pushApprovals(), $this->trustedDevices()];
            }
        };

        // Act
        [$approvals, $devices] = $account->services();

        // Assert
        $this->assertInstanceOf(PushApprovals::class, $approvals);
        $this->assertInstanceOf(TrustedDevices::class, $devices);
    }
}

class PushFlow extends FakeLoginFlow
{
    /** @var list<bool> */
    public array $trustChoices = [];

    /** @var array{0: string, 1: string}|null */
    public ?array $completedFactor = null;

    public ?LoginFlowResult $factorResult = null;

    public bool $sends = true;

    /** @var list<string> */
    public array $methods = [];

    public function chooseTrust(bool $trust): void
    {
        $this->trustChoices[] = $trust;
    }

    public function completeFactor(string $factorName, string $code): LoginFlowResult
    {
        $this->completedFactor = [$factorName, $code];

        return $this->factorResult ?? LoginFlowResult::failed();
    }

    public function sendFactorChallenge(string $factorName): bool
    {
        return $this->sends;
    }

    public function secondsUntilResend(): int
    {
        return 0;
    }

    public function pendingStepUpMethods(): array
    {
        return $this->methods;
    }

    public function pendingFactors(): array
    {
        return [];
    }

    public function hasLiveEmailCode(): bool
    {
        return false;
    }

    public function pushStatus(): array
    {
        return ['state' => 'delivered', 'number' => null, 'devices' => ['Safari on iPhone or iPad'], 'expires_in' => 90];
    }

    public function canTrustDevice(): bool
    {
        return true;
    }

    public function trustDays(): int
    {
        return 30;
    }

    public function trustChoice(): bool
    {
        return true;
    }
}

class RecordingApprovals extends PushApprovals
{
    public bool $answerable = true;

    /** @var list<array{0: string, 1: int}> */
    public array $looked = [];

    /** @var list<array{0: string, 1: int, 2: string, 3: int|null}> */
    public array $decided = [];

    public function forAnswering(string $token, int $userId): ?array
    {
        $this->looked[] = [$token, $userId];

        return $this->answerable ? ['approval_id' => 1, 'choices' => [], 'open' => true] : null;
    }

    public function decide(string $token, int $userId, string $decision, ?int $picked = null): string
    {
        $this->decided[] = [$token, $userId, $decision, $picked];

        return $decision;
    }

    public function enabled(): bool
    {
        return false;
    }
}

class RecordingDevices extends TrustedDevices
{
    /** @var list<array{0: int, 1: int}> */
    public array $revoked = [];

    /** @var list<int> */
    public array $revokedAll = [];

    public function revoke(int $userId, int $deviceId): bool
    {
        $this->revoked[] = [$userId, $deviceId];

        return true;
    }

    public function revokeAll(int $userId): int
    {
        $this->revokedAll[] = $userId;

        return 2;
    }
}

class PushAccount extends TestableAccount
{
    public RecordingApprovals $approvals;

    public RecordingDevices $devices;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);

        $this->flow      = new PushFlow();
        $this->devices   = new RecordingDevices();
        $this->approvals = new RecordingApprovals($this->devices);
    }

    protected function pushApprovals(): PushApprovals
    {
        return $this->approvals;
    }

    protected function trustedDevices(): TrustedDevices
    {
        return $this->devices;
    }

    protected function useStandaloneLayout(): void
    {
    }

    protected function addMessage($message)
    {
    }
}
