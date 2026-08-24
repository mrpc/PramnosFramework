<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Http\Response;

/**
 * Passkey (WebAuthn/FIDO2) ceremony + management endpoints.
 *
 * Registration & management actions require a logged-in session; the login
 * actions are public (they are how a user logs in). Every action is a small
 * JSON endpoint the browser's WebAuthn glue calls:
 *
 *   POST /Passkey/registerOptions  → creation options (auth)
 *   POST /Passkey/register         → verify attestation, store credential (auth)
 *   POST /Passkey/loginOptions     → request options (public)
 *   POST /Passkey/login            → verify assertion, establish session (public)
 *   GET  /Passkey/list             → list the user's passkeys (auth)
 *   POST /Passkey/rename           → rename a passkey (auth)
 *   POST /Passkey/revoke           → revoke a passkey (auth)
 *
 * The "which ceremony is in flight" correlation is kept in the session (the
 * issued challenge), never round-tripped through the client — so the finish
 * step can only complete a ceremony this same session started. The heavy
 * collaborators are reached through protected seams so the flow is unit-testable
 * without a live WebAuthn device or HTTP request.
 */
class Passkey extends Controller
{
    /** Session keys holding the in-flight ceremony challenge. */
    private const S_REG_CHALLENGE   = 'passkey_reg_challenge';
    private const S_LOGIN_CHALLENGE = 'passkey_login_challenge';
    private const S_LOGIN_USERID    = 'passkey_login_userid';

