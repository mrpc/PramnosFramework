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

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['login', 'logout']);
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

        $user = $this->verifyCredentials($username, $password);
        if ($user === null) {
            return Response::json(['error' => 'invalid_credentials'], 401);
        }

        $token = $this->issueToken($user);
        if ($token === null) {
            return Response::json(
                ['error' => 'token_unavailable', 'error_description' => 'The API signing key is not configured.'],
                500
            );
        }

        return Response::json([
            'status'       => 'success',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->userPayload($user),
        ]);
    }

    /**
     * POST /account/logout — revoke the presented access token.
     */
    public function logout(): mixed
    {
        $token = trim((string) ($_SERVER['HTTP_ACCESSTOKEN'] ?? ''));
        if ($token !== '') {
            $this->revokeToken($token);
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
        $jwt = JWT::encode([
            'iss' => defined('sURL') ? sURL : '',
            'aud' => $this->audience(),
            'iat' => $now,
            'nbf' => $now - (3600 * 12),
        ], $key);

        $user->addToken('auth', $jwt, 'api_login');

        return $jwt;
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

    /**
     * Raw request body (seam so tests can supply a JSON payload).
     *
     * @codeCoverageIgnore — reads php://input; a payload is supplied in tests.
     */
    protected function rawBody(): string
    {
        return (string) file_get_contents('php://input');
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
