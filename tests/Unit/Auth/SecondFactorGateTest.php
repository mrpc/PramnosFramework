<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\SecondFactorGate;
use Pramnos\Auth\SecurityPolicy;

/** A login flow whose answer about the second factor a test decides, counting the questions. */
final class GateTestFlow extends LoginFlow
{
    public bool $owes = true;

    public int $asked = 0;

    /** @var (callable(): void)|null Something to do while deciding — ask the gate again, say. */
    public $during = null;

    public function __construct()
    {
    }

    /** The test's answer. */
    public function demandSecondFactor(int $userId): bool
    {
        $this->asked++;
        if ($this->during !== null) {
            ($this->during)();
        }

        return $this->owes;
    }
}

/** An application's own login flow, as `auth.login_flow` names it. */
final class GateAppFlow extends LoginFlow
{
    public function __construct()
    {
    }
}

/** The account screens with the parts a return address is read from opened up. */
final class GateAccountProbe extends \Pramnos\Auth\Controllers\Account
{
    public function __construct()
    {
    }

    public function theReturn(): string
    {
        return $this->returnUrl();
    }

    public function theTarget(string $return): string
    {
        return $this->postLoginTarget($return);
    }

    public function theFlow(): LoginFlow
    {
        return $this->flow();
    }

    protected function baseUrl(): string
    {
        return 'https://site.example/';
    }
}

/**
 * The session-wide gate (FW-164): with `auth.security.second_factor_everywhere` on, a session
 * signed in without its second factor is not counted as signed in, wherever it came from.
 */
#[CoversClass(SecondFactorGate::class)]
#[CoversClass(SecurityPolicy::class)]
#[CoversClass(LoginFlow::class)]
final class SecondFactorGateTest extends TestCase
{
    private ?array $saved = null;

    private array $server = [];

