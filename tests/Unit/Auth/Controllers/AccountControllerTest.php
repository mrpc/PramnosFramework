<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyCredential;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Auth\Passkey\VerificationResult;

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
        $_POST    = [];
        $_GET     = [];
        $_SESSION = []; // pending step-up + passkey challenge live here; isolate tests
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->c = new TestableAccount(null);
    }

    protected function tearDown(): void
    {
        $_POST    = [];
        $_GET     = [];
        $_SESSION = [];
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
        // Branding + route base reach the view so it can render + rebrand.
        $this->assertSame($this->c->brandData, $this->c->view->props['brand']);
        $this->assertSame('Account', $this->c->view->props['routeBase']);
    }

    /** An already-authenticated visitor is bounced to the dashboard, not the form. */
    public function testLoginWhenAlreadyLoggedInRedirects(): void
    {
        $this->c->userId = 7;

        $out = $this->c->login();

        $this->assertNull($out);
        $this->assertSame([sURL . 'Account'], $this->c->redirects); // routeBase, no return url
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
        $this->assertSame([sURL . 'Account'], $this->c->redirects);
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
        $this->assertSame([sURL . 'Account'], $this->c->redirects);
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
        $this->assertSame([sURL . 'Account'], $this->c->redirects);
    }

    // ── passkey step-up (passkeyOptions / passkeyVerify) ─────────────────────────

    /** Options can only be issued while a step-up is pending. */
    public function testPasskeyOptionsRequiresPendingLogin(): void
    {
        $this->c->flow->pending = null;
        $r = $this->c->passkeyOptions();
        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('no_pending_login', $r->getBody());
    }

    /** Options are pinned to the pending user and stash the challenge server-side. */
    public function testPasskeyOptionsPinsToPendingUserAndStoresChallenge(): void
    {
        $this->c->flow->pending = 7;

        $r = $this->c->passkeyOptions();

        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('options', $r->getBody());
        $this->assertSame(7, $this->c->passkeys->beganFor, 'ceremony scoped to the pending user');
        $this->assertSame('stepup-chal', $_SESSION['account_stepup_passkey_challenge']);
    }

    public function testPasskeyVerifyRequiresPendingLogin(): void
    {
        $this->c->flow->pending = null;
        $r = $this->c->passkeyVerify();
        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('no_pending_login', $r->getBody());
    }

    /** Without a matching in-flight challenge the verify is refused. */
    public function testPasskeyVerifyWithoutCeremonyFails(): void
    {
        $this->c->flow->pending = 7; // pending, but no challenge issued
        $r = $this->c->passkeyVerify();
        $this->assertSame(400, $r->getStatusCode());
        $this->assertStringContainsString('no_ceremony', $r->getBody());
    }

    /** A failed assertion returns 401 and clears the single-use challenge. */
    public function testPasskeyVerifyRejectsBadAssertion(): void
    {
        $this->c->flow->pending = 7;
        $_SESSION['account_stepup_passkey_challenge'] = 'stepup-chal';
        $this->c->passkeys->throwOnFinish = true;

        $r = $this->c->passkeyVerify();

        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('authentication_failed', $r->getBody());
        $this->assertArrayNotHasKey('account_stepup_passkey_challenge', $_SESSION, 'challenge is single-use');
    }

    /** A valid assertion whose user does not match the pending login is refused. */
    public function testPasskeyVerifyRejectsWhenFlowRejects(): void
    {
        $this->c->flow->pending = 7;
        $_SESSION['account_stepup_passkey_challenge'] = 'stepup-chal';
        $this->c->passkeys->verifiedUserId = 9;            // assertion resolves a different user
        $this->c->flow->passkeyResult = LoginFlowResult::failed();

        $r = $this->c->passkeyVerify();

        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('login_failed', $r->getBody());
        $this->assertSame(9, $this->c->flow->completedPasskeyUser, 'verified user handed to the flow');
    }

    /** A valid assertion for the pending user finishes the login and returns the redirect. */
    public function testPasskeyVerifySuccessReturnsRedirect(): void
    {
        $this->c->flow->pending = 7;
        $_SESSION['account_stepup_passkey_challenge'] = 'stepup-chal';
        $this->c->passkeys->verifiedUserId = 7;
        $this->c->flow->passkeyResult = LoginFlowResult::success(7);

        $r = $this->c->passkeyVerify();

        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('"status":"ok"', $r->getBody());
        $this->assertStringContainsString('"redirect"', $r->getBody());
        $this->assertSame(7, $this->c->flow->completedPasskeyUser);
    }

    // ── logout() ─────────────────────────────────────────────────────────────────

    /** Logout drops any pending step-up, tears the session down, and redirects. */
    public function testLogoutTearsDownAndRedirects(): void
    {
        $this->c->logout();

        $this->assertTrue($this->c->flow->cancelled, 'pending step-up dropped');
        $this->assertTrue($this->c->auth->loggedOut, 'session torn down');
        $this->assertSame([sURL . 'login'], $this->c->redirects);
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
     * The un-overridden, DB-free seams resolve their real defaults, proving the
     * zero-config path a scaffolded app relies on. (The DB-backed seams —
     * currentUser()/setting() — are exercised through their own injectable
     * subclasses below so this test never depends on live DB state.)
     */
    public function testDefaultSeams(): void
    {
        $flow = new ExposedAccount(null);

        $this->assertInstanceOf(LoginFlow::class, $flow->flowPublic());
        $this->assertInstanceOf(Auth::class, $flow->authPublic());
        $this->assertIsBool($flow->checkCsrfPublic());
        // Asserted against `sURL` rather than a literal: the base is what the installation's
        // own is, and a test that hardcoded a host would pass on a value no server emits.
        $this->assertSame((string) sURL, $flow->baseUrlPublic());
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

    /**
     * brand() resolves its settings-driven fallbacks: an explicit brand name
     * wins, else `sitename`, else "Sign in"; the primary colour defaults when
     * unset. Driven through the setting() seam so it needs no live settings.
     */
    public function testBrandFallbacks(): void
    {
        // Nothing configured → hard defaults.
        $blank = new BrandAccount(null);
        $this->assertSame('Sign in', $blank->brandPublic()['name']);
        $this->assertSame('#2563eb', $blank->brandPublic()['primary_color']);

        // sitename fills the name when no explicit brand name is set.
        $sn = new BrandAccount(null);
        $sn->settings = ['sitename' => 'Acme'];
        $this->assertSame('Acme', $sn->brandPublic()['name']);

        // Explicit brand keys win and are all passed through.
        $full = new BrandAccount(null);
        $full->settings = [
            'auth_brand_name' => 'Acme ID', 'sitename' => 'Acme',
            'auth_brand_primary_color' => '#111', 'auth_brand_logo' => '/l.png',
            'auth_brand_footer' => '© Acme',
        ];
        $b = $full->brandPublic();
        $this->assertSame('Acme ID', $b['name'], 'explicit brand name beats sitename');
        $this->assertSame('#111', $b['primary_color']);
        $this->assertSame('/l.png', $b['logo']);
        $this->assertSame('© Acme', $b['footer']);
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
    public ?LoginFlowResult $passkeyResult = null;
    public ?array $attemptArgs = null;
    public ?string $completedCode = null;
    public ?int $completedPasskeyUser = null;
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

    public function completePasskey(int $verifiedUserId): LoginFlowResult
    {
        $this->completedPasskeyUser = $verifiedUserId;
        return $this->passkeyResult ?? LoginFlowResult::failed();
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

/** In-memory passkey service; only the step-up ceremony methods are exercised. */
class FakeAccountPasskeys implements PasskeyServiceInterface
{
    public bool $throwOnFinish = false;
    public int $verifiedUserId = 7;
    public ?int $beganFor = null;

    public function beginAuthentication(?int $userId = null): AuthenticationOptions
    {
        $this->beganFor = $userId;
        return new AuthenticationOptions('stepup-chal', '{}', $userId);
    }

    public function finishAuthentication(AuthenticationOptions $options, string $clientResponse): VerificationResult
    {
        if ($this->throwOnFinish) {
            throw new PasskeyException('nope');
        }
        return new VerificationResult($this->verifiedUserId, new PasskeyCredential(1, $this->verifiedUserId, 'cid', 'pk', 1), 1);
    }

    public function beginRegistration(int $userId, ?string $label = null): RegistrationOptions
    {
        return new RegistrationOptions('c', '{}', $userId);
    }
    public function finishRegistration(int $userId, RegistrationOptions $options, string $clientResponse): PasskeyCredential
    {
        return new PasskeyCredential(1, $userId, 'cid', 'pk', 0);
    }
    public function listCredentials(int $userId): array { return []; }
    public function renameCredential(int $userId, int $credentialId, string $name): bool { return false; }
    public function revokeCredential(int $userId, int $credentialId): bool { return false; }
    public function hasCredentials(int $userId): bool { return false; }
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
    /** @var array<string,string> */
    public array $brandData = [
        'name' => 'Acme ID', 'logo' => '', 'primary_color' => '#111111', 'footer' => 'Acme Inc.',
    ];
    public FakeLoginFlow $flow;
    public FakeAccountAuth $auth;
    public FakeAccountPasskeys $passkeys;
    public string $body = '{}';
    public StubAccountView $view;
    public object $doc;
    public array $redirects = [];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->flow     = new FakeLoginFlow();
        $this->auth     = new FakeAccountAuth();
        $this->passkeys = new FakeAccountPasskeys();
        $this->view     = new StubAccountView();
        $this->doc      = new class { public string $title = ''; };
        parent::__construct($application);
    }

    // Collaborator seams
    protected function flow(): LoginFlow { return $this->flow; }
    protected function authService(): Auth { return $this->auth; }
    protected function passkeys(): PasskeyServiceInterface { return $this->passkeys; }
    protected function rawRequestBody(): string { return $this->body; }
    protected function currentUserId(): ?int { return $this->userId; }
    protected function checkCsrf(): bool { return $this->csrf; }
    protected function baseUrl(): string { return $this->base; }
    protected function brand(): array { return $this->brandData; }

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

/** Exposes the real (un-overridden), DB-free seams to cover their default bodies. */
class ExposedAccount extends Account
{
    public function flowPublic(): LoginFlow { return $this->flow(); }
    public function authPublic(): Auth { return $this->authService(); }
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

/** Account whose settings are injectable, to drive brand()'s fallbacks without a DB. */
class BrandAccount extends Account
{
    /** @var array<string,string> */
    public array $settings = [];

    public function brandPublic(): array { return $this->brand(); }

    protected function setting(string $key): string { return $this->settings[$key] ?? ''; }
}
