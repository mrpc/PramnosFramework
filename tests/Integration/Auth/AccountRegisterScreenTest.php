<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * Self-service registration — 17 statements, and the one screen that creates an account.
 *
 * Off unless `auth_allow_registration` says otherwise, which is the first thing asserted: a
 * scaffolded application must not gain an open sign-up page by being upgraded. With the setting
 * off the form still renders and says registration is closed, rather than 404-ing a page the
 * navigation links to.
 *
 * When it is on, this is a **public write that creates a row and sends a mail** — the form most
 * worth pricing, and every refusal in front of it is asserted for that reason: the anti-CSRF
 * token, the human check, the field validation, the password policy, and the two "already taken"
 * cases.
 *
 * The one that is not about spam is `testNothingInTheFormCanGrantAPrivilege`. `createUser()` names
 * the five fields it sets and `usertype` is not among them, so a submission carrying one is
 * ignored. That is the whole of the protection, and it is the kind of line somebody later
 * "generalises" into a loop over `$_POST`.
 *
 * Both backends: {@see AccountRegisterScreenPostgreSQLTest} re-runs it. The duplicate checks are
 * two lookups whose "no row" answer decides whether an account is created.
 */
#[CoversClass(Account::class)]
class AccountRegisterScreenTest extends BaseTestCase
{
    private $db;

    /** Accounts this test created, removed in tearDown. */
    private array $created = [];

    private array $originalInfo = [];

    private const PASSWORD = 'a-perfectly-fine-password-4!';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $application = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        User::setupDb();

        $this->originalInfo = (array) $application->applicationInfo;

        \Pramnos\Http\RequestIdentity::reset();
        $this->created = [];

        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();

        $application = Application::currentInstance();
        if (is_object($application)) {
            $application->applicationInfo = $this->originalInfo;
        }

        foreach ($this->created as $username) {
            try {
                $row = $this->db->queryBuilder()->table('#PREFIX#users')
                    ->select(['userid'])->where('username', $username)->first();
                $userId = (int) ($row->fields['userid'] ?? 0);

                if ($userId > 0) {
                    $this->db->queryBuilder()->table('#PREFIX#userdetails')
                        ->where('userid', $userId)->delete();
                    $this->db->queryBuilder()->table('#PREFIX#users')
                        ->where('userid', $userId)->delete();
                }
            } catch (\Throwable $exception) {
                // Nothing to undo.
            }
        }
        $this->created = [];

        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
        User::clearUserCache();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── Whether it is open at all ─────────────────────────────────────────────

