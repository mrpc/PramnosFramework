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
 * @package     PramnosFramework
 * @subpackage  Auth\Controllers
 */
class Oauth extends Controller
{
    private OAuth2ServerFactory $oauth2Factory;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);

        $this->addaction([
            'authorize', 'token', 'revoke', 'introspect',
            'userinfo', 'logout', 'deviceauthorization',
        ]);

        $this->oauth2Factory = new OAuth2ServerFactory($this);
        $this->oauth2Factory->generateKeyPair();

        header('Access-Control-Allow-Origin: *');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            exit(0);
        }
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
    public function token(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        try {
            $psrFactory  = new Psr17Factory();
            $psrRequest  = $this->buildPsrServerRequest($psrFactory);
            $psrResponse = $psrFactory->createResponse();

            $authServer  = $this->oauth2Factory->createAuthorizationServer();
            $psrResponse = $authServer->respondToAccessTokenRequest($psrRequest, $psrResponse);

            $this->emitPsrResponse($psrResponse);

        } catch (OAuthServerException $ex) {
            $this->emitPsrResponse($ex->generateHttpResponse(new Psr17Factory()->createResponse()));
        } catch (\Exception $ex) {
            http_response_code(500);
            echo json_encode([
                'error'             => 'server_error',
                'error_description' => $ex->getMessage(),
            ]);
        }
        exit;
    }

    // ── Revocation ────────────────────────────────────────────────────────────

    /**
     * Token revocation endpoint — RFC 7009.
     *
     * POST /oauth/revoke
     * Parameters: token, token_type_hint (optional)
     */
    public function revoke(): void
    {
        header('Content-Type: application/json');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'method_not_allowed']);
            exit;
        }

        $token = $_POST['token'] ?? '';
        if ($token === '') {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => 'Missing token parameter']);
            exit;
        }

        // RFC 7009: revocation always returns 200 (even for unknown tokens)
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "UPDATE usertokens SET status = 0 WHERE token = %s AND status = 1",
            $token
        );
        $db->query($sql);

        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    /**
     * Token introspection endpoint — RFC 7662.
     *
     * POST /oauth/introspect
     * Requires client authentication (Basic or POST body).
     */
    public function introspect(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'method_not_allowed']);
            exit;
        }

        $credentials = $this->extractClientCredentials();
        if ($credentials === null || !$this->validateClientCredentials($credentials)) {
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="OAuth2"');
            echo json_encode(['error' => 'invalid_client']);
            exit;
        }

        $token = $_POST['token'] ?? '';
        if ($token === '') {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => 'Missing token parameter']);
            exit;
        }

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "SELECT ut.*, u.username, u.email, a.apikey AS client_id
               FROM usertokens ut
               JOIN users u ON ut.userid = u.userid
               JOIN applications a ON ut.applicationid = a.appid
              WHERE ut.token = %s",
            $token
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            echo json_encode(['active' => false]);
            exit;
        }

        $row     = (array) $result->fields;
        $isActive = (int) $row['status'] === 1
                 && ((int) $row['expires'] === 0 || (int) $row['expires'] > time());

        if (!$isActive) {
            echo json_encode(['active' => false]);
            exit;
        }

        echo json_encode([
            'active'     => true,
            'scope'      => $row['scope']     ?? '',
            'client_id'  => $row['client_id'] ?? '',
            'username'   => $row['username']  ?? '',
            'token_type' => 'Bearer',
            'exp'        => (int) ($row['expires'] ?? 0),
            'iat'        => (int) ($row['created'] ?? 0),
            'sub'        => (string) ($row['userid'] ?? ''),
        ]);
        exit;
    }

    // ── UserInfo ──────────────────────────────────────────────────────────────

    /**
     * OIDC UserInfo endpoint — OpenID Connect Core §5.3.
     *
     * GET/POST /oauth/userinfo
     * Requires Bearer token with the `openid` scope.
     */
    public function userinfo(): void
    {
        header('Content-Type: application/json');

        $token = $this->extractBearerToken();
        if ($token === null) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer realm="oauth"');
            echo json_encode(['error' => 'invalid_token']);
            exit;
        }

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "SELECT ut.userid, ut.scope, ut.expires, ut.status
               FROM usertokens ut
              WHERE ut.token = %s AND ut.tokentype = 'access_token'",
            $token
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0
            || (int) $result->fields['status'] !== 1
            || ((int) $result->fields['expires'] > 0 && (int) $result->fields['expires'] < time())) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_token', 'error_description' => 'Token expired or invalid']);
            exit;
        }

        $userId = (int) $result->fields['userid'];
        $scopes = array_filter(explode(' ', (string) ($result->fields['scope'] ?? '')));

        if (!in_array('openid', $scopes, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'insufficient_scope', 'error_description' => 'The openid scope is required']);
            exit;
        }

        $payload = $this->buildUserInfoPayload($userId, $scopes);
        echo json_encode($payload);
        exit;
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    /**
     * OAuth2 Bearer-token logout.
     * POST /oauth/logout
     *
     * Revokes all tokens for the session associated with the presented access
     * token. For browser-session logout use the application's /logout route.
     */
    public function logout(): void
    {
        header('Content-Type: application/json');

        $token = $this->extractBearerToken();
        if ($token === null) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_token']);
            exit;
        }

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "SELECT userid, sid FROM usertokens WHERE token = %s AND status = 1",
            $token
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            // Token not found — still return success per RFC 7009 spirit
            echo json_encode(['success' => true]);
            exit;
        }

        $userId = (int) $result->fields['userid'];
        $sid    = $result->fields['sid'] ?? null;

        // Revoke all tokens for this user session
        if ($sid !== null) {
            $sql = $db->prepareQuery(
                "UPDATE usertokens SET status = 0 WHERE userid = %d AND sid = %s AND status = 1",
                $userId,
                $sid
            );
        } else {
            $sql = $db->prepareQuery(
                "UPDATE usertokens SET status = 0 WHERE userid = %d AND status = 1",
                $userId
            );
        }
        $db->query($sql);

        echo json_encode(['success' => true, 'user_id' => $userId]);
        exit;
    }

    // ── Device Authorization ──────────────────────────────────────────────────

    /**
     * Device Authorization endpoint — RFC 8628 §3.1.
     * POST /oauth/deviceauthorization
     */
    public function deviceauthorization(): void
    {
        header('Content-Type: application/json');

        $clientId = $_POST['client_id'] ?? null;
        $scope    = $_POST['scope']     ?? '';

        if ($clientId === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => 'Missing client_id']);
            exit;
        }

        try {
            $client = $this->loadClient($clientId);

            $deviceCode  = bin2hex(random_bytes(32));
            $userCode    = $this->generateUserCode();
            $expiresIn   = 600;

            $db  = \Pramnos\Framework\Factory::getDatabase();
            $sql = $db->prepareQuery(
                "INSERT INTO oauth2_device_codes
                    (device_code, user_code, client_id, scope, expires_at, status)
                 VALUES (%s, %s, %s, %s, %d, 'pending')",
                $deviceCode,
                $userCode,
                $clientId,
                $scope,
                time() + $expiresIn
            );
            $db->query($sql);

            $verificationUri = sURL . 'device';

            echo json_encode([
                'device_code'              => $deviceCode,
                'user_code'                => $userCode,
                'verification_uri'         => $verificationUri,
                'verification_uri_complete' => $verificationUri . '?user_code=' . $userCode,
                'expires_in'               => $expiresIn,
                'interval'                 => 5,
            ]);
        } catch (\Exception $ex) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => $ex->getMessage()]);
        }
        exit;
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
            $this->issueCodeAndRedirect($user->userid, $params);
        } else {
            $redirectParams = ['error' => 'access_denied'];
            if ($params['state'] !== '') {
                $redirectParams['state'] = $params['state'];
            }
            header('Location: ' . $params['redirect_uri'] . '?' . http_build_query($redirectParams));
            exit;
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
        exit;
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

        $db  = \Pramnos\Framework\Factory::getDatabase();

        // Get application ID from client_id (apikey)
        $appSql = $db->prepareQuery(
            'SELECT appid FROM applications WHERE apikey = %s AND status = 1',
            $clientId
        );
        $appResult = $db->query($appSql);
        if (!$appResult || $appResult->numRows == 0) {
            throw new \RuntimeException('Invalid client');
        }
        $appId = (int) $appResult->fields['appid'];

        $sql = $db->prepareQuery(
            "INSERT INTO usertokens
                (token, userid, applicationid, tokentype, scope, redirect_uri,
                 code_challenge, code_challenge_method, expires, status, created)
             VALUES (%s, %d, %d, 'auth_code', %s, %s, %s, %s, %d, 1, %d)",
            $code,
            $userId,
            $appId,
            $scope,
            $redirectUri,
            $codeChallenge       ?? '',
            $codeChallengeMethod ?? 'plain',
            $expires,
            time()
        );
        $db->query($sql);

        return $code;
    }

    // ── User helpers ──────────────────────────────────────────────────────────

    /**
     * Return the currently logged-in user object, or null.
     */
    private function getLoggedInUser(): ?object
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
        $sql    = $db->prepareQuery(
            'SELECT * FROM users WHERE userid = %d AND active = 1',
            $userId
        );
        $result = $db->query($sql);

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
     * Check whether the user has already authorised this application with at
     * least the set of requested scopes.
     *
     * @param int      $userId
     * @param int      $appId
     * @param string[] $requestedScopes
     */
    private function hasUserAuthorizedApp(int $userId, int $appId, array $requestedScopes): bool
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT scope FROM oauth2_user_consents WHERE userid = %d AND applicationid = %d',
            $userId,
            $appId
        );
        $result = $db->query($sql);

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
        $sql      = $db->prepareQuery(
            'SELECT scope FROM oauth2_user_consents WHERE userid = %d AND applicationid = %d',
            $userId,
            $appId
        );
        $result = $db->query($sql);
        if ($result && $result->numRows > 0) {
            $existing = (string) ($result->fields['scope'] ?? '');
        }

        $merged = implode(' ', array_unique(array_filter(array_merge(
            explode(' ', $existing),
            explode(' ', $scope)
        ))));

        if ($existing !== '') {
            $sql = $db->prepareQuery(
                'UPDATE oauth2_user_consents SET scope = %s, updated_at = NOW()
                  WHERE userid = %d AND applicationid = %d',
                $merged,
                $userId,
                $appId
            );
        } else {
            $sql = $db->prepareQuery(
                'INSERT INTO oauth2_user_consents (userid, applicationid, scope, created_at, updated_at)
                 VALUES (%d, %d, %s, NOW(), NOW())',
                $userId,
                $appId,
                $merged
            );
        }
        $db->query($sql);
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
        $sql    = $db->prepareQuery(
            'SELECT * FROM applications WHERE apikey = %s AND status = 1',
            $clientId
        );
        $result = $db->query($sql);

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
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $sql    = $db->prepareQuery(
            'SELECT appid FROM applications WHERE apikey = %s AND apisecret = %s AND status = 1',
            $credentials['client_id'],
            $credentials['client_secret']
        );
        $result = $db->query($sql);
        return $result && $result->numRows > 0;
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
    private function extractBearerToken(): ?string
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
     * Emit a PSR-7 response to the PHP output buffer.
     */
    private function emitPsrResponse(\Psr\Http\Message\ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }
        echo $response->getBody();
    }
}
