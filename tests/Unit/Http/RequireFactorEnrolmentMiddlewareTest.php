<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\RequireFactorEnrolmentMiddleware;
use Pramnos\Http\Request;

/**
 * The wall that walks a privileged account to the second-factor setup screen.
 *
 * Two halves, and the dangerous one is the allow-list. A wall in front of every page has to
 * leave open the doors that lead out of it — the setup screens, the sign-in flow, signing out
 * — or it is not a wall, it is a lockout with a redirect loop. So that is what most of this
 * file is about.
 */
#[CoversClass(RequireFactorEnrolmentMiddleware::class)]
class RequireFactorEnrolmentMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['factor_enrolment_required'], $_SESSION['logged'], $_SESSION['uid']);

        // The policy and the cached identity are application-wide, so a test that set either has
        // to put it back — otherwise every later test in the run inherits this one's floor.
        $app = \Pramnos\Application\Application::getInstance();
        if ($this->hadAuth) {
            $app->applicationInfo['auth'] = $this->savedAuth;
        } else {
            unset($app->applicationInfo['auth']);
        }
        $this->hadAuth    = false;
        $this->savedAuth  = null;
        $app->currentUser = null;

        parent::tearDown();
    }

    /**
     * A middleware whose decision is fixed, so the pipeline behaviour can be asserted.
     */
    private function middleware(bool $mustEnrol): RequireFactorEnrolmentMiddleware
    {
        return new class ($mustEnrol) extends RequireFactorEnrolmentMiddleware {
            public function __construct(private bool $decision)
            {
                parent::__construct();
            }

            protected function mustEnrol(Request $request): bool
            {
                return $this->decision;
            }
        };
    }

    /**
     * A probe that exposes the path decision.
     */
    private function pathProbe(array $extra = []): object
    {
        return new class ($extra) extends RequireFactorEnrolmentMiddleware {
            public function __construct(array $extra)
            {
                parent::__construct($extra);
            }

            public function allows(string $uri): bool
            {
                return $this->isAllowed($uri);
            }
        };
    }

    /**
     * When nothing is required, the request goes through untouched.
     */
    public function testAnUnaffectedRequestPassesThrough(): void
    {
        // Act
        $result = $this->middleware(false)->handle(new Request(), static fn (): string => 'the page');

        // Assert
        $this->assertSame('the page', $result);
        $this->assertArrayNotHasKey('factor_enrolment_required', $_SESSION ?? []);
    }

    /**
     * When it is required, the pipeline stops and the session is flagged.
     *
     * Stopping matters more than the redirect: an operator action that must not run is one
     * whose request has to end here, and returning null is what `MiddlewareInterface`
     * documents as short-circuiting.
     */
    public function testAGatedRequestIsStoppedAndFlagged(): void
    {
        // Arrange
        $reached = false;

        // Act
        $result = $this->middleware(true)->handle(
            new Request(),
            static function () use (&$reached): string {
                $reached = true;

                return 'the page';
            }
        );

        // Assert
        $this->assertNull($result);
        $this->assertFalse($reached, 'the action behind the wall must not run');
        $this->assertTrue($_SESSION['factor_enrolment_required'] ?? false);
    }

    /**
     * The doors out of the wall are open.
     *
     * Each of these is a lockout on its own if it is closed: the setup screen is the
     * destination, the sign-in flow has to be able to finish, and a wall somebody cannot
     * sign out of is a trap.
     */
    public function testTheDoorsOutAreOpen(): void
    {
        // Arrange
        $probe = $this->pathProbe();

        // Act & Assert
        foreach ([
            'TwoFactorAuth/setup',
            'twofactorauth',
            'Passkey/register',
            'login',
            'login/verify',
            'logout',
            'account/security',
            'api/1.0/account/login',
            '.well-known/openid-configuration',
            'health/check',
            'assets/css/style.css',
        ] as $path) {
            $this->assertTrue($probe->allows($path), $path . ' must not be gated');
        }
    }

    /**
     * Everything else is gated, including the front page and the administration area.
     */
    public function testTheRestIsGated(): void
    {
        // Arrange
        $probe = $this->pathProbe();

        // Act & Assert
        foreach (['', 'admin', 'admin/Users/view/2', 'Emails', 'dashboard'] as $path) {
            $this->assertFalse($probe->allows($path), $path . ' must be gated');
        }
    }

    /**
     * The match is on a whole path segment, not a substring.
     *
     * `logo.png` is not `logout`, and `logins-report` is not `login`. A substring test on a
     * list this short is how an exemption ends up quietly wider than it reads — and every
     * one of these exemptions is a page a privileged account with no factor can reach.
     */
    public function testASubstringIsNotAMatch(): void
    {
        // Arrange
        $probe = $this->pathProbe();

        // Act & Assert
        $this->assertFalse($probe->allows('logo.png'));
        $this->assertFalse($probe->allows('logins-report'));
        $this->assertFalse($probe->allows('accounts'));
        $this->assertFalse($probe->allows('apidocs'));
    }

    /**
     * A query string does not change the decision.
     */
    public function testAQueryStringIsIgnored(): void
    {
        // Arrange
        $probe = $this->pathProbe();

        // Act & Assert
        $this->assertTrue($probe->allows('login?return=/admin'));
        $this->assertFalse($probe->allows('admin?tab=users'));
    }

    /**
     * An application can leave its own paths open.
     */
    public function testAnApplicationCanOpenItsOwnDoors(): void
    {
        // Arrange
        $probe = $this->pathProbe(['Support', 'Docs']);

        // Act & Assert
        $this->assertTrue($probe->allows('support/contact'));
        $this->assertTrue($probe->allows('docs'));
        $this->assertFalse($probe->allows('admin'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // The decision itself
    // ────────────────────────────────────────────────────────────────────────

    /*
     * Everything above fixes the decision and asserts the pipeline. Which left the decision —
     * five short-circuits and a fail-open `catch`, twenty-two statements — with no covered line
     * at all: `handle()` was tested against a subclass that replaced `mustEnrol()`, and a test
     * that replaces a method is not a test of it.
     *
     * These use the seam the constructor already offers instead. `FactorEnrolment` is one level
     * *below* the decision, so overriding it leaves every branch of the decision running.
     */

    /** @var array<string, mixed>|null */
    private mixed $savedAuth = null;

    private bool $hadAuth = false;

    /** Sets the usertype floor the policy reads, and remembers what was there. */
    private function floorAt(int $usertype): void
    {
        $app = \Pramnos\Application\Application::getInstance();

        if (!$this->hadAuth) {
            $this->hadAuth   = isset($app->applicationInfo['auth']);
            $this->savedAuth = $app->applicationInfo['auth'] ?? null;
        }

        $app->applicationInfo['auth'] = [
            'security' => ['require_factor_enrolment_from_usertype' => $usertype],
        ];
    }

    /** Signs somebody in, as `staticIsLogged()` and `getCurrentUser()` see it. */
    private function signIn(int $userid, int $usertype): void
    {
        $user = new \Pramnos\User\User();
        $user->userid   = $userid;
        $user->username = 'someone';
        $user->usertype = $usertype;

        $app = \Pramnos\Application\Application::getInstance();
        $app->currentUser = $user;

        // Both keys, and a uid above 1 — `1` is the anonymous account.
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = $userid;
    }

    /**
     * A middleware wired to an enrolment service that answers as the test says.
     *
     * @param bool|\Throwable $answer What `isRequiredFor()` returns, or throws
     */
    private function withEnrolment(bool|\Throwable $answer, array $allowed = array()): RequireFactorEnrolmentMiddleware
    {
        $enrolment = new class ($answer) extends \Pramnos\Auth\FactorEnrolment {
            /** @var list<array{0: int, 1: int}> */
            public array $asked = [];

            public function __construct(private readonly bool|\Throwable $answer)
            {
            }

            public function isRequiredFor(int $userId, int $usertype): bool
            {
                $this->asked[] = [$userId, $usertype];

                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };

        return new RequireFactorEnrolmentMiddleware($allowed, 'TwoFactorAuth/setup', $enrolment);
    }

    /** Runs a request through and reports whether it reached the application. */
    private function passedThrough(RequireFactorEnrolmentMiddleware $middleware, string $uri = 'orders'): bool
    {
        $_SERVER['REQUEST_URI'] = '/' . ltrim($uri, '/');
        $reached                = false;

        $middleware->handle(new Request(), function () use (&$reached) {
            $reached = true;

            return 'the page';
        });

        return $reached;
    }

    /**
     * With the feature off, nothing is gated — whatever the enrolment service would say.
     *
     * The floor is the switch, and it defaults to `0`. An installation that has not asked for
     * this must not have it, or upgrading the framework locks its administrators out of their own
     * site.
     */
    public function testWithNoFloorConfiguredNothingIsGated(): void
    {
        // Arrange — the service would say yes, and must not be asked
        $this->floorAt(0);
        $this->signIn(42, 100);

        // Act + Assert
        $this->assertTrue($this->passedThrough($this->withEnrolment(true)));
    }

    /**
     * A visitor who is not signed in is not gated.
     *
     * There is nothing to enrol for somebody with no account, and redirecting a guest to a setup
     * screen would put the sign-in flow behind a page that requires signing in.
     */
    public function testAGuestIsNotGated(): void
    {
        // Arrange
        $this->floorAt(80);
        unset($_SESSION['logged'], $_SESSION['uid']);
        \Pramnos\Application\Application::getInstance()->currentUser = null;

        // Act + Assert
        $this->assertTrue($this->passedThrough($this->withEnrolment(true)));
    }

    /**
     * A signed-in account the service says must enrol is stopped and flagged.
     *
     * The whole point of the middleware, and the first time the real decision has run. The flag
     * is what the setup screen reads to explain why somebody is looking at it.
     */
    public function testAnAccountThatMustEnrolIsStopped(): void
    {
        // Arrange
        $this->floorAt(80);
        $this->signIn(42, 100);

        // Act
        $reached = $this->passedThrough($this->withEnrolment(true));

        // Assert
        $this->assertFalse($reached, 'a privileged account with no factor reached the page');
        $this->assertTrue($_SESSION['factor_enrolment_required'] ?? false);
    }

    /**
     * The account's own id and usertype are what the service is asked about.
     *
     * Not the session's copy of them: a stale `$_SESSION['user']` is how an account that was
     * demoted keeps a privilege it no longer has, and the decision has to be made about the
     * account as it is now.
     */
    public function testTheServiceIsAskedAboutTheCurrentAccount(): void
    {
        // Arrange
        $this->floorAt(80);
        $this->signIn(4242, 95);
        $middleware = $this->withEnrolment(false);

        // Act
        $this->passedThrough($middleware);

        // Assert
        $enrolment = (new \ReflectionProperty($middleware, 'enrolment'))->getValue($middleware);
        $this->assertSame([[4242, 95]], $enrolment->asked);
    }

    /**
     * An account the service clears is let through.
     *
     * The counterpart, and the control: without it, a middleware that gated everybody would pass
     * every other test here.
     */
    public function testAnAccountThatHasEnrolledIsLetThrough(): void
    {
        // Arrange
        $this->floorAt(80);
        $this->signIn(42, 100);

        // Act + Assert
        $this->assertTrue($this->passedThrough($this->withEnrolment(false)));
    }

    /**
     * A request on the way out of the wall is never even asked about.
     *
     * The allow-list short-circuits before the service is consulted, which matters for more than
     * speed: the setup screen is itself a gated path, so a decision that ran first and redirected
     * would send the setup screen to the setup screen, for ever.
     */
    public function testADoorOutOfTheWallIsNotAsked(): void
    {
        // Arrange
        $this->floorAt(80);
        $this->signIn(42, 100);
        $middleware = $this->withEnrolment(true);

        // Act
        $reached = $this->passedThrough($middleware, 'TwoFactorAuth/setup');

        // Assert
        $this->assertTrue($reached, 'the setup screen was redirected to itself');

        $enrolment = (new \ReflectionProperty($middleware, 'enrolment'))->getValue($middleware);
        $this->assertSame([], $enrolment->asked, 'the service was consulted for an exempt path');
    }

    /**
     * A decision that cannot be made lets the request through.
     *
     * **Fails open, deliberately**, and it is the opposite of the human check on the sign-in form.
     * The difference is what each failure costs: a broken human check refuses new submissions,
     * while a broken enrolment check would redirect *every page* of the site to a setup screen —
     * including, if the allow-list were ever wrong, the screen itself. Locking an installation out
     * of itself because a lookup failed is worse than a privileged account going one more request
     * without a second factor, and the log line is how anybody finds out.
     */
    public function testADecisionThatCannotBeMadeLetsTheRequestThrough(): void
    {
        // Arrange
        $this->floorAt(80);
        $this->signIn(42, 100);

        // Act + Assert
        $this->assertTrue(
            $this->passedThrough($this->withEnrolment(new \RuntimeException('the store is down'))),
            'a failed enrolment lookup gated the request, which locks the site out of itself'
        );
        $this->assertArrayNotHasKey('factor_enrolment_required', $_SESSION);
    }

}