    /**
     * With the setting off — the default — no account can be created, and the form says why.
     *
     * A scaffolded application must not gain an open sign-up page by being upgraded. It renders
     * rather than 404s because the navigation links to it: a page that says "registration is
     * closed" is an answer, and a 404 on a linked page is a bug report.
     */
    public function testWithTheSettingOffNothingIsCreated(): void
    {
        // Arrange — nothing turned on.
        $probe = $this->probe(open: false);
        $username = $this->freshName();
        $this->postWithToken($this->submission($username));

        // Act
        $probe->register();

        // Assert
        $this->assertSame('registration_closed', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertFalse($this->accountExists($username), 'an account was created with sign-up off');
    }

    /** With it on, a GET renders an empty form and creates nothing. */
    public function testAGetRendersTheForm(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->register();

        // Assert
        $this->assertSame([], $probe->rendered[0]['ctx']);
    }

    /** Somebody already signed in is sent on rather than shown the form. */
    public function testASignedInVisitorIsSentOn(): void
    {
        // Arrange
        $existing = $this->seedAccount();
        \Pramnos\Http\RequestIdentity::seal(new User($existing), 'test');
        $probe = $this->probe();

        // Act
        $probe->register();

        // Assert
        $this->assertSame([], $probe->rendered);
        $this->assertNotSame([], $probe->redirects);
    }

    // ── The refusals in front of a public write ───────────────────────────────

    /**
     * Without the anti-CSRF token, nothing is created.
     *
     * This form writes a row and sends a mail on a request nobody has authenticated. The token is
     * what stops a page anybody writes from making every visitor's browser create an account.
     */
    public function testAPostWithoutTheTokenCreatesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $username = $this->freshName();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->submission($username);
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->register();

        // Assert
        $this->assertSame('invalid_token', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertFalse($this->accountExists($username));
    }

    /** A failed human check creates nothing, and the form comes back filled in. */
    public function testAFailedHumanCheckCreatesNothing(): void
    {
        // Arrange
        $probe = $this->probe(humanCheckPasses: false);
        $username = $this->freshName();
        $this->postWithToken($this->submission($username));

        // Act
        $probe->register();

        // Assert
        $this->assertSame('human_check', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame($username, $probe->rendered[0]['ctx']['formData']['username'] ?? null);
        $this->assertFalse($this->accountExists($username));
    }

    /**
     * A rejected submission never carries the password back.
     *
     * The rest of the form is echoed so a refusal does not empty it — retyping an address after
     * a failed check is how people give up. The password is not, because the form is rendered
     * into HTML that ends up in a browser's history, a proxy log and a bug report screenshot.
     */
    public function testTheEchoedFormNeverCarriesThePassword(): void
    {
        // Arrange
        $probe = $this->probe(humanCheckPasses: false);
        $username = $this->freshName();
        $this->postWithToken($this->submission($username));

        // Act
        $probe->register();

        // Assert
        $echoed = (string) json_encode($probe->rendered[0]['ctx'] ?? []);
        $this->assertStringNotContainsString(self::PASSWORD, $echoed, 'the password was echoed back');
    }

    /** A password that fails the policy creates nothing. */
    public function testAPasswordThatFailsThePolicyCreatesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $username = $this->freshName();
        $this->postWithToken([
            'username'         => $username,
            'email'            => $username . '@example.test',
            'password'         => 'x',
            'confirm_password' => 'x',
        ]);

        // Act
        $probe->register();

        // Assert
        $this->assertNotSame('', (string) ($probe->rendered[0]['ctx']['error'] ?? ''));
        $this->assertFalse($this->accountExists($username));
    }

    /** A confirmation that does not match creates nothing. */
    public function testAMismatchedConfirmationCreatesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $username = $this->freshName();
        $submission = $this->submission($username);
        $submission['confirm_password'] = self::PASSWORD . 'x';
        $this->postWithToken($submission);

        // Act
        $probe->register();

        // Assert
        $this->assertNotSame('', (string) ($probe->rendered[0]['ctx']['error'] ?? ''));
        $this->assertFalse($this->accountExists($username));
    }

    // ── Already taken ─────────────────────────────────────────────────────────

