<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Account;

/**
 * `Account::register()` — the self-service sign-up leg.
 *
 * WHAT: the branching around creating an account, driven with every database and
 *       view boundary replaced, so no Application, connection or session is
 *       needed.
 * WHY:  this is an unauthenticated endpoint that writes a row to the users table.
 *       The order of its guards is the whole security story — it must refuse
 *       outright when registration is closed, refuse a POST without a CSRF token
 *       *before* validating anything, never reach the database on invalid input,
 *       and never create an account it did not fully validate. It also must not
 *       hand out a privilege level.
 */
class AccountRegistrationTest extends TestCase
{
    private RegisteringAccount $c;

    protected function setUp(): void
    {
        $_POST                     = [];
        $_GET                      = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->c                   = new RegisteringAccount(null);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Fills the request with a submission that would otherwise succeed.
     *
     * Each test then breaks exactly one thing, so a failure names its cause.
     *
     * @param array<string, string> $overrides Fields to change or add
     */
    private function validPost(array $overrides = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = array_merge([
            'username'         => 'alice',
            'email'            => 'alice@example.com',
            'password'         => 'sup3rsecret!',
            'confirm_password' => 'sup3rsecret!',
        ], $overrides);
    }

    // ── The gate ──────────────────────────────────────────────────────────────

    /**
     * With registration closed, a GET says so and does not offer a form.
     *
     * The page renders rather than 404s because the navigation and the bundled
     * views link to it; the view hides the form on the `registrationOpen` flag.
     */
    public function testAClosedServerRendersTheClosedNotice(): void
    {
        // Arrange
        $this->c->open = false;

        // Act
        $out = $this->c->register();

        // Assert
        $this->assertSame('VIEW:register', $out);
        $this->assertSame('registration_closed', $this->c->view->props['error']);
        $this->assertFalse($this->c->view->props['registrationOpen']);
    }

    /**
     * With registration closed, a POST creates nothing.
     *
     * The most important assertion in this file: the gate is checked before the
     * request body is looked at, so a crafted POST to a server that never opened
     * registration cannot write a user.
     */
    public function testAClosedServerIgnoresAPost(): void
    {
        // Arrange
        $this->c->open = false;
        $this->validPost();

        // Act
        $this->c->register();

        // Assert
        $this->assertSame([], $this->c->created, 'a closed server must not create an account');
    }

    /** An already-authenticated visitor is sent on rather than shown the form. */
    public function testASignedInVisitorIsRedirected(): void
    {
        // Arrange
        $this->c->userId = 42;

        // Act
        $out = $this->c->register();

        // Assert
        $this->assertNull($out);
        $this->assertNotSame([], $this->c->redirects);
        $this->assertSame([], $this->c->created);
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    /**
     * A POST without a valid token never reaches validation or the database.
     *
     * Asserted on `lookups` as well as on `created`: a CSRF failure that still
     * probed for the username would turn the form into an account-existence
     * oracle that needs no token at all.
     */
    public function testAPostWithoutAValidTokenTouchesNothing(): void
    {
        // Arrange
        $this->c->csrf = false;
        $this->validPost();

        // Act
        $out = $this->c->register();

        // Assert
        $this->assertSame('invalid_token', $this->c->view->props['error']);
        $this->assertSame([], $this->c->created);
        $this->assertSame([], $this->c->lookups);
    }

    // ── Field validation ──────────────────────────────────────────────────────

    /**
     * Invalid input is rejected without a database lookup.
     *
     * One case per rule, and each asserts that `lookups` stayed empty — cheap
     * validation before an expensive query is the difference between a form and
     * a way to make the database work for free.
     *
     * @param string $field The field to corrupt
     * @param string $value The corrupt value
     * @param string $error The expected error key
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSubmissions')]
    public function testInvalidInputIsRejectedBeforeAnyQuery(
        string $field,
        string $value,
        string $error
    ): void {
        // Arrange
        $this->validPost([$field => $value]);

        // Act
        $out = $this->c->register();

        // Assert
        $this->assertSame('VIEW:register', $out);
        $this->assertSame($error, $this->c->view->props['error']);
        $this->assertSame([], $this->c->lookups, 'validation must not query the database');
        $this->assertSame([], $this->c->created);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidSubmissions(): array
    {
        return [
            'empty username'      => ['username', '', 'username_required'],
            'username too short'  => ['username', 'ab', 'username_length'],
            'username with space' => ['username', 'al ice', 'username_invalid'],
            'username with slash' => ['username', 'a/../b', 'username_invalid'],
            'empty email'         => ['email', '', 'invalid_email'],
            'malformed email'     => ['email', 'not-an-email', 'invalid_email'],
            'short password'      => ['password', 'ab1!', 'password_too_short'],
            'password no digit'   => ['password', 'abcdefgh!', 'password_needs_digit'],
            'password no symbol'  => ['password', 'abcdefgh1', 'password_needs_symbol'],
        ];
    }

    /**
     * A mismatched confirmation is rejected.
     *
     * Separate from the provider because it corrupts the *second* password field
     * while the first stays valid.
     */
    public function testAMismatchedConfirmationIsRejected(): void
    {
        // Arrange
        $this->validPost(['confirm_password' => 'something-else1!']);

        // Act
        $this->c->register();

        // Assert
        $this->assertSame('passwords_do_not_match', $this->c->view->props['error']);
        $this->assertSame([], $this->c->created);
    }

    /**
     * A rejected submission comes back with the username and email filled in,
     * and never with the password.
     *
     * Re-typing an email address after a password typo is how a form loses
     * people; re-rendering the password would put a credential in the HTML.
     */
    public function testARejectedSubmissionKeepsTheFieldsButNotThePassword(): void
    {
        // Arrange
        $this->validPost(['confirm_password' => 'mismatch1!']);

        // Act
        $this->c->register();

        // Assert
        $this->assertSame(
            ['username' => 'alice', 'email' => 'alice@example.com'],
            $this->c->view->props['formData']
        );
        $this->assertStringNotContainsString(
            'sup3rsecret',
            json_encode($this->c->view->props, JSON_THROW_ON_ERROR)
        );
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    /** A taken username is refused, and says so — you cannot pick another blind. */
    public function testATakenUsernameIsRefused(): void
    {
        // Arrange
        $this->c->takenUsernames = ['alice'];
        $this->validPost();

        // Act
        $this->c->register();

        // Assert
        $this->assertSame('username_taken', $this->c->view->props['error']);
        $this->assertSame([], $this->c->created);
    }

    /**
     * A known email is refused with wording that does not confirm it.
     *
     * The username case has to reveal itself for the form to be usable at all.
     * This one does not, so it does not: `email_unavailable` reads the same
     * whether the address is registered or simply not allowed.
     */
    public function testAKnownEmailIsRefusedWithoutConfirmingIt(): void
    {
        // Arrange
        $this->c->knownEmails = ['alice@example.com' => 9];
        $this->validPost();

        // Act
        $this->c->register();

        // Assert
        $this->assertSame('email_unavailable', $this->c->view->props['error']);
        $this->assertSame([], $this->c->created);
    }

    // ── Success ───────────────────────────────────────────────────────────────

    /**
     * A valid submission creates the account and sends the visitor to sign in.
     *
     * It deliberately does **not** establish a session: signing in is the login
     * flow's job, and it is the leg that applies the lockout, the second factor
     * and the sign-in alert.
     */
    public function testAValidSubmissionCreatesTheAccountAndRedirectsToLogin(): void
    {
        // Arrange
        $this->validPost();

        // Act
        $out = $this->c->register();

        // Assert
        $this->assertNull($out);
        $this->assertSame(
            [['alice', 'alice@example.com', 'sup3rsecret!']],
            $this->c->created
        );
        $this->assertNotSame([], $this->c->redirects);
        $this->assertStringEndsWith('login', (string) $this->c->redirects[0]);
    }

    /**
     * A failure to persist is reported, not swallowed.
     *
     * Without this the visitor is redirected to a login page for an account that
     * was never created, and told their password is wrong.
     */
    public function testAFailedInsertIsReported(): void
    {
        // Arrange
        $this->c->createReturns = null;
        $this->validPost();

        // Act
        $out = $this->c->register();

        // Assert
        $this->assertSame('VIEW:register', $out);
        $this->assertSame('registration_failed', $this->c->view->props['error']);
        $this->assertSame([], $this->c->redirects);
    }

    // ── The gate's default ────────────────────────────────────────────────────

    /**
     * With no setting at all, registration is closed.
     *
     * This is the assertion that keeps an upgrade from opening a sign-up page on
     * an application that never had one.
     */
    public function testRegistrationIsClosedWhenTheSettingIsAbsent(): void
    {
        // Arrange
        $account = new SettingsAccount(null);

        // Act / Assert
        $this->assertFalse($account->openPublic());
    }

    /**
     * The setting accepts the spellings a human writes.
     *
     * A settings table holds whatever somebody typed. `1` works and `true` does
     * not would be a support thread, not a feature.
     */
    public function testTheSettingAcceptsTheUsualTruthySpellings(): void
    {
        // Arrange
        $account = new SettingsAccount(null);

        foreach (['1', 'true', 'TRUE', 'yes', 'On'] as $value) {
            // Act
            $account->settings['auth_allow_registration'] = $value;

            // Assert
            $this->assertTrue($account->openPublic(), $value . ' must read as open');
        }

        foreach (['', '0', 'false', 'no', 'off', 'maybe'] as $value) {
            $account->settings['auth_allow_registration'] = $value;
            $this->assertFalse($account->openPublic(), $value . ' must read as closed');
        }
    }
}

/**
 * Account with every registration boundary replaced.
 *
 * `created` and `lookups` are the two recorders that make the security
 * assertions possible: they show what the action would have written and what it
 * would have asked the database, without a database being present.
 */
class RegisteringAccount extends Account
{
    public bool $open = true;
    public bool $csrf = true;
    public ?int $userId = null;
    public ?int $createReturns = 101;

    /** @var list<string> Usernames that already exist */
    public array $takenUsernames = [];

    /** @var array<string, int> Email address → user id */
    public array $knownEmails = [];

    /** @var list<array{0: string, 1: string, 2: string}> createUser() calls */
    public array $created = [];

    /** @var list<string> Uniqueness questions asked of the database */
    public array $lookups = [];

    /** @var list<string> Captured redirects */
    public array $redirects = [];

    public StubAccountView $view;
    public object $doc;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->view = new StubAccountView();
        $this->doc  = new class {
            public string $title = '';
        };
        parent::__construct($application);
    }

    protected function registrationIsOpen(): bool
    {
        return $this->open;
    }

    protected function checkCsrf(): bool
    {
        return $this->csrf;
    }

    protected function currentUserId(): ?int
    {
        return $this->userId;
    }

    protected function usernameExists(string $username): bool
    {
        $this->lookups[] = 'username:' . $username;
        return in_array($username, $this->takenUsernames, true);
    }

    protected function findUserIdByEmail(string $email): ?int
    {
        $this->lookups[] = 'email:' . $email;
        return $this->knownEmails[$email] ?? null;
    }

    protected function createUser(string $username, string $email, string $password): ?int
    {
        $this->created[] = [$username, $email, $password];
        return $this->createReturns;
    }

    protected function brand(): array
    {
        return ['name' => 'Acme ID', 'logo' => '', 'primary_color' => '#111', 'footer' => ''];
    }

    // The standalone layout asks the document for its theme object; the stub
    // document has none, and the real method would look for one.
    protected function useStandaloneLayout(): void
    {
    }

    public function &getView($name = '', $type = '', $args = array())
    {
        return $this->view;
    }

    protected function document(): object
    {
        return $this->doc;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirects[] = $url;
    }
}

/** Account whose settings are injectable, to drive the registration gate. */
class SettingsAccount extends Account
{
    /** @var array<string, string> */
    public array $settings = [];

    public function openPublic(): bool
    {
        return $this->registrationIsOpen();
    }

    protected function setting(string $key): string
    {
        return $this->settings[$key] ?? '';
    }
}
