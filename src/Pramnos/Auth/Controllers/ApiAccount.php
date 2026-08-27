<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\JWT;
use Pramnos\Http\Response;
use Pramnos\User\User;

/**
 * ApiAccount — token-based authentication for the REST API.
 *
 * The JSON counterpart to the web {@see Account} controller. Unlike the web flow
 * (which establishes a PHP session and renders HTML), this issues a stateless
 * **bearer access token** — the correct model for a REST API:
 *
 *   POST /account/login  {username,password} → 200 {access_token, token_type, user}
 *   POST /account/logout (accessToken header) → 200 revokes the presented token
 *
 * Credentials are verified WITHOUT creating a session (via
 * {@see \Pramnos\Auth\Auth::verifyCredentials()}); on success a signed JWT is
 * minted and stored in `usertokens` (type `auth`) so {@see \Pramnos\User\User::loadByToken()}
 * — and thus the API auth middleware — accepts it on subsequent requests. The
 * client sends it back in the `accessToken` header.
 *
 * Input is read from a JSON body or form POST. CSRF is not enforced (token auth,
 * not session-cookie forms). Apps thin-wrap this in their Api\Controllers.
 */
class ApiAccount extends Controller
{
    /** @var array<string, mixed>|null decoded JSON body cache */
    private ?array $jsonBodyCache = null;