    /**
     * A username in use is refused, and the refusal says so.
     *
     * There is no way to both refuse a duplicate and not confirm the account exists: a form that
     * has to let a person pick a different name has to say why. So this reveals it, and the
     * mitigations are the ones that work — leave registration off when it is not needed, and keep
     * the login lockout, since the value of an enumerated username is what happens next.
     */
    public function testAUsernameInUseIsRefused(): void
    {
        // Arrange
        $taken = $this->seedAccountNamed();
        $probe = $this->probe();
        $this->postWithToken([
            'username'         => $taken,
            'email'            => 'a-different-address@example.test',
            'password'         => self::PASSWORD,
            'confirm_password' => self::PASSWORD,
        ]);

        // Act
        $probe->register();

        // Assert
        $this->assertSame('username_taken', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame(1, $this->countNamed($taken), 'a second account took the same username');
    }

    /**
     * An address in use is refused too, and the code for it is a different one.
     *
     * Worded so it does not add a second, independent confirmation: the username case already
     * reveals what it has to, and an address is the more valuable of the two to be able to test.
     */
    public function testAnAddressInUseIsRefused(): void
    {
        // Arrange
        $existing = $this->seedAccountNamed();
        $address  = $existing . '@example.test';

        $probe = $this->probe();
        $username = $this->freshName();
        $this->postWithToken([
            'username'         => $username,
            'email'            => $address,
            'password'         => self::PASSWORD,
            'confirm_password' => self::PASSWORD,
        ]);

        // Act
        $probe->register();

        // Assert
        $this->assertSame('email_unavailable', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertFalse($this->accountExists($username));
    }

    // ── What a new account is ─────────────────────────────────────────────────

    /**
     * A good submission creates an account that can sign in, and nothing more than that.
     *
     * Active and validated so the person can use it, and at the lowest privilege level — the
     * account exists to be signed into, not to be trusted.
     */
    public function testAGoodSubmissionCreatesAUsableAccount(): void
    {
        // Arrange
        $probe = $this->probe();
        $username = $this->freshName();
        $this->postWithToken($this->submission($username));

        // Act
        $probe->register();

        // Assert
        $this->assertSame([], $probe->rendered, 'the submission was refused: ' . json_encode($probe->rendered));
        $this->assertNotSame([], $probe->redirects);

        $row = $this->row($username);
        $this->assertNotNull($row, 'no account was created');
        $this->assertSame(1, (int) $row['active']);
        $this->assertSame(0, (int) $row['usertype']);
        $this->assertTrue(
            (new User((int) $row['userid']))->verifyPassword(self::PASSWORD),
            'the new account cannot sign in with the password it was given'
        );
    }

    /**
     * Nothing in the form can grant a privilege.
     *
     * `createUser()` names the fields it sets, and `usertype` is not among them — so a submission
     * carrying one is ignored rather than obeyed. That is the entire protection, and it is exactly
     * the kind of line somebody later replaces with a loop over the submitted fields.
     */
    public function testNothingInTheFormCanGrantAPrivilege(): void
    {
        // Arrange
        $probe = $this->probe();
        $username = $this->freshName();
        $this->postWithToken($this->submission($username) + [
            'usertype'  => '90',
            'validated' => '1',
            'userid'    => '1',
        ]);

        // Act
        $probe->register();

        // Assert
        $row = $this->row($username);
        $this->assertNotNull($row, 'no account was created');
        $this->assertSame(
            0,
            (int) $row['usertype'],
            'a registration form granted itself a usertype'
        );
        $this->assertGreaterThan(1, (int) $row['userid'], 'the submission chose its own id');
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the render and the switch under the test's control.
     *
     * `registrationIsOpen()` is a documented seam — "so a subclass can decide on something other
     * than a global flag, an invite code, a domain allow-list" — which is what makes it the right
     * place to steer this from rather than writing a setting into the database.
     */
    private function probe(bool $open = true, bool $humanCheckPasses = true): object
    {
        return new class ($this->db, $open, $humanCheckPasses) extends Account {
            /** @var list<array{ctx: array}> */
            public array $rendered = [];

            public array $redirects = [];

            public array $messages = [];

            public function __construct(
                \Pramnos\Database\Database $db,
                private bool $open,
                private bool $human
            ) {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            protected function renderRegister(array $ctx): mixed
            {
                $this->rendered[] = ['ctx' => $ctx];

                return null;
            }

            protected function registrationIsOpen(): bool
            {
                return $this->open;
            }

            protected function humanCheckPasses(string $form): bool
            {
                return $this->human;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }
        };
    }

    /** A username no fixture would collide with, remembered for cleanup. */
    private function freshName(): string
    {
        $username = 'register_' . bin2hex(random_bytes(5));
        $this->created[] = $username;

        return $username;
    }

    /** @return array<string, string> */
    private function submission(string $username): array
    {
        return [
            'username'         => $username,
            'email'            => $username . '@example.test',
            'password'         => self::PASSWORD,
            'confirm_password' => self::PASSWORD,
        ];
    }

    /** An account that already exists; returns its id. */
    private function seedAccount(): int
    {
        $user = new User();
        $user->username = $this->freshName();
        $user->email    = $user->username . '@example.test';
        $user->save();

        return (int) $user->userid;
    }

    /** The same, returning the username. */
    private function seedAccountNamed(): string
    {
        $username = $this->freshName();
        $user = new User();
        $user->username = $username;
        $user->email    = $username . '@example.test';
        $user->save();

        return $username;
    }

    /** @return array<string, mixed>|null */
    private function row(string $username): ?array
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('username', $username)->first();

        return $row && $row->numRows > 0 ? (array) $row->fields : null;
    }

    private function accountExists(string $username): bool
    {
        return $this->row($username) !== null;
    }

    private function countNamed(string $username): int
    {
        return (int) $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('username', $username)->count();
    }

    /** A POST carrying the session's anti-CSRF token. */
    private function postWithToken(array $fields): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields + [$session->getToken() => $session->getFingerprint()];
        \Pramnos\Http\Request::resetInstance();
    }
}
