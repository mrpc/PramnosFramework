<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;

/**
 * Unit tests for the public authentication-entry actions of the Account controller.
 *
 * WHAT: the login / verify / logout branching that a scaffolded auth server relies
 *       on, driven with a fake {@see LoginFlow} and a stub view/document so no
 *       Application, DB, or session is needed. The account-management actions
 *       inherited from the promoted controller keep their own (existing) tests.
 * WHY:  this controller is the untrusted public edge of the login flow. It must
 *       never establish a session on a bad password, must stop at the step-up form
 *       when a second factor is due (without leaking the password into the page),
 *       must refuse a step-up with no pending login, must reject an open-redirect
 *       `?return=`, and must enforce CSRF on every state-changing POST.
 */
class AccountControllerTest extends TestCase
{
    private TestableAccount $c;

    protected function setUp(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->c = new TestableAccount(null);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    // ── login(): GET / already-authenticated ────────────────────────────────────

    /** A GET renders the login form (no error) — the real render seam runs. */
    public function testLoginGetRendersForm(): void
    {
        $out = $this->c->login();

        $this->assertSame('VIEW:login', $out);
        $this->assertArrayNotHasKey('error', $this->c->view->props);
        $this->assertSame('Login', $this->c->doc->title);
    }

    /** An already-authenticated visitor is bounced to the dashboard, not the form. */
    public function testLoginWhenAlreadyLoggedInRedirects(): void
    {
        $this->c->userId = 7;

        $out = $this->c->login();

        $this->assertNull($out);
        $this->assertSame(['Account'], $this->c->redirects); // routeBase, no return url
    }

    // ── login(): POST credential leg ────────────────────────────────────────────

    /** A POST with a bad CSRF token never reaches the flow. */
    public function testLoginPostRejectsBadCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->c->csrf = false;
        $_POST = ['username' => 'alice', 'password' => 'secret'];

        $out = $this->c->login();

        $this->assertSame('VIEW:login', $out);
        $this->assertSame('invalid_token', $this->c->view->props['error']);
        $this->assertFalseAttempt();
    }

    /** Missing username/password short-circuits before the flow. */
    public function testLoginPostMissingCredentials(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'alice', 'password' => ''];

        $out = $this->c->login();

