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
        unset($_SESSION['factor_enrolment_required']);

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
}
