<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\InvitationsController;
use Pramnos\Auth\InvitationException;
use Pramnos\Auth\Invitations;
use Pramnos\Framework\Factory;

/**
 * The refusal branches {@see \Pramnos\Tests\Integration\Auth\InvitationsTest}'s real-sign-in
 * tests cannot reach.
 *
 * `redirect()` throws `ApplicationClosedException` under this suite's `Application` — the
 * mechanism every "screen" test in that file relies on to stop at the redirect — which means
 * the single statement immediately *after* a `redirect()` call (the `return false`/`return
 * null` guarding the rest of the method) never actually executes when `redirect()` runs for
 * real: the exception unwinds past it. These tests replace `redirect()` with one that records
 * instead of throwing, so that statement runs like it would outside a test.
 */
#[CoversClass(InvitationsController::class)]
class InvitationsControllerDeniedAndEmptyTest extends TestCase
{
    private function controller(bool $deny = false, ?Invitations $service = null, bool $mayGiveRoles = true): object
    {
        return new class ($deny, $service, $mayGiveRoles) extends InvitationsController {
            public ?string $to = null;

            /** @var list<string> */
            public array $said = [];

            public function __construct(
                private bool $deny,
                private ?Invitations $fakeService,
                private bool $mayGiveRolesAnswer
            ) {
            }

            protected function requireMinUserType(int $minType): bool
            {
                return $this->deny;
            }

            protected function mayGiveRoles(): bool
            {
                return $this->mayGiveRolesAnswer;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->to = (string) $url;
            }

            protected function addError($error)
            {
                $this->said[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                $this->said[] = (string) $message;

                return $this;
            }

            protected function service(): Invitations
            {
                return $this->fakeService ?? parent::service();
            }

            public function exposeValidPost(): bool
            {
                return (new \ReflectionMethod(InvitationsController::class, 'validPost'))->invoke($this);
            }

            public function exposeOrganizations(): array
            {
                return $this->organizations();
            }

            public function exposeRoles(): array
            {
                return $this->roles();
            }
        };
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    /** Denied, `display()` renders nothing — the real question is that it stops there. */
    public function testDisplayDeniedRendersNothing(): void
    {
        // Arrange
        $controller = $this->controller(deny: true);

        // Act
        $result = $controller->display();

        // Assert
        $this->assertNull($result);
    }

    /** Denied, `invite()` never asks the service. */
    public function testInviteDeniedNeverAsksTheService(): void
    {
        // Arrange
        $service = new class extends Invitations {
            public bool $asked = false;

            public function __construct()
            {
            }

            public function invite(string $email, array $options = []): array
            {
                $this->asked = true;

                return ['link' => '', 'sent' => true];
            }
        };
        $controller = $this->controller(deny: true, service: $service);

        // Act
        $controller->invite();

        // Assert
        $this->assertFalse($service->asked);
    }

    /** Denied, `resend()` never asks the service. */
    public function testResendDeniedNeverAsksTheService(): void
    {
        // Arrange
        $service = new class extends Invitations {
            public bool $asked = false;

            public function __construct()
            {
            }

            public function resend(int $invitationId): ?array
            {
                $this->asked = true;

                return null;
            }
        };
        $controller = $this->controller(deny: true, service: $service);

        // Act
        $controller->resend();

        // Assert
        $this->assertFalse($service->asked);
    }

    /** Denied, `revoke()` never asks the service. */
    public function testRevokeDeniedNeverAsksTheService(): void
    {
        // Arrange
        $service = new class extends Invitations {
            public bool $asked = false;

            public function __construct()
            {
            }

            public function revoke(int $invitationId): bool
            {
                $this->asked = true;

                return true;
            }
        };
        $controller = $this->controller(deny: true, service: $service);

        // Act
        $controller->revoke();

        // Assert
        $this->assertFalse($service->asked);
    }

    /** A GET is refused before the CSRF token is even read. */
    public function testValidPostRefusesAGet(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->controller();

        // Act & Assert
        $this->assertFalse($controller->exposeValidPost());
        $this->assertStringEndsWith('Invitations', $controller->to);
    }

    /** A POST with a wrong or missing token is refused, and told so. */
    public function testValidPostRefusesABadToken(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_csrf_token'] = 'not-a-real-token';
        $controller = $this->controller();

        // Act & Assert
        $this->assertFalse($controller->exposeValidPost());
        $this->assertStringEndsWith('Invitations', $controller->to);
        $this->assertSame(['That form had expired. Please try again.'], $controller->said);
    }

    /**
     * A role requested by somebody who may not give roles is refused before the service is
     * ever asked, and nothing from the request reaches it.
     */
    public function testARoleFromSomebodyWhoMayNotGiveRolesIsRefused(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $service = new class extends Invitations {
            public bool $asked = false;

            public function __construct()
            {
            }

            public function invite(string $email, array $options = []): array
            {
                $this->asked = true;

                return ['link' => '', 'sent' => true];
            }
        };
        $controller = $this->controller(service: $service, mayGiveRoles: false);
        $_POST['_csrf_token'] = $this->realToken();
        $_POST['email']       = 'someone@example.com';
        $_POST['role_id']     = '5';

        // Act
        $controller->invite();

        // Assert
        $this->assertFalse($service->asked, 'the service must not be asked when the role is refused first');
        $this->assertSame(
            ['You may not give roles, so the invitation cannot carry one.'],
            $controller->said
        );
        $this->assertStringEndsWith('Invitations', $controller->to);
    }

    /**
     * An invitation the service refuses (a bad address, an existing account, an unknown
     * role) is reported, and the screen stops rather than setting a fresh link that was
     * never made.
     */
    public function testAServiceRefusalIsReportedAndStopsThere(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $service = new class extends Invitations {
            public function __construct()
            {
            }

            public function invite(string $email, array $options = []): array
            {
                throw new InvitationException('account_exists', 'That address already has an account.');
            }
        };
        $controller = $this->controller(service: $service);
        $_POST['_csrf_token'] = $this->realToken();
        $_POST['email']       = 'someone@example.com';

        // Act
        $controller->invite();

        // Assert
        $this->assertSame(['That address already has an account.'], $controller->said);
        $this->assertStringEndsWith('Invitations', $controller->to);
        $this->assertArrayNotHasKey('invitations_fresh_link', $_SESSION);
    }

    /** With no `organizations` table at all, the screen offers none rather than failing. */
    public function testOrganizationsIsEmptyWithoutTheTable(): void
    {
        // Arrange
        $this->withNeitherTable(function (): void {
            // Act & Assert
            $this->assertSame([], $this->controller()->exposeOrganizations());
        });
    }

    /** With no `authserver.roles` table at all, the screen offers no roles rather than failing. */
    public function testRolesIsEmptyWithoutTheTable(): void
    {
        // Arrange
        $this->withNeitherTable(function (): void {
            // Act & Assert
            $this->assertSame([], $this->controller()->exposeRoles());
        });
    }

    /**
     * Runs `$callback` against a throwaway database carrying only `users` — neither
     * `organizations` nor `authserver.roles` — swapped in for `Factory::getDatabase()` and
     * swapped back out afterwards whatever `$callback` does.
     */
    private function withNeitherTable(\Closure $callback): void
    {
        $admin = new \Pramnos\Database\Database();
        $admin->type     = 'mysql';
        $admin->server   = 'db';
        $admin->user     = 'root';
        $admin->password = 'secret';
        $admin->database = TEST_DATABASE;
        $admin->port     = 3306;
        if (!$admin->connect(false)) {
            $this->markTestSkipped('db not reachable');
        }

        $name = 'pramnos_invitations_no_org_probe';
        $admin->query('DROP DATABASE IF EXISTS ' . $name);
        $admin->query('CREATE DATABASE ' . $name);

        $probe = new \Pramnos\Database\Database();
        $probe->type     = 'mysql';
        $probe->server   = 'db';
        $probe->user     = 'root';
        $probe->password = 'secret';
        $probe->database = $name;
        $probe->port     = 3306;
        $probe->connect(false);
        \Pramnos\Framework\Testing\Schema::ensure(
            [\Pramnos\Framework\Migrations\Auth\CreateUsersTable::class],
            $probe
        );

        $reference = &Factory::getDatabase();
        $previous  = $reference;
        $reference = $probe;

        try {
            $callback();
        } finally {
            $reference = $previous;
            $probe->close();
            $admin->query('DROP DATABASE IF EXISTS ' . $name);
            $admin->close();
        }
    }

    private function realToken(): string
    {
        return \Pramnos\Http\Session::getInstance()->getCsrfToken();
    }
}