        $this->assertSame('VIEW:login', $out);
        $this->assertSame('missing_credentials', $this->c->view->props['error']);
        $this->assertFalseAttempt();
    }

    /** A correct password with no second factor establishes the session and redirects. */
    public function testLoginPostSuccessRedirects(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'alice', 'password' => 'secret', 'remember' => '1'];
        $this->c->flow->attemptResult = LoginFlowResult::success(7);

        $out = $this->c->login();

        $this->assertNull($out);
        $this->assertSame(['Account'], $this->c->redirects);
        // The plaintext password reached the flow untrimmed; remember flag parsed.
        $this->assertSame(['alice', 'secret', true], $this->c->flow->attemptArgs);
    }

    /** A second factor requirement renders the step-up form and establishes NO session. */
    public function testLoginPostStepUpRendersVerify(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'alice', 'password' => 'secret'];
        $this->c->flow->attemptResult = LoginFlowResult::stepUpRequired(7, ['twofactor', 'passkey']);

        $out = $this->c->login();

        $this->assertSame('VIEW:login_2fa', $out);
        $this->assertSame(['twofactor', 'passkey'], $this->c->view->props['methods']);
        $this->assertSame([], $this->c->redirects, 'no session/redirect on step-up');
    }

    /** A lockout re-renders the form with the remaining seconds. */
    public function testLoginPostLockedShowsRemaining(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'alice', 'password' => 'secret'];
        $this->c->flow->attemptResult = LoginFlowResult::locked(42);

        $out = $this->c->login();

        $this->assertSame('VIEW:login', $out);
        $this->assertSame('locked', $this->c->view->props['error']);
        $this->assertSame(42, $this->c->view->props['lockoutSeconds']);
    }

    /** Wrong credentials re-render the form with a generic error. */
    public function testLoginPostFailedShowsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'alice', 'password' => 'nope'];
        $this->c->flow->attemptResult = LoginFlowResult::failed();

        $out = $this->c->login();

        $this->assertSame('VIEW:login', $out);
        $this->assertSame('invalid_credentials', $this->c->view->props['error']);
    }

    // ── verify(): 2FA step-up completion ────────────────────────────────────────

    /** With no pending step-up, verify sends the user back to the login form. */
    public function testVerifyWithoutPendingReturnsToLogin(): void
    {
        $this->c->flow->pending = null;

        $out = $this->c->verify();

        $this->assertSame('VIEW:login', $out);
        $this->assertSame('session_expired', $this->c->view->props['error']);
    }

    /** A GET with a pending step-up renders the step-up form. */
    public function testVerifyGetRendersStepUp(): void
    {
        $this->c->flow->pending = 7;

        $out = $this->c->verify();

        $this->assertSame('VIEW:login_2fa', $out);
        $this->assertSame(7, $this->c->view->props['pendingUserId']);
    }

    /** A step-up POST with a bad CSRF token never verifies the code. */
    public function testVerifyPostRejectsBadCsrf(): void
    {
        $this->c->flow->pending = 7;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->c->csrf = false;
        $_POST = ['code' => '123456'];

        $out = $this->c->verify();

        $this->assertSame('VIEW:login_2fa', $out);
        $this->assertSame('invalid_token', $this->c->view->props['error']);
        $this->assertNull($this->c->flow->completedCode);
    }

    /** An empty code short-circuits before verification. */
    public function testVerifyPostMissingCode(): void
    {
        $this->c->flow->pending = 7;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['code' => ''];

        $out = $this->c->verify();

        $this->assertSame('VIEW:login_2fa', $out);
        $this->assertSame('missing_code', $this->c->view->props['error']);
    }

    /** A correct code finishes the login and redirects. */
    public function testVerifyPostSuccessRedirects(): void
    {
        $this->c->flow->pending = 7;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['code' => '123456'];
        $this->c->flow->completeResult = LoginFlowResult::success(7);

        $out = $this->c->verify();

        $this->assertNull($out);
        $this->assertSame('123456', $this->c->flow->completedCode);
        $this->assertSame(['Account'], $this->c->redirects);
    }

    /** A wrong code re-renders the step-up form (pending kept by the flow). */
    public function testVerifyPostWrongCodeRerenders(): void
    {
        $this->c->flow->pending = 7;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['code' => '000000'];
        $this->c->flow->completeResult = LoginFlowResult::failed();

        $out = $this->c->verify();

        $this->assertSame('VIEW:login_2fa', $out);
        $this->assertSame('invalid_code', $this->c->view->props['error']);
    }

    /** An already-authenticated visitor hitting verify is bounced onward. */
    public function testVerifyWhenAlreadyLoggedInRedirects(): void
    {
        $this->c->userId = 7;

        $out = $this->c->verify();

        $this->assertNull($out);
        $this->assertSame(['Account'], $this->c->redirects);
    }

    // ── logout() ─────────────────────────────────────────────────────────────────

    /** Logout drops any pending step-up, tears the session down, and redirects. */
    public function testLogoutTearsDownAndRedirects(): void
    {
        $this->c->logout();

        $this->assertTrue($this->c->flow->cancelled, 'pending step-up dropped');
        $this->assertTrue($this->c->auth->loggedOut, 'session torn down');
        $this->assertSame(['login'], $this->c->redirects);
    }

    // ── return-url sanitisation (open-redirect guard) ────────────────────────────

    /** A same-origin absolute return is honoured; everything hostile is dropped. */
    public function testReturnUrlSanitisation(): void
    {
        $this->assertSame('/Account/security', $this->c->exposeSanitize('/Account/security'), 'relative path allowed');
        $this->assertSame('', $this->c->exposeSanitize('https://evil.example/steal'), 'cross-origin rejected');
        $this->assertSame('', $this->c->exposeSanitize('//evil.example'), 'protocol-relative rejected');
        $this->assertSame('', $this->c->exposeSanitize("/ok\r\nSet-Cookie: x"), 'control chars rejected');
        $this->assertSame('', $this->c->exposeSanitize(''), 'empty stays empty');

        // Same-origin absolute (base seam returns a real host here).
        $this->c->base = 'https://auth.example.com/';
        $this->assertSame(
            'https://auth.example.com/Account',
            $this->c->exposeSanitize('https://auth.example.com/Account'),
            'same-origin absolute allowed'
        );
    }

    /** A validated return url wins over the dashboard fallback after login. */
    public function testSuccessHonoursReturnUrl(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'alice', 'password' => 'secret', 'return' => '/Account/security'];
        $this->c->flow->attemptResult = LoginFlowResult::success(7);

        $this->c->login();

        $this->assertSame(['/Account/security'], $this->c->redirects);
    }

    // ── default seams / wiring ────────────────────────────────────────────────

    /**
     * The un-overridden seams resolve their real defaults, proving the zero-config
     * path a scaffolded app relies on. (Runs against the live test env like the
     * rest of the suite; no user is logged in, so currentUserId() is null.)
     */
    public function testDefaultSeams(): void
    {
        $flow = new ExposedAccount(null);

        $this->assertInstanceOf(LoginFlow::class, $flow->flowPublic());
        $this->assertInstanceOf(Auth::class, $flow->authPublic());
        $this->assertNull($flow->currentUserIdPublic(), 'no logged-in user in tests');
        $this->assertIsBool($flow->checkCsrfPublic());
        $this->assertSame('', $flow->baseUrlPublic(), 'sURL is empty in the test bootstrap');
        $this->assertIsObject($flow->documentPublic());
        // post() trims by default, leaves the value verbatim when asked.
        $_POST = ['x' => '  hi  '];
        $this->assertSame('hi', $flow->postPublic('x'));
        $this->assertSame('  hi  ', $flow->postPublic('x', false));
        $_POST = [];
    }

    /**
     * currentUserId() maps a session user object to its id, and rejects the
     * anonymous / guest sentinels (null, missing id, id <= 1).
     */
    public function testCurrentUserIdMapsSessionUser(): void
    {
        $acc = new UserAccount(null);

        $acc->fakeUser = (object) ['userid' => 7];
        $this->assertSame(7, $acc->currentUserIdPublic(), 'real user id returned');

        $acc->fakeUser = (object) ['userid' => 1]; // guest sentinel
        $this->assertNull($acc->currentUserIdPublic());

        $acc->fakeUser = null;
        $this->assertNull($acc->currentUserIdPublic());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assertFalseAttempt(): void
    {
        $this->assertNull($this->c->flow->attemptArgs, 'flow->attempt() must not have run');
    }
}