    /**
     * The user the credentials check just resolved.
     *
     * The flow reports a user *id*; this is the object that produced it. Keeping
     * it saves loading the same row twice, and keeps the seam honest: an
     * application that overrode `verifyCredentials()` to return its own User
     * subclass gets that object back, not a freshly constructed base one.
     *
     * @var User|null
     */
    private ?User $resolvedUser = null;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['login', 'login2fa', 'logout']);
        parent::__construct($application);
    }

    /**
     * POST /account/login — verify credentials and issue a bearer token (JSON).
     */
    public function login(): mixed
    {
        if ($this->requestMethod() !== 'POST') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        $username = trim((string) $this->input('username'));
        $password = (string) $this->input('password'); // never trim a password

        if ($username === '' || $password === '') {
            return Response::json(['error' => 'missing_credentials'], 400);
        }

        $result = $this->loginFlow()->attempt($username, $password, false);

        if ($result->isLocked()) {
            return Response::json([
                'error'             => 'too_many_attempts',
                'error_description' => 'Too many failed attempts. Try again later.',
                'retry_after'       => $result->lockoutRemaining,
            ], 429);
        }

        if ($result->status === \Pramnos\Auth\LoginFlowResult::STEP_UP_REQUIRED) {
            return Response::json([
                'error'             => 'two_factor_required',
                'error_description' => 'This account needs a second factor. '
                    . 'Post the code to /account/login2fa.',
                'methods'           => $result->stepUpMethods,
            ], 401);
        }

        if (!$result->isSuccess() || $result->userId === null) {
            return Response::json(['error' => 'invalid_credentials'], 401);
        }

        return $this->tokenResponse($this->userFor($result->userId));
    }

    /**
     * POST /account/login2fa — finish a login that needed a second factor.
     *
     * The pending state was stashed by {@see login()} and lives server-side, so
     * the only thing the client sends is the code. A wrong code leaves the
     * pending login intact so the user can try again.
     */
    public function login2fa(): mixed
    {
        if ($this->requestMethod() !== 'POST') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        $code = trim((string) $this->input('code'));
        if ($code === '') {
            return Response::json(['error' => 'missing_code'], 400);
        }

        $result = $this->loginFlow()->completeTwoFactor($code);

        if ($result->isLocked()) {
            return Response::json([
                'error'       => 'too_many_attempts',
                'retry_after' => $result->lockoutRemaining,
            ], 429);
        }

        if (!$result->isSuccess() || $result->userId === null) {
            return Response::json(['error' => 'invalid_code'], 401);
        }

        return $this->tokenResponse($this->userFor($result->userId));
    }

    /**
     * The user behind a verified login.
     *
     * The first leg already has the object; the second leg (a code posted on a
     * later request) does not, and loads it.
     */
    protected function userFor(int $userId): User
    {
        if ($this->resolvedUser !== null
            && (int) $this->resolvedUser->userid === $userId) {
            return $this->resolvedUser;
        }

        return new User($userId);
    }

    /**
     * The success response: a bearer token for a fully verified user.
     *
     * Shared by both legs so a login that needed a second factor is answered
     * exactly like one that did not.
     */
    protected function tokenResponse(User $user): mixed
    {
        // This request *did* authenticate somebody — with a password rather than
        // a token, which is the only reason it did not look like it. Saying so
        // is what lets the debug toolbar show the login at the moment it
        // happens, instead of only on whatever call comes next; and it means
        // anything reading the current user during this response (activity logs,
        // tracking) sees who it was for.
        // `currentInstance()`: `getInstance()` is a factory, and this is a login response
        // — not the place to construct an application, a database connection and a session
        // as a side effect. The guard was already written for a null this call never
        // returned.
        $app = \Pramnos\Application\Application::currentInstance();
        if ($app) {
            $app->currentUser = $user;
        }

        $token = $this->issueToken($user);
        if ($token === null) {
            \Pramnos\Http\RequestIdentity::seal($user, 'password');

            return Response::json(
                ['error' => 'token_unavailable', 'error_description' => 'The API signing key is not configured.'],
                500
            );
        }

        // Sealed after the token exists, so the toolbar can describe what was
        // just issued — how long it lasts, and what is in it — at the moment it
        // is handed over. Before this, a login showed "signed in" and the expiry
        // only appeared on the next request, which is the one moment nobody is
        // looking. The token's *value* still never enters the payload; the
        // response beside it already carries that to the client that asked.
        \Pramnos\Http\RequestIdentity::seal($user, 'password', $token);

        return Response::json([
            'status'       => 'success',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->userPayload($user),
        ]);
    }

    /**
     * The flow that decides what a password entitles the caller to.
     *
     * The same one the HTML login uses — lockout, credentials, second factor —
     * with the last step swapped for a token. This endpoint used to go straight
     * from password to token, so an account with 2FA could be entered with the
     * password alone and nothing counted failed attempts.
     *
     * `verifyCredentials()` stays the seam it always was: the flow calls back
     * into it, so an application that overrode it keeps its hook.
     */
    protected function loginFlow(): \Pramnos\Auth\ApiLoginFlow
    {
        return new \Pramnos\Auth\ApiLoginFlow(
            function (string $username, string $password): array|false {
                $user = $this->verifyCredentials($username, $password);
                $this->resolvedUser = $user;

                return $user === null
                    ? false
                    : ['status' => true, 'uid' => (int) $user->userid];
            }
        );
    }

    /**
     * POST /account/logout — revoke the presented access token.
     */
    public function logout(): mixed
    {
        // Accepts the framework's accessToken header or a standard
        // "Authorization: Bearer …" — logging out must work for whichever one
        // the client used to log in with.
        $token = \Pramnos\Http\Request::accessToken();
        if ($token !== null) {
            $this->revokeToken($token);
        }

        // This request arrived authenticated and leaves nobody behind it, and
        // the second half is the part worth reporting: the toolbar's Auth tab
        // describes the state as it stands, so a logout that still said
        // "signed in as admin" — true of the call, false of everything after it
        // — read as a logout that had not worked.
        //
        // Symmetric with login(), which reports the identity it *created* rather
        // than the anonymous one it was made with.
        \Pramnos\Http\RequestIdentity::seal(null, 'signed-out');
        $app = \Pramnos\Application\Application::currentInstance();
        if ($app) {
            $app->currentUser = null;
        }

        return Response::json(['status' => 'ok']);
    }

    // ── Collaborators (overridable seams) ───────────────────────────────────────

    /**
     * Verify credentials WITHOUT establishing a session; return the user or null.
     *
     * @codeCoverageIgnore — wraps the auth addon + a DB user load; a double is
     *                       returned in tests, and the behaviour is covered end
     *                       to end by the integration suite.
     */
    protected function verifyCredentials(string $username, string $password): ?User
    {
        $result = $this->authService()->verifyCredentials($username, $password, false, false);
        if (!$result || empty($result['status']) || empty($result['uid'])) {
            return null;
        }

        return new User((int) $result['uid']);
    }

    /**
     * Mint a signed JWT for the user and persist it as an `auth` token, so the API
     * auth middleware accepts it. Returns null when no signing key is available.
     */
    protected function issueToken(User $user): ?string
    {
        $key = $this->signingKey();
        if ($key === '') {
            return null;
        }

        $now = time();
        $claims = [
            'iss' => defined('sURL') ? sURL : '',
            'aud' => $this->audience(),
            'iat' => $now,
            'nbf' => $now - (3600 * 12),
        ];

        // Optional expiry. TTL of 0 keeps the historical never-expires behaviour;
        // a positive TTL stamps both the JWT `exp` claim (rejected by JWT::decode
        // once past) and the usertokens.expires column (rejected by loadByToken).
        $ttl = $this->tokenTtl();
        $expires = null;
        if ($ttl > 0) {
            $expires = $now + $ttl;
            $claims['exp'] = $expires;
        }

        $jwt = JWT::encode($claims, $key);

        $user->addToken('auth', $jwt, 'api_login', null, $expires);

        return $jwt;
    }

    /**
     * Access-token lifetime in seconds. 0 (the default) means the token never
     * expires — preserving the historical behaviour. Apps enable expiry by
     * overriding this or by setting the `auth.token_ttl` application config key.
     */
    protected function tokenTtl(): int
    {
        $app = $this->application;
        if (is_object($app) && isset($app->applicationInfo['auth']['token_ttl'])) {
            return max(0, (int) $app->applicationInfo['auth']['token_ttl']);
        }

        return 0;
    }

    /**
     * The public profile returned alongside the token.
     *
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        return [
            'id'       => (int) $user->userid,
            'username' => $user->username,
            'email'    => $user->email,
        ];
    }

    /**
     * The HS256 signing key — the API application's authentication key.
     *
     * @codeCoverageIgnore — reads the Api instance; overridden in tests.
     */
    protected function signingKey(): string
    {
        $app = $this->application;
        return (is_object($app) && isset($app->authenticationKey)) ? (string) $app->authenticationKey : '';
    }

    /**
     * The token audience — the authenticated application's API key (if any).
     *
     * @codeCoverageIgnore — reads the Api instance; overridden in tests.
     */
    protected function audience(): string
    {
        $app = $this->application;
        if (is_object($app) && isset($app->apiKey) && is_object($app->apiKey)) {
            return (string) ($app->apiKey->apikey ?? '');
        }

        return '';
    }

    /**
     * Deactivate a token row by its value.
     *
     * @codeCoverageIgnore — thin DB write; exercised via integration, not unit tests.
     */
    protected function revokeToken(string $token): void
    {
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('usertokens')
            ->where('token', $token)
            ->update(['status' => 2]);
    }

    /** The framework auth service. */
    protected function authService(): \Pramnos\Auth\Auth
    {
        return \Pramnos\Framework\Factory::getAuth(); // @codeCoverageIgnore — DI seam; a double is injected in tests
    }

    /**
     * The HTTP method, upper-cased.
     *
     * @codeCoverageIgnore — env seam; set directly in tests.
     */
    protected function requestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Raw request body (seam so tests can supply a JSON payload). */
    protected function rawBody(): string
    {
        return \Pramnos\Http\Request::rawBody();
    }

    /**
     * Read an input field from the JSON body if present, else from form POST.
     */
    protected function input(string $key): mixed
    {
        $json = $this->jsonBody();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $_POST[$key] ?? '';
    }

    /**
     * The decoded JSON request body (empty array when absent/invalid).
     *
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        if ($this->jsonBodyCache !== null) {
            return $this->jsonBodyCache;
        }

        $raw     = trim($this->rawBody());
        $decoded = $raw === '' ? null : json_decode($raw, true);

        return $this->jsonBodyCache = is_array($decoded) ? $decoded : [];
    }
}