    protected function setUp(): void
    {
        $_SESSION = ['logged' => true, 'uid' => 7];
        $this->server = $_SERVER;
        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->saved = $ref->getValue() ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        $_SERVER  = $this->server;
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, $this->saved);
    }

    /** An application with the gate on or off. */
    private static function gate(bool $on, array $security = [], array $auth = []): void
    {
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $app->applicationInfo = ['auth' => ['security' => ['second_factor_everywhere' => $on] + $security] + $auth];
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, ['default' => $app]);
    }

    /**
     * Off, nothing is asked. On: a session settled for its account passes without a question; an
     * unsettled one that owes a factor is no longer signed in; one that owes none passes.
     */
    public function testWhoPasses(): void
    {
        // Arrange
        $flow = new GateTestFlow();

        // Act + Assert
        self::gate(false);
        $this->assertTrue(SecondFactorGate::passes(7, $flow));
        $this->assertSame(0, $flow->asked, 'off: not even asked');

        self::gate(true);
        SecondFactorGate::settle(7);
        $this->assertTrue(SecondFactorGate::passes(7, $flow));
        $this->assertSame(0, $flow->asked, 'settled: not asked again');

        unset($_SESSION[SecondFactorGate::SESSION]);
        $flow->owes = false;
        $this->assertTrue(SecondFactorGate::passes(7, $flow), 'owes nothing');

        $flow->owes = true;
        $this->assertFalse(SecondFactorGate::passes(7, $flow), 'owes its factor');
        $this->assertFalse($_SESSION['logged']);
        $this->assertSame(0, $_SESSION['uid'], 'no longer signed in');
    }

    /** A question the deciding asks about the session does not ask the gate again. */
    public function testItDoesNotAskItselfWhileDeciding(): void
    {
        // Arrange
        self::gate(true);
        $flow  = new GateTestFlow();
        $inner = null;
        $flow->during = static function () use (&$inner, $flow): void {
            $inner = SecondFactorGate::passes(7, $flow);
        };

        // Act
        $outer = SecondFactorGate::passes(7, $flow);

        // Assert
        $this->assertTrue($inner, 'inside the decision: let through, not asked again');
        $this->assertSame(1, $flow->asked);
        $this->assertFalse($outer);
    }

    /**
     * Where a stopped session is sent (FW-166). A page opened in a browser goes to the step the
     * application names, and the page is remembered to come back to; with none named it is only
     * remembered. A form post, an XHR, a JSON call and a script go nowhere — a redirect would
     * answer them with a page they did not ask for — and nor does the step's own page, or the
     * browser would be sent round in a circle.
     */
    public function testWhereAStoppedSessionIsSent(): void
    {
        // Arrange — a browser opening a page
        $browser = static function (string $uri, array $more = []): void {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_ACCEPT' => 'text/html,*/*'] + $more;
        };
        $base = defined('sURL') ? sURL : '/';

        // Act + Assert — no step named: remembered, not sent
        self::gate(true);
        $browser('/admin/reports?y=2026');
        $this->assertNull(SecondFactorGate::secondStep());
        $this->assertSame('/admin/reports?y=2026', $_SESSION[SecondFactorGate::RETURN]);

        // a step named: sent there, a relative one under the site, an absolute one as it is
        $_SESSION = [];
        self::gate(true, ['second_factor_url' => 'login']);
        $this->assertSame($base . 'login', SecondFactorGate::secondStep());
        $this->assertSame('/admin/reports?y=2026', $_SESSION[SecondFactorGate::RETURN]);
        self::gate(true, ['second_factor_url' => 'https://auth.example/login']);
        $this->assertSame('https://auth.example/login', SecondFactorGate::secondStep());

        // the step's own page is not sent to itself, and does not become the return
        $_SESSION = [];
        self::gate(true, ['second_factor_url' => 'login']);
        $browser('/login?x=1');
        $this->assertNull(SecondFactorGate::secondStep());
        $this->assertArrayNotHasKey(SecondFactorGate::RETURN, $_SESSION);

        // not a page opened in a browser: nothing
        foreach ([
            'a form post' => ['REQUEST_METHOD' => 'POST'],
            'an XHR'      => ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            'a JSON call' => ['HTTP_ACCEPT' => 'application/json'],
        ] as $what => $more) {
            $browser('/admin/reports');
            $_SERVER = $more + $_SERVER;
            $this->assertNull(SecondFactorGate::secondStep(), $what);
            $this->assertArrayNotHasKey(SecondFactorGate::RETURN, $_SESSION, $what);
        }
        $_SERVER = [];
        $this->assertNull(SecondFactorGate::secondStep(), 'a script');
    }

    /**
     * A demoted session remembers its page through the gate itself — in a script no redirect is
     * sent, and the answer is still "not signed in".
     */
    public function testTheGateRemembersThePageItStopped(): void
    {
        // Arrange
        self::gate(true, ['second_factor_url' => 'login']);
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin', 'HTTP_ACCEPT' => 'text/html'];

        // Act
        $passes = SecondFactorGate::passes(7, new GateTestFlow());

        // Assert
        $this->assertFalse($passes);
        $this->assertSame('/admin', $_SESSION[SecondFactorGate::RETURN] ?? null);
    }

    /**
     * The account screens take the remembered page as the return where the request names none,
     * and forget it once the sign-in is finished — a later sign-in from the form must not land on
     * a page from an abandoned one. A return the request names still wins.
     */
    public function testTheAccountScreensReturnToTheRememberedPage(): void
    {
        // Arrange
        $account = new GateAccountProbe();
        $_SESSION[SecondFactorGate::RETURN] = '/admin/reports';

        // Act
        $remembered = $account->theReturn();
        $_GET['return'] = '/account';
        $named = $account->theReturn();
        $target = $account->theTarget($remembered);

        // Assert
        $this->assertSame('/admin/reports', $remembered);
        $this->assertSame('/account', $named, 'the request\'s own return wins');
        $this->assertSame('/admin/reports', $target);
        $this->assertArrayNotHasKey(SecondFactorGate::RETURN, $_SESSION, 'forgotten once used');
    }

    /**
     * The flow the gate and the account screens run is the application's (FW-166): a subclass
     * named by `auth.login_flow`, with its own trusted devices or factors. Anything else — no
     * application, no setting, a class that is not a login flow — is the framework's own.
     */
    public function testTheApplicationsLoginFlowIsTheOneMade(): void
    {
        // Arrange + Act + Assert
        self::gate(true, [], ['login_flow' => GateAppFlow::class]);
        $this->assertInstanceOf(GateAppFlow::class, LoginFlow::make());
        $this->assertInstanceOf(GateAppFlow::class, (new GateAccountProbe())->theFlow());

        self::gate(true, [], ['login_flow' => \stdClass::class]);
        $this->assertSame(LoginFlow::class, LoginFlow::make()::class, 'not a login flow: ignored');

        self::gate(true);
        $this->assertSame(LoginFlow::class, LoginFlow::make()::class);

        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, []);
        $this->assertSame(LoginFlow::class, LoginFlow::make()::class, 'no application');
    }

    /**
     * A stopped session goes on being sent to the step (FW-169): while its login waits there, or
     * its account is locked out, every page it opens is owed — each remembered as the page to go
     * back to — though the session reads as signed out. It is forgotten when the wait is over:
     * the login finished (settled), abandoned or expired, the lockout over, the switch off.
     */
    public function testAStoppedSessionStaysStopped(): void
    {
        // Arrange
        self::gate(true);
        $browse = static function (string $uri): void {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_ACCEPT' => 'text/html'];
        };
        $owed = static function (int $user, int $until): void {
            $_SESSION[SecondFactorGate::OWED] = ['user' => $user, 'until' => $until];
        };

        // Act + Assert — the gate stops it, its login now waiting for the step, and says so
        $browse('/admin/Myaccount');
        $this->assertFalse(SecondFactorGate::passes(7, new GateTestFlow()));
        $this->assertSame(7, $_SESSION[SecondFactorGate::OWED]['user']);
        $_SESSION['loginflow_pending_userid'] = 7;
        $_SESSION['loginflow_pending_time']   = time();
        $this->assertFalse(\Pramnos\Http\Session::staticIsLogged(), 'signed out');

        // the next page is owed too, and is where it goes back to
        $browse('/el');
        $this->assertTrue(SecondFactorGate::stillOwed());
        $this->assertSame('/el', $_SESSION[SecondFactorGate::RETURN]);

        // locked out, with no login to finish: owed while the lockout lasts
        $_SESSION = [SecondFactorGate::LOCKED => time() + 60];
        $owed(7, time() + 600);
        $this->assertTrue(SecondFactorGate::stillOwed());
        $this->assertGreaterThan(0, SecondFactorGate::lockedFor());

        // over: abandoned, expired, the switch off, settled
        $_SESSION = [];
        $owed(7, time() + 600);
        $this->assertFalse(SecondFactorGate::stillOwed(), 'no login waiting, no lockout');
        $this->assertArrayNotHasKey(SecondFactorGate::OWED, $_SESSION);
        $_SESSION = ['loginflow_pending_userid' => 7, 'loginflow_pending_time' => time()];
        $owed(7, time() - 1);
        $this->assertFalse(SecondFactorGate::stillOwed(), 'expired');
        $owed(7, time() + 600);
        self::gate(false);
        $this->assertFalse(SecondFactorGate::stillOwed(), 'the switch off');
        $this->assertFalse(SecondFactorGate::stillOwed(), 'nothing owed at all');
        $_SESSION[SecondFactorGate::OWED]   = ['user' => 7, 'until' => time() + 600];
        $_SESSION[SecondFactorGate::LOCKED] = time() + 60;
        SecondFactorGate::settle(7);
        $this->assertArrayNotHasKey(SecondFactorGate::OWED, $_SESSION, 'settled: nothing owed');
        $this->assertSame(0, SecondFactorGate::lockedFor());
    }

    /**
     * Asked before the application exists (FW-173) — a front controller deciding on its page
     * cache — the policy is unknown: the session is still owed and nothing is forgotten, so the
     * question asked once the application is up still sends it to the step.
     */
    public function testAskedBeforeTheApplicationNothingIsForgotten(): void
    {
        // Arrange
        $_SESSION = ['loginflow_pending_userid' => 7, 'loginflow_pending_time' => time(),
            SecondFactorGate::OWED => ['user' => 7, 'until' => time() + 600]];
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, []);

        // Act
        $before = SecondFactorGate::stillOwed();
        $kept   = $_SESSION[SecondFactorGate::OWED] ?? null;
        self::gate(true);
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/el', 'HTTP_ACCEPT' => 'text/html'];
        $after = SecondFactorGate::stillOwed();

        // Assert
        $this->assertTrue($before, 'not signed in, whatever the policy turns out to be');
        $this->assertSame(7, $kept['user'] ?? null, 'and nothing forgotten');
        $this->assertTrue($after, 'the application up: still owed, sent to the step');
        $this->assertSame('/el', $_SESSION[SecondFactorGate::RETURN]);
    }
}