/** Fake LoginFlow: canned results, records the calls the controller made. */
class FakeLoginFlow extends LoginFlow
{
    public ?LoginFlowResult $attemptResult = null;
    public ?LoginFlowResult $completeResult = null;
    public ?array $attemptArgs = null;
    public ?string $completedCode = null;
    public ?int $pending = null;
    public bool $cancelled = false;

    public function attempt(string $username, string $password, bool $remember = true): LoginFlowResult
    {
        $this->attemptArgs = [$username, $password, $remember];
        return $this->attemptResult ?? LoginFlowResult::failed();
    }

    public function completeTwoFactor(string $code): LoginFlowResult
    {
        $this->completedCode = $code;
        return $this->completeResult ?? LoginFlowResult::failed();
    }

    public function pendingUserId(): ?int
    {
        return $this->pending;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }
}

/** Fake auth service: records that logout() was called. */
class FakeAccountAuth extends Auth
{
    public bool $loggedOut = false;

    public function logout()
    {
        $this->loggedOut = true;
    }
}

/** Minimal view double: captures assigned properties, returns a display marker. */
class StubAccountView
{
    /** @var array<string,mixed> */
    public array $props = [];

    public function __set(string $name, mixed $value): void
    {
        $this->props[$name] = $value;
    }

    public function display(?string $template = null): string
    {
        return 'VIEW:' . (string) $template;
    }
}

/** Account with the collaborators + view/document replaced, redirect captured. */
class TestableAccount extends Account
{
    public ?int $userId = null;
    public bool $csrf = true;
    public string $base = '';
    public FakeLoginFlow $flow;
    public FakeAccountAuth $auth;
    public StubAccountView $view;
    public object $doc;
    public array $redirects = [];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->flow = new FakeLoginFlow();
        $this->auth = new FakeAccountAuth();
        $this->view = new StubAccountView();
        $this->doc  = new class { public string $title = ''; };
        parent::__construct($application);
    }

    // Collaborator seams
    protected function flow(): LoginFlow { return $this->flow; }
    protected function authService(): Auth { return $this->auth; }
    protected function currentUserId(): ?int { return $this->userId; }
    protected function checkCsrf(): bool { return $this->csrf; }
    protected function baseUrl(): string { return $this->base; }

    // View boundary — return the stub so the real render seams run end-to-end.
    public function &getView($name = '', $type = '', $args = array())
    {
        return $this->view;
    }
    protected function document(): object { return $this->doc; }

    // Capture redirects instead of exiting.
    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirects[] = $url;
    }

    /** Reach the protected sanitiser for the open-redirect assertions. */
    public function exposeSanitize(string $return): string
    {
        return $this->sanitizeReturnUrl($return);
    }
}

/** Exposes the real (un-overridden) seams to cover their default bodies. */
class ExposedAccount extends Account
{
    public function flowPublic(): LoginFlow { return $this->flow(); }
    public function authPublic(): Auth { return $this->authService(); }
    public function currentUserIdPublic(): ?int { return $this->currentUserId(); }
    public function checkCsrfPublic(): bool { return $this->checkCsrf(); }
    public function baseUrlPublic(): string { return $this->baseUrl(); }
    public function documentPublic(): object { return $this->document(); }
    public function postPublic(string $k, bool $t = true): string { return $this->post($k, $t); }
}

/** Account whose session user is injectable, to drive currentUserId()'s branches. */
class UserAccount extends Account
{
    public mixed $fakeUser = null;

    public function currentUserIdPublic(): ?int { return $this->currentUserId(); }

    protected function currentUser(): mixed { return $this->fakeUser; }
}
