<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\OAuth2\OAuth2ServerFactory;
use Pramnos\Auth\Scopes;
use Pramnos\Application\Controller;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * OAuth2 / OpenID Connect server controller.
 *
 * Implements the authorization server endpoints using league/oauth2-server
 * for token issuance and manual DB queries for introspection / revocation.
 *
 * Public actions (no auth guard):
 *   authorize           — authorization endpoint (GET = form, POST = decision)
 *   token               — token endpoint (all grant types via League)
 *   revoke              — RFC 7009 token revocation
 *   introspect          — RFC 7662 token introspection
 *   userinfo            — OIDC UserInfo
 *   logout              — Bearer-token logout
 *   deviceauthorization — RFC 8628 device authorization
 *
 */
class Oauth extends Controller
{
    /**
     * Terminate execution. Overridden in tests to prevent exit.
     */
    protected function terminate(): void
    {
        if (defined('PRAMNOS_TESTING')) {
            throw new \Exception("OAuth controller terminated");
        }
        exit;
    }

    private OAuth2ServerFactory $oauth2Factory;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);

        $this->addaction([
            'authorize', 'token', 'revoke', 'introspect',
            'userinfo', 'logout', 'deviceauthorization',
        ]);

        $this->addAuthAction(['display']);

        $this->oauth2Factory = new OAuth2ServerFactory($this);
        $this->oauth2Factory->generateKeyPair();

        header('Access-Control-Allow-Origin: *');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            $this->terminate(); return;
        }
    }

    // ── Admin overview ────────────────────────────────────────────────────────

    /**
     * Admin overview — list of registered OAuth2 applications.
     *
     * Requires authentication (addAuthAction). Renders via the scaffolded
     * view at src/Views/oauth/oauth.html.php (falling back to the framework
     * scaffolding view oauth/oauth.html.php).
     */
    public function display()
    {
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'OAuth Applications';

        $db = \Pramnos\Framework\Factory::getDatabase();

        $result = $db->queryBuilder()
            ->table('applications')
            // `added`, not `created`: that is what the applications table calls
            // its creation timestamp. Selecting and ordering by a column that
            // does not exist made this listing fail outright.
            ->select(['appid', 'name', 'description', 'apikey', 'status', 'added AS created'])
            ->orderBy('added', 'desc')
            ->get();

        $apps = [];
        if ($result) {
            while ($result->fetch()) {
                $apps[] = (array) $result->fields;
            }
        }

        $view        = $this->getView('oauth');
        $view->apps  = $apps;

        return $view->display();
    }

    // ── Authorization endpoint ────────────────────────────────────────────────

    /**
     * Authorization endpoint — RFC 6749 §4.1.
     *
     * GET  — validate parameters, check login state, show consent form (or
     *        auto-approve if the user has already authorised this application).
     * POST — record consent decision, issue auth code and redirect.
     *
     * Supports PKCE (RFC 7636) when code_challenge is present.
     */
    public function authorize(): void
    {
        try {
            $params = $this->collectAuthorizeParams();
            $this->validateAuthorizeParams($params);

            $client      = $this->loadClient($params['client_id']);
            $user        = $this->getLoggedInUser();

            if ($user === null) {
                $returnUrl = sURL . 'oauth/authorize?' . http_build_query($params);
                $this->redirect(sURL . 'login?' . http_build_query(['return_url' => $returnUrl]));
                return;
            }

            // POST — user has submitted the consent form
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $this->handleConsentPost($user, $client, $params);
                return;
            }

            // Trusted first-party clients skip the consent screen entirely
            // (silent flow): the authorization server issues the code without
            // prompting.  Untrusted clients (the default) fall through to the
            // normal consent path below.
            if ($this->clientSkipsConsent($client)) {
                $this->issueCodeAndRedirect($user->userid, $params);
                return;
            }

            // Auto-approve if already authorised with the same or broader scopes
            $requestedScopes = array_filter(explode(' ', $params['scope']));
            if ($this->hasUserAuthorizedApp($user->userid, (int) $client['appid'], $requestedScopes)) {
                $this->issueCodeAndRedirect($user->userid, $params);
                return;
            }

            // Show consent form
            $this->showConsentForm($user, $client, $params);

        } catch (OAuthServerException $ex) {
            $this->showErrorPage($ex->getMessage());
        } catch (\Exception $ex) {
            if ($ex->getMessage() === 'OAuth controller terminated') {
                throw $ex;
            }
            $this->showErrorPage($ex->getMessage());
        }
    }

    // ── Token endpoint ────────────────────────────────────────────────────────

    /**
     * Token endpoint — RFC 6749 §3.2.
     *
     * All grant-type dispatch is handled by the League authorization server.
     * Supported grant types: authorization_code, client_credentials,
     * password, refresh_token.
     */
    public function token(): mixed
    {
        // JWT client assertion (RFC 7523) for client_credentials is handled manually
        // because League oauth2-server does not natively support private_key_jwt
        // client authentication.  The bypass also manages the per-application system
        // user so introspect() / revoke() continue to work on the issued tokens.
        if (($_POST['grant_type'] ?? '') === 'client_credentials'
            && isset($_POST['client_assertion'])
            && ($_POST['client_assertion_type'] ?? '') === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        ) {
            return $this->handleJwtClientCredentials()
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Pragma', 'no-cache');
        }

        try {
            $psrFactory  = new Psr17Factory();
            $psrRequest  = $this->buildPsrServerRequest($psrFactory);
            $psrResponse = $psrFactory->createResponse();

            $authServer  = $this->oauth2Factory->createAuthorizationServer();
            $psrResponse = $authServer->respondToAccessTokenRequest($psrRequest, $psrResponse);

            return $this->emitPsrResponse($psrResponse);

        } catch (OAuthServerException $ex) {
            return $this->emitPsrResponse($ex->generateHttpResponse(new Psr17Factory()->createResponse()));
        } catch (\Exception $ex) {
            return \Pramnos\Http\Response::json([
                'error'             => 'server_error',
                'error_description' => $ex->getMessage(),
            ], 500);
        }
    }

    // ── Revocation ────────────────────────────────────────────────────────────

    /**
     * Token revocation endpoint — RFC 7009.
     *
     * POST /oauth/revoke
     * Parameters: token, token_type_hint (optional)
     */
    public function revoke(): mixed
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return \Pramnos\Http\Response::json(['error' => 'method_not_allowed'], 405);
        }

        $token = $_POST['token'] ?? '';
        if ($token === '') {
            return \Pramnos\Http\Response::json(['error' => 'invalid_request', 'error_description' => 'Missing token parameter'], 400);
        }

        // RFC 7009: revocation always answers 200, even for a token that does not
        // exist, so that the endpoint cannot be used to find out which do.
        //
        // The value is resolved the same way introspection resolves it: a token
        // issued through the League server is a JWT, and what is stored is its
        // `jti`. Matching literally revoked nothing at all for those — and unlike
        // introspection, which at least answered `active: false`, this failure was
        // completely silent, because the endpoint answers 200 either way. A caller
        // revoking on sign-out had no way to discover it had not worked.
        $stored = $this->resolveStoredTokenValue($token);

        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('usertokens')
            ->where('token', $stored)
            ->where('status', 1)
            ->update(['status' => 0]);

        return \Pramnos\Http\Response::json(['success' => true]);
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    /**
     * Token introspection endpoint — RFC 7662.
     *
     * POST /oauth/introspect
     * Requires client authentication (Basic or POST body).
     */
    public function introspect(): mixed
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return \Pramnos\Http\Response::json(['error' => 'method_not_allowed'], 405);
        }

        $credentials = $this->extractClientCredentials();
        if ($credentials === null || !$this->validateClientCredentials($credentials)) {
            return \Pramnos\Http\Response::json(['error' => 'invalid_client'], 401)
                ->withHeader('WWW-Authenticate', 'Basic realm="OAuth2"');
        }

        $token = $_POST['token'] ?? '';
        if ($token === '') {
            return \Pramnos\Http\Response::json(['error' => 'invalid_request', 'error_description' => 'Missing token parameter'], 400);
        }

        $result = $this->findIntrospectableToken($token);

        if ($result === null) {
            return \Pramnos\Http\Response::json(['active' => false]);
        }

        $row      = $result;
        $isActive = (int) $row['status'] === 1
                 && ((int) $row['expires'] === 0 || (int) $row['expires'] > time());

        if (!$isActive) {
            return \Pramnos\Http\Response::json(['active' => false]);
        }

        return \Pramnos\Http\Response::json([
            'active'     => true,
            'scope'      => $row['scope']     ?? '',
            'client_id'  => $row['client_id'] ?? '',
            'username'   => $row['username']  ?? '',
            'token_type' => 'Bearer',
            'exp'        => (int) ($row['expires'] ?? 0),
            'iat'        => (int) ($row['created'] ?? 0),
            'sub'        => (string) ($row['userid'] ?? ''),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The value a presented token is stored under.
     *
     * The token itself when it is stored verbatim; its `jti` when it is a JWT
     * issued through the League server. Returns the original when neither
     * matches, so the caller's query behaves as it did before.
     */
    protected function resolveStoredTokenValue(string $token): string
    {
        if ($this->selectTokenRow($token) !== null) {
            return $token;
        }

        $jti = $this->extractJwtId($token);

        return ($jti !== null && $this->selectTokenRow($jti) !== null) ? $jti : $token;
    }

    /**
     * Find the stored row for a token presented to `introspect` or `revoke`.
     *
     * ## Why this is not one `WHERE token = ?`
     *
     * A token issued through the League server is a **JWT**, and what is stored in
     * `usertokens.token` is its `jti` — the opaque identifier League generates,
     * which is what `persistNewAccessToken()` receives. Looking the JWT up
     * literally therefore never matched anything, and introspection answered
     * `{"active": false}` for every access token this server had ever issued. For
     * a resource server that trusts introspection, that is every request refused.
     *
     * Two lookups, in this order:
     *
     *  1. **The literal value.** Tokens this framework stores verbatim — web
     *     session tokens, API tokens, the ones the JWT-assertion path writes —
     *     match here, and looking first keeps them working exactly as before.
     *  2. **The `jti` inside it**, when the value parses as a JWT.
     *
     * The signature is not verified, and that is deliberate: the stored row is the
     * authority on whether a token is active, and a `jti` is only useful to
     * somebody who already holds the token it came from. Requiring verification
     * would also make every token issued before a key rotation introspect as dead
     * while it was still perfectly valid.
     *
     * @return array<string, mixed>|null The row, or null when nothing matches
     */
    protected function findIntrospectableToken(string $token): ?array
    {
        $row = $this->selectTokenRow($token);

        if ($row === null) {
            $jti = $this->extractJwtId($token);
            if ($jti !== null && $jti !== $token) {
                $row = $this->selectTokenRow($jti);
            }
        }

        return $row;
    }

    /**
     * One `usertokens` row by its stored value, joined for the introspection body.
     *
     * @return array<string, mixed>|null
     */
    protected function selectTokenRow(string $stored): ?array
    {
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('usertokens ut')
            ->join('users u', 'ut.userid = u.userid')
            ->join('applications a', 'ut.applicationid = a.appid')
            ->select('ut.*, u.username, u.email, a.apikey AS client_id')
            ->where('ut.token', $stored)
            ->first();

        if (!$result || $result->numRows == 0) {
            return null;
        }

        return (array) $result->fields;
    }

    /**
     * The `jti` claim of a JWT, or null when the value is not one.
     *
     * Decode only. The claim is used as a database key and nothing is trusted
     * because it was in there — see {@see findIntrospectableToken()} for why the
     * signature is deliberately not checked here.
     */
    protected function extractJwtId(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), false);
        if ($payload === false) {
            return null;
        }

        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            return null;
        }

        $jti = $claims['jti'] ?? null;

        return is_string($jti) && $jti !== '' ? $jti : null;
    }

    // ── UserInfo ──────────────────────────────────────────────────────────────

    /**
     * OIDC UserInfo endpoint — OpenID Connect Core §5.3.
     *
     * GET/POST /oauth/userinfo
     * Requires Bearer token with the `openid` scope.
     */
    public function userinfo(): mixed
    {
        $token = $this->extractBearerToken();
        if ($token === null) {
            return \Pramnos\Http\Response::json(['error' => 'invalid_token'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer realm="oauth"');
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('usertokens')
            ->select('userid, scope, expires, status')
            ->where('token', $token)
            ->where('tokentype', 'access_token')
            ->first();

        if (!$result || $result->numRows == 0
            || (int) $result->fields['status'] !== 1
            || ((int) $result->fields['expires'] > 0 && (int) $result->fields['expires'] < time())) {
            return \Pramnos\Http\Response::json([
                'error' => 'invalid_token',
                'error_description' => 'Token expired or invalid'
            ], 401);
        }

        $userId = (int) $result->fields['userid'];
        $scopes = array_filter(explode(' ', (string) ($result->fields['scope'] ?? '')));

        if (!in_array('openid', $scopes, true)) {
            return \Pramnos\Http\Response::json([
                'error' => 'insufficient_scope',
                'error_description' => 'The openid scope is required'
            ], 403);
        }

        $payload = $this->buildUserInfoPayload($userId, $scopes);
        return \Pramnos\Http\Response::json($payload);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    /**
     * OAuth2 Bearer-token logout.
     * POST /oauth/logout
     *
     * Revokes all tokens for the session associated with the presented access
     * token. For browser-session logout use the application's /logout route.
     */
    /**
     * Revoke the tokens of one session — `POST /oauth/logout`.
     *
     * Requires `Authorization: Bearer`, and answers JSON.
     *
     * ## What "one session" means here
     *
     * It used to mean "every token with the same `usertokens.sid`", and there is
     * no such column — there never has been. The `SELECT` failed, the failure was
     * swallowed, and the endpoint took its not-found branch for **every** token,
     * valid or not: it answered `{"success": true}` and revoked nothing. A
     * security endpoint that reports success and takes no action is worse than one
     * that errors, because nothing looks wrong.
     *
     * A session is now the **token family**: the access token and the refresh
     * token issued with it, linked by `usertokens.parentToken` — the column the
     * refresh-token repository writes precisely so that "revocation can cascade".
     * A token issued to another device belongs to another family and is left
     * alone, which is what makes this different from "sign out of everything".
     *
     * With `logoutwebsession=1` the browser session is ended as well. Without it
     * the OAuth tokens go and the browser stays signed in, which is usually what a
     * backend wants and rarely what a "sign out everywhere" button wants.
     *
     * A token that is not found still answers success, in the spirit of RFC 7009:
     * a logout endpoint that distinguished real tokens from invented ones would be
     * an oracle for which tokens exist.
     */
    public function logout(): mixed
    {
        $token = $this->extractBearerToken();
        if ($token === null) {
            return \Pramnos\Http\Response::json(['error' => 'invalid_token'], 401);
        }

        $row = $this->findTokenRow($token);

        if ($row === null) {
            return \Pramnos\Http\Response::json(['success' => true]);
        }

        $userId  = (int) $row['userid'];
        $tokenId = (int) $row['tokenid'];

        // The family root: a refresh token points at the access token it was
        // issued with, so revoking from the root catches both directions.
        $rootId = (int) ($row['parentToken'] ?? 0) ?: $tokenId;

        $revoked = $this->revokeTokenFamily(
            \Pramnos\Framework\Factory::getDatabase(),
            $userId,
            $rootId
        );

        if ($this->wantsWebSessionEnded()) {
            $this->endWebSession();
        }

        $this->recordLogout($userId);

        return \Pramnos\Http\Response::json([
            'success'        => true,
            'user_id'        => $userId,
            'tokens_revoked' => $revoked,
        ]);
    }

    /**
     * The active token row for a bearer token, or null when there is none.
     *
     * A seam, so the endpoint's decisions can be tested without a schema — which
     * matters here more than usual, because the bug this replaced was a query
     * against a column that did not exist, failing silently.
     *
     * @return array{tokenid: mixed, userid: mixed, parentToken: mixed}|null
     */
    protected function findTokenRow(string $token): ?array
    {
        $row = $this->selectActiveTokenRow($token);

        if ($row === null) {
            // A client holds the JWT; what is stored is its `jti`. Without this
            // fallback logout could not find the token it had just been handed —
            // and, like revoke, it answers success either way, so nobody found out.
            $jti = $this->extractJwtId($token);
            if ($jti !== null && $jti !== $token) {
                $row = $this->selectActiveTokenRow($jti);
            }
        }

        return $row;
    }

    /**
     * One active `usertokens` row by its stored value.
     *
     * @return array{tokenid: mixed, userid: mixed, parentToken: mixed}|null
     */
    protected function selectActiveTokenRow(string $stored): ?array
    {
        $result = \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('usertokens')
            ->select(['tokenid', 'userid', 'parentToken'])
            ->where('token', $stored)
            ->where('status', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            return null;
        }

        return (array) $result->fields;
    }

    /** Note the logout in the activity log (seam: the log needs a database). */
    protected function recordLogout(int $userId): void
    {
        \Pramnos\Auth\ActivityLog::record($userId, 'oauth_logout');
    }

    /**
     * Revoke a token and everything issued alongside it.
     *
     * Scoped to the owning user as well as to the family, so a crafted
     * `parentToken` could never reach another account's tokens.
     *
     * @return int How many rows were revoked
     */
    protected function revokeTokenFamily(mixed $db, int $userId, int $rootId): int
    {
        // Counted before the UPDATE rather than from it: `update()` answers a
        // boolean, so a caller reading `tokens_revoked` would always see 0. The
        // number is the reason that field exists — this endpoint answers success
        // whether or not anything matched, so it is the only way to tell a real
        // revocation from a token that was not found. One indexed COUNT on a
        // logout is worth being able to see that.
        $family = static function ($query) use ($rootId) {
            $query->where('tokenid', $rootId)->orWhere('parentToken', $rootId);
        };

        $count = (int) $db->queryBuilder()
            ->table('usertokens')
            ->where('userid', $userId)
            ->where('status', 1)
            ->where($family)
            ->count();

        if ($count === 0) {
            return 0;
        }

        $db->queryBuilder()
            ->table('usertokens')
            ->where('userid', $userId)
            ->where('status', 1)
            ->where($family)
            ->update(['status' => 0]);

        return $count;
    }

    /** Did the caller ask for the browser session to be ended too? */
    protected function wantsWebSessionEnded(): bool
    {
        $value = $_POST['logoutwebsession'] ?? $_GET['logoutwebsession'] ?? '';

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** End the browser session, the same way the browser logout does. */
    protected function endWebSession(): void
    {
        try {
            (new \Pramnos\Auth\Auth())->logout();
        } catch (\Throwable $ex) {
            // The tokens are already gone; a session that could not be ended is
            // worth a log line and not worth failing the request over.
            \Pramnos\Logs\Logger::log('OAuth logout: could not end the web session: ' . $ex->getMessage());
        }
    }

    // ── Device Authorization ──────────────────────────────────────────────────

    /**
     * Device Authorization endpoint — RFC 8628 §3.1.
     * POST /oauth/deviceauthorization
     */
    public function deviceauthorization(): mixed
    {
        $clientId = $_POST['client_id'] ?? null;
        $scope    = $_POST['scope']     ?? '';

        if ($clientId === null) {
            return \Pramnos\Http\Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Missing client_id'
            ], 400);
        }

        try {
            $client = $this->loadClient($clientId);

            $deviceCode  = bin2hex(random_bytes(32));
            $userCode    = $this->generateUserCode();
            $expiresIn   = 600;

            $db = \Pramnos\Framework\Factory::getDatabase();
            $db->queryBuilder()
                ->table('authserver.oauth2_device_codes')
                ->insert([
                    'device_code' => $deviceCode,
                    'user_code'   => $userCode,
                    'client_id'   => $clientId,
                    'scope'       => $scope,
                    'expires_at'  => time() + $expiresIn,
                    'status'      => 'pending',
                ]);

            $verificationUri = sURL . 'device';

            return \Pramnos\Http\Response::json([
                'device_code'              => $deviceCode,
                'user_code'                => $userCode,
                'verification_uri'         => $verificationUri,
                'verification_uri_complete' => $verificationUri . '?user_code=' . $userCode,
                'expires_in'               => $expiresIn,
                'interval'                 => 5,
            ]);
        } catch (\Exception $ex) {
            return \Pramnos\Http\Response::json([
                'error' => 'invalid_request',
                'error_description' => $ex->getMessage()
            ], 400);
        }
    }

    // ── Authorize helpers ─────────────────────────────────────────────────────

    /**
     * Collect and sanitize GET/POST parameters for the authorization endpoint.
     *
     * @return array<string, string>
     */
    private function collectAuthorizeParams(): array
    {
        $get = array_merge($_GET, $_POST);
        return [
            'client_id'             => (string) ($get['client_id']             ?? ''),
            'redirect_uri'          => (string) ($get['redirect_uri']          ?? ''),
            'response_type'         => (string) ($get['response_type']         ?? ''),
            'scope'                 => (string) ($get['scope']                 ?? ''),
            'state'                 => (string) ($get['state']                 ?? ''),
            'code_challenge'        => (string) ($get['code_challenge']        ?? ''),
            'code_challenge_method' => (string) ($get['code_challenge_method'] ?? 'plain'),
        ];
    }

    /**
     * Validate the minimum required parameters for the authorization request.
     *
     * @param array<string, string> $params
     * @throws \InvalidArgumentException on any invalid parameter
     */
    private function validateAuthorizeParams(array $params): void
    {
        if ($params['client_id'] === '') {
            throw new \InvalidArgumentException('Missing client_id');
        }
        if ($params['redirect_uri'] === '') {
            throw new \InvalidArgumentException('Missing redirect_uri');
        }
        if ($params['response_type'] !== 'code') {
            throw new \InvalidArgumentException('Unsupported response_type (only "code" is supported)');
        }

        if ($params['code_challenge'] !== '') {
            if (!in_array($params['code_challenge_method'], ['S256', 'plain'], true)) {
                throw new \InvalidArgumentException('Invalid code_challenge_method');
            }
            if ($params['code_challenge_method'] === 'S256') {
                // RFC 7636 §4.2: code_challenge = BASE64URL(SHA256(code_verifier)), 43–128 chars
                if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $params['code_challenge'])) {
                    throw new \InvalidArgumentException('Invalid code_challenge format');
                }
            }
        }

        // Validate scopes
        if ($params['scope'] !== '') {
            [$hasInvalid, $invalid] = Scopes::hasInvalidScopes($params['scope']);
            if ($hasInvalid) {
                throw OAuthServerException::invalidScope(implode(' ', $invalid));
            }
        }
    }

    /**
     * Handle the POST (user consent decision) for the authorization endpoint.
     */
    private function handleConsentPost(object $user, array $client, array $params): void
    {
        if (($_POST['authorize'] ?? '') === 'yes') {
            $this->recordConsent(
                $user->userid,
                (int) $client['appid'],
                $params['scope']
            );
            \Pramnos\Auth\ActivityLog::record((int) $user->userid, 'application_authorized', [
                'application_id'   => (int) $client['appid'],
                'application_name' => (string) ($client['name'] ?? ''),
                'scope'            => $params['scope'],
            ]);
            $this->issueCodeAndRedirect($user->userid, $params);
        } else {
            $redirectParams = ['error' => 'access_denied'];
            if ($params['state'] !== '') {
                $redirectParams['state'] = $params['state'];
            }
            header('Location: ' . $params['redirect_uri'] . '?' . http_build_query($redirectParams));
            $this->terminate(); return;
        }
    }

    /**
     * Generate an auth code row in the DB and redirect with it.
     */
    private function issueCodeAndRedirect(int $userId, array $params): void
    {
        $authCode = $this->generateAuthCode(
            $params['client_id'],
            $userId,
            $params['scope'],
            $params['redirect_uri'],
            $params['code_challenge']     !== '' ? $params['code_challenge']     : null,
            $params['code_challenge_method'] !== '' ? $params['code_challenge_method'] : null
        );

        $redirectParams = ['code' => $authCode];
        if ($params['state'] !== '') {
            $redirectParams['state'] = $params['state'];
        }
        header('Location: ' . $params['redirect_uri'] . '?' . http_build_query($redirectParams));
        $this->terminate(); return;
    }

    /**
     * Show the HTML consent form (delegates to the OAuth2 view).
     */
    private function showConsentForm(object $user, array $client, array $params): void
    {
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Authorize Application';

        $view = $this->getView('OAuth2');

        $view->application     = (object) $client;
        $view->user            = $user;
        $view->allScopes       = Scopes::getScopes();
        $view->requestedScopes = array_filter(explode(' ', $params['scope']));
        $view->params          = $params;

        $view->display('authorize');
    }

    /**
     * Show a plain HTML error page when the authorization request is invalid.
     */
    private function showErrorPage(string $message): void
    {
        http_response_code(400);
        $doc        = \Pramnos\Framework\Factory::getDocument('html');
        $doc->title = 'Authorization Error';

        echo '<h1>Authorization Error</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
    }

    // ── Auth code generation ──────────────────────────────────────────────────

    /**
     * Generate an authorization code and store it in the DB.
     * Returns the opaque code string.
     */
    private function generateAuthCode(
        string $clientId,
        int    $userId,
        string $scope,
        string $redirectUri,
        ?string $codeChallenge       = null,
        ?string $codeChallengeMethod = null
    ): string {
        $code    = bin2hex(random_bytes(32));
        $expires = time() + 600; // 10 minutes

        $db = \Pramnos\Framework\Factory::getDatabase();

        // Get application ID from client_id (apikey)
        $appResult = $db->queryBuilder()
            ->table('applications')
            ->select('appid')
            ->where('apikey', $clientId)
            ->where('status', 1)
            ->first();

        if (!$appResult || $appResult->numRows == 0) {
            throw new \RuntimeException('Invalid client');
        }
        $appId = (int) $appResult->fields['appid'];

        $db->queryBuilder()
            ->table('usertokens')
            ->insert([
                'token'               => $code,
                'userid'              => $userId,
                'applicationid'       => $appId,
                'tokentype'           => 'auth_code',
                'scope'               => $scope,
                'notes'               => $redirectUri,
                'code_challenge'      => $codeChallenge       ?? '',
                'code_challenge_method' => $codeChallengeMethod ?? 'plain',
                'expires'             => $expires,
                'status'              => 1,
                'created'             => time(),
            ]);

        return $code;
    }

    // ── User helpers ──────────────────────────────────────────────────────────

    /**
     * Return the currently logged-in user object, or null.
     */
    protected function getLoggedInUser(): ?object
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if ($user && isset($user->userid) && $user->userid > 0) {
            return $user;
        }
        return null;
    }

    /**
     * Build the OIDC UserInfo payload, honoring granted scopes.
     *
     * @param  int      $userId
     * @param  string[] $scopes
     * @return array<string, mixed>
     */
    private function buildUserInfoPayload(int $userId, array $scopes): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('users')
            ->where('userid', $userId)
            ->where('active', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            return ['sub' => (string) $userId];
        }

        $u       = (array) $result->fields;
        $payload = ['sub' => (string) $userId];

        if (in_array('email', $scopes, true)) {
            $payload['email']          = $u['email'] ?? '';
            $payload['email_verified'] = isset($u['validated']) && in_array((int) $u['validated'], [1, 3], true);
        }

        if (in_array('profile', $scopes, true)) {
            $payload['name']               = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
            $payload['given_name']         = $u['firstname']  ?? '';
            $payload['family_name']        = $u['lastname']   ?? '';
            $payload['preferred_username'] = $u['username']   ?? '';
            $payload['updated_at']         = $u['modified']   ?? null;
            $payload['picture']            = $u['avatarurl']  ?? null;
            $payload['website']            = $u['website']    ?? null;
        }

        if (in_array('phone', $scopes, true)) {
            $payload['phone_number'] = $u['mobile'] ?? $u['phone'] ?? null;
        }

        if (in_array('user', $scopes, true)) {
            $payload['maingroup'] = $u['maingroup'] ?? null;
            $payload['regdate']   = $u['regdate']   ?? null;
        }

        return $payload;
    }

    // ── Consent store ─────────────────────────────────────────────────────────

    /**
     * Whether a client is trusted and therefore skips the consent screen.
     *
     * A trusted first-party client (`trusted = 1`) uses the silent flow: the
     * authorization server issues the code without prompting the user. Any
     * other value — including a missing column, 0, or an unexpected value —
     * is treated as untrusted so the normal consent path runs. Protected to
     * allow direct unit testing of the decision in isolation.
     *
     * @param array $client The client row as loaded by loadClient().
     */
    protected function clientSkipsConsent(array $client): bool
    {
        return (int) ($client['trusted'] ?? 0) === 1;
    }

    /**
     * Check whether the user has already authorised this application with at
     * least the set of requested scopes.
     *
     * @param int      $userId
     * @param int      $appId
     * @param string[] $requestedScopes
     */
    private function hasUserAuthorizedApp(int $userId, int $appId, array $requestedScopes): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('authserver.oauth2_user_consents')
            ->select('scope')
            ->where('userid', $userId)
            ->where('applicationid', $appId)
            ->first();

        if (!$result || $result->numRows == 0) {
            return false;
        }

        $grantedScopes = array_filter(explode(' ', (string) ($result->fields['scope'] ?? '')));

        foreach ($requestedScopes as $scope) {
            if (!in_array($scope, $grantedScopes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Upsert the user consent record for an application.
     */
    private function recordConsent(int $userId, int $appId, string $scope): void
    {
        $db = \Pramnos\Framework\Factory::getDatabase();

        // Read existing scopes so we only ever expand, never shrink
        $existing = '';
        $result   = $db->queryBuilder()
            ->table('authserver.oauth2_user_consents')
            ->select('scope')
            ->where('userid', $userId)
            ->where('applicationid', $appId)
            ->first();

        if ($result && $result->numRows > 0) {
            $existing = (string) ($result->fields['scope'] ?? '');
        }

        $merged = implode(' ', array_unique(array_filter(array_merge(
            explode(' ', $existing),
            explode(' ', $scope)
        ))));

        if ($existing !== '') {
            $db->queryBuilder()
                ->table('authserver.oauth2_user_consents')
                ->where('userid', $userId)
                ->where('applicationid', $appId)
                ->update(['scope' => $merged, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $db->queryBuilder()
                ->table('authserver.oauth2_user_consents')
                ->insert([
                    'userid'        => $userId,
                    'applicationid' => $appId,
                    'scope'         => $merged,
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
        }
    }

    // ── Client helpers ────────────────────────────────────────────────────────

    /**
     * Load and return the application row for a given client_id (apikey).
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the client does not exist or is inactive
     */
    private function loadClient(string $clientId): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('applications')
            ->where('apikey', $clientId)
            ->where('status', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            throw new \RuntimeException('Invalid or inactive client');
        }

        return (array) $result->fields;
    }

    /**
     * Validate client credentials (apikey + secret) for confidential clients.
     *
     * @param array{client_id: string, client_secret: string} $credentials
     */
    private function validateClientCredentials(array $credentials): bool
    {
        $app = new \Pramnos\Auth\Application($this);
        return $app->validateCredentials(
            $credentials['client_id'],
            $credentials['client_secret']
        );
    }

    /**
     * Extract client credentials from the Basic auth header or POST body.
     *
     * @return array{client_id: string, client_secret: string}|null
     */
    private function extractClientCredentials(): ?array
    {
        // Authorization: Basic base64(client_id:client_secret)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $m)) {
            $decoded = base64_decode($m[1], strict: true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                return ['client_id' => $id, 'client_secret' => $secret];
            }
        }

        // Fallback: POST body
        $id     = $_POST['client_id']     ?? '';
        $secret = $_POST['client_secret'] ?? '';
        if ($id !== '' && $secret !== '') {
            return ['client_id' => $id, 'client_secret' => $secret];
        }

        return null;
    }

    // ── JWT client_credentials bypass ────────────────────────────────────────

    /**
     * Handle client_credentials grant authenticated via JWT client assertion
     * (RFC 7523 §2.2, private_key_jwt).
     *
     * League oauth2-server does not natively support private_key_jwt, so this
     * path validates the assertion manually, manages a per-application system
     * user, issues a signed JWT access token, and stores it in usertokens so
     * that introspect() and revoke() work unchanged.
     *
     * The system user for each application is created exactly once and reused on
     * all subsequent calls.  The regression this fixes (UW-461 equivalent) was
     * the absence of a SELECT before the INSERT that caused a new sys_* user to
     * be created on every repeated token request for the same client.
     */
    private function handleJwtClientCredentials(): \Pramnos\Http\Response
    {
        $clientId  = $_POST['client_id'] ?? null;
        $assertion = $_POST['client_assertion'];
        $scope     = $_POST['scope'] ?? '';

        // Accept client_id from Basic auth header when absent in POST body
        if (!$clientId) {
            $basic = $this->extractClientCredentials();
            if ($basic) {
                $clientId = $basic['client_id'];
            }
        }

        if (!$clientId) {
            return \Pramnos\Http\Response::json([
                'error'             => 'invalid_request',
                'error_description' => 'Missing client_id',
            ], 400);
        }

        // Validate assertion — returns a fully-hydrated Application object so we
        // already have systemuser without a second SELECT (regression fix UW-461).
        $app = $this->validateJwtClientAssertion($assertion, $clientId);
        if ($app === null) {
            return \Pramnos\Http\Response::json([
                'error'             => 'invalid_client',
                'error_description' => 'JWT client assertion validation failed',
            ], 401);
        }

        // `systemuser` is already populated by loadByApiKey(), so an application
        // that has one gets it back without a second SELECT — that reuse is what
        // stopped a new sys_* account being created on every repeated token
        // request (UW-461).
        //
        // The creation itself used to be thirty lines inline here, which is why
        // the secret-authenticated client_credentials grant — which does not come
        // through this method — had no system user at all and could not store a
        // token. One implementation now serves both.
        $systemUserId = $app->systemUserId();

        if ($systemUserId <= 0) {
            return \Pramnos\Http\Response::json([
                'error'             => 'server_error',
                'error_description' => 'Failed to assign system user to application',
            ], 500);
        }

        // Issue a signed JWT access token
        $now     = time();
        $jti     = bin2hex(random_bytes(16));
        $issuer  = defined('sURL') ? rtrim((string) sURL, '/') : 'https://localhost';
        $payload = [
            'iss'        => $issuer,
            'sub'        => (string) $systemUserId,
            'aud'        => $clientId,
            'iat'        => $now,
            'exp'        => $now + 3600,
            'jti'        => $jti,
            'scope'      => $scope,
            'token_type' => 'access_token',
        ];

        $privateKeyPath = ROOT . \DS . 'app' . \DS . 'keys' . \DS . 'private.key';
        if (file_exists($privateKeyPath)) {
            $privateKey = (string) file_get_contents($privateKeyPath);
            $token = \Pramnos\Auth\JWT::encode($payload, $privateKey, 'RS256');
        } else {
            // Fallback to symmetric signing when RSA keys are unavailable
            $token = \Pramnos\Auth\JWT::encode($payload, $clientId);
        }

        // Persist the token so introspect() / revoke() can find it
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()
            ->table('usertokens')
            ->insert([
                'userid'        => $systemUserId,
                'tokentype'     => 'access_token',
                'token'         => $token,
                'created'       => $now,
                'status'        => 1,
                'applicationid' => $app->appid,
                'scope'         => $scope,
                'expires'       => $now + 3600,
                'deviceinfo'    => 'jwt_bearer',
            ]);

        return \Pramnos\Http\Response::json([
            'access_token'       => $token,
            'token_type'         => 'Bearer',
            'expires_in'         => 3600,
            'scope'              => $scope,
            'client_auth_method' => 'jwt_bearer',
        ]);
    }

    /**
     * Validate a JWT client assertion (RFC 7523 §2.2).
     *
     * Verifies the assertion's RS256/RS384/RS512 signature against the
     * application's registered public key and checks the mandatory claims
     * (sub = client_id, exp in the future).
     *
     * Returns the fully-hydrated Application model on success so the caller
     * can access systemuser and other fields without an additional SELECT.
     *
     * @param string $assertion Raw JWT string from the request
     * @param string $clientId  The client_id claim to verify
     * @return \Pramnos\Auth\Application|null  Hydrated Application on success, null on failure
     */
    private function validateJwtClientAssertion(string $assertion, string $clientId): ?\Pramnos\Auth\Application
    {
        $app = new \Pramnos\Auth\Application($this);
        $loaded = $app->loadByApiKey($clientId);

        if ($loaded === false) {
            return null;
        }

        $publicKey = $app->public_key;
        if (empty($publicKey)) {
            return null;
        }

        try {
            $payload = \Pramnos\Auth\JWT::decode($assertion, $publicKey, ['RS256', 'RS384', 'RS512']);
        } catch (\Exception $e) {
            return null;
        }

        // sub claim must equal the client_id being authenticated
        if (!isset($payload->sub) || $payload->sub !== $clientId) {
            return null;
        }

        // exp must be in the future (JWT::decode also checks this, but be explicit)
        if (!isset($payload->exp) || (int) $payload->exp < time()) {
            return null;
        }

        return $app;
    }

    // ── Device-code helpers ───────────────────────────────────────────────────

    /**
     * Generate an 8-character human-readable user code (RFC 8628 §6.1).
     * Uses an alphabet that avoids visually ambiguous characters.
     */
    private function generateUserCode(): string
    {
        $alphabet = 'BCDFGHJKLMNPQRSTVWXZ';
        $code     = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === 4) {
                $code .= '-';
            }
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    // ── Bearer token extraction ───────────────────────────────────────────────

    /**
     * Extract the Bearer token from the Authorization header.
     */
    /**
     * Protected rather than private so a subclass can supply the token.
     *
     * Widening this is additive — nothing outside could call it before, and
     * nothing outside can call it now — and it is what lets the logout and
     * introspection decisions be tested without building a request.
     */
    protected function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);

        if ($header === null) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        return $m[1];
    }

    // ── PSR-7 bridge ──────────────────────────────────────────────────────────

    /**
     * Build a PSR-7 ServerRequest from PHP globals.
     *
     * nyholm/psr7 provides the factories but not a from-globals helper
     * (that lives in nyholm/psr7-server). We recreate the request manually
     * so we avoid adding another dependency.
     */
    private function buildPsrServerRequest(Psr17Factory $factory): \Psr\Http\Message\ServerRequestInterface
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $factory->createUri($scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'));

        $request = $factory->createServerRequest($method, $uri, $_SERVER);

        // Attach request body so League can read POST fields from php://input
        $bodyStream = $factory->createStreamFromResource(fopen('php://input', 'r'));
        $request    = $request->withBody($bodyStream);

        // Attach parsed body ($_POST) for application/x-www-form-urlencoded requests
        if (!empty($_POST)) {
            $request = $request->withParsedBody($_POST);
        }

        // Forward all HTTP headers
        foreach (($this->getAllRequestHeaders()) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Retrieve all HTTP request headers as a name → value map.
     *
     * Falls back to manual extraction from $_SERVER when getallheaders() is
     * unavailable (e.g. FastCGI without Apache).
     *
     * @return array<string, string>
     */
    private function getAllRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name           = str_replace('_', '-', $key);
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    // ── PSR-7 response emitter ────────────────────────────────────────────────

    /**
     * Emit a PSR-7 response as a framework Response.
     */
    private function emitPsrResponse(\Psr\Http\Message\ResponseInterface $psrResponse): \Pramnos\Http\Response
    {
        $response = \Pramnos\Http\Response::make(
            (string) $psrResponse->getBody(),
            $psrResponse->getStatusCode()
        );
        foreach ($psrResponse->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withHeader($name, $value);
            }
        }
        return $response;
    }
}