    private ?PasskeyServiceInterface $passkeyService = null;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['loginOptions', 'login']);
        $this->addAuthAction(['display', 'registerOptions', 'register', 'list', 'rename', 'revoke']);
        parent::__construct($application);
    }

    // ── Management page (auth) ─────────────────────────────────────────────────

    /**
     * HTML page for managing the logged-in user's passkeys (list / add / rename /
     * revoke). The heavy lifting is done client-side by pf-webauthn.js against the
     * JSON endpoints below; this action just renders the page shell. Rendering is
     * a seam so the flow stays testable without a view layer.
     */
    public function display(): mixed
    {
        $user = $this->currentUserId();
        if ($user === null) {
            $this->redirect(sURL . 'login');
            return null;
        }
        return $this->renderManage();
    }

    /** Render the passkey management view (overridable / mockable). */
    protected function renderManage(): mixed
    {
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Passkeys';

        $view            = $this->getView('passkey');
        $view->routeBase = 'Passkey';
        return $view->display('manage');
    }

    // ── Registration (auth) ───────────────────────────────────────────────────

    /** POST — issue creation options for the logged-in user. */
    public function registerOptions(): mixed
    {
        $user = $this->currentUserId();
        if ($user === null) {
            return $this->unauthorized();
        }

        $label   = $this->input('label');
        $options = $this->service()->beginRegistration($user, $label !== '' ? $label : null);

        // Remember which challenge this session issued.
        // Verified on the next request, so it has to outlive this one.
        \Pramnos\Http\Session::getInstance()->ensureStarted();
        $_SESSION[self::S_REG_CHALLENGE] = $options->challenge;

        return Response::json(['options' => $options->toClientArray()], 200);
    }

    /** POST — verify the attestation response and persist the credential. */
    public function register(): mixed
    {
        $user = $this->currentUserId();
        if ($user === null) {
            return $this->unauthorized();
        }

        $challenge = (string) ($_SESSION[self::S_REG_CHALLENGE] ?? '');
        if ($challenge === '') {
            return Response::json(['error' => 'no_ceremony'], 400);
        }
        unset($_SESSION[self::S_REG_CHALLENGE]);

        $clientResponse = $this->rawRequestBody();

        try {
            $credential = $this->service()->finishRegistration(
                $user,
                new RegistrationOptions($challenge, '', $user),
                $clientResponse
            );
        } catch (PasskeyException $e) {
            return Response::json(['error' => 'registration_failed'], 400);
        }

        \Pramnos\Auth\ActivityLog::record($user, 'passkey_added');

        return Response::json(['status' => 'ok', 'credential' => $credential->toPublicArray()], 200);
    }

    // ── Authentication (public) ─────────────────────────────────────────────────

    /**
     * POST — issue request options.
     *
     * With a `username` field the ceremony is pinned to that user; without it
     * the ceremony is usernameless (discoverable credentials).
     */
    public function loginOptions(): mixed
    {
        $username = $this->input('username');
        $userId   = $username !== '' ? $this->resolveUserId($username) : null;

        $options = $this->service()->beginAuthentication($userId);

        // Verified on the next request, and a passkey login begins with no session.
        \Pramnos\Http\Session::getInstance()->ensureStarted();
        $_SESSION[self::S_LOGIN_CHALLENGE] = $options->challenge;
        $_SESSION[self::S_LOGIN_USERID]    = $userId;

        return Response::json(['options' => $options->toClientArray()], 200);
    }

    /** POST — verify the assertion and establish a login session. */
    public function login(): mixed
    {
        $challenge = (string) ($_SESSION[self::S_LOGIN_CHALLENGE] ?? '');
        if ($challenge === '') {
            return Response::json(['error' => 'no_ceremony'], 400);
        }
        $userId = $_SESSION[self::S_LOGIN_USERID] ?? null;
        unset($_SESSION[self::S_LOGIN_CHALLENGE], $_SESSION[self::S_LOGIN_USERID]);

        $clientResponse = $this->rawRequestBody();

        try {
            $result = $this->service()->finishAuthentication(
                new AuthenticationOptions($challenge, '', $userId === null ? null : (int) $userId),
                $clientResponse
            );
        } catch (PasskeyException $e) {
            return Response::json(['error' => 'authentication_failed'], 401);
        }

        // Establish the session the same way a password login would.
        if (!$this->establishSession($result->userId)) {
            return Response::json(['error' => 'login_failed'], 401);
        }

        return Response::json(['status' => 'ok', 'user_id' => $result->userId], 200);
    }

    // ── Management (auth) ─────────────────────────────────────────────────────

    /** GET — list the user's active passkeys. */
    public function list(): mixed
    {
        $user = $this->currentUserId();
        if ($user === null) {
            return $this->unauthorized();
        }
        $items = array_map(
            static fn($c) => $c->toPublicArray(),
            $this->service()->listCredentials($user)
        );
        return Response::json(['passkeys' => $items], 200);
    }

    /** POST — rename one of the user's passkeys. */
    public function rename(): mixed
    {
        $user = $this->currentUserId();
        if ($user === null) {
            return $this->unauthorized();
        }
        $id   = (int) $this->input('id');
        $name = $this->input('name');
        if ($id <= 0 || $name === '') {
            return Response::json(['error' => 'invalid_request'], 400);
        }
        $ok = $this->service()->renameCredential($user, $id, $name);
        if ($ok) {
            \Pramnos\Auth\ActivityLog::record($user, 'passkey_renamed');
        }
        return Response::json(['status' => $ok ? 'ok' : 'not_found'], $ok ? 200 : 404);
    }

    /** POST — revoke (soft-delete) one of the user's passkeys. */
    public function revoke(): mixed
    {
        $user = $this->currentUserId();
        if ($user === null) {
            return $this->unauthorized();
        }
        $id = (int) $this->input('id');
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_request'], 400);
        }
        $ok = $this->service()->revokeCredential($user, $id);
        if ($ok) {
            \Pramnos\Auth\ActivityLog::record($user, 'passkey_removed');
        }
        return Response::json(['status' => $ok ? 'ok' : 'not_found'], $ok ? 200 : 404);
    }

    // ── Testable seams ─────────────────────────────────────────────────────────

    /** The passkey service (seam so tests can inject a double). */
    protected function service(): PasskeyServiceInterface
    {
        if ($this->passkeyService === null) {
            $this->passkeyService = new PasskeyService();
        }
        return $this->passkeyService;
    }

    /** Current logged-in user id, or null when not authenticated. */
    protected function currentUserId(): ?int
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if ($user === false || !isset($user->userid) || (int) $user->userid <= 1) {
            return null;
        }
        return (int) $user->userid;
    }

    /** Resolve a username/email to a user id, or null when unknown. */
    protected function resolveUserId(string $username): ?int
    {
        $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('users')
            ->select('userid')
            ->where('username', $username)
            ->orWhere('email', $username)
            ->first();
        return ($row && $row->numRows > 0) ? (int) $row->fields['userid'] : null;
    }

    /** Establish a login session for a verified user (passwordless). */
    protected function establishSession(int $userId): bool
    {
        return \Pramnos\Framework\Factory::getAuth()->loginById($userId);
    }

    /** A request field from POST/GET (seam for tests). */
    protected function input(string $key): string
    {
        return isset($_POST[$key]) ? trim((string) $_POST[$key])
            : (isset($_GET[$key]) ? trim((string) $_GET[$key]) : '');
    }

    /** The raw request body (seam so tests can supply a body). */
    protected function rawRequestBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    private function unauthorized(): mixed
    {
        return Response::json(['error' => 'unauthorized'], 401);
    }
}
