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

        // JWT client assertion (RFC 7523) for client_credentials is handled manually
        // because League oauth2-server does not natively support private_key_jwt
        // client authentication.  The bypass also manages the per-application system
        // user so introspect() / revoke() continue to work on the issued tokens.
        if (($_POST['grant_type'] ?? '') === 'client_credentials'
            && isset($_POST['client_assertion'])
            && ($_POST['client_assertion_type'] ?? '') === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        ) {
            echo $this->handleJwtClientCredentials();
            exit;
        }

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
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()
            ->table('usertokens')
            ->where('token', $token)
            ->where('status', 1)
            ->update(['status' => 0]);

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

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('usertokens ut')
            ->join('users u', 'ut.userid = u.userid')
            ->join('applications a', 'ut.applicationid = a.appid')
            ->select('ut.*, u.username, u.email, a.apikey AS client_id')
            ->where('ut.token', $token)
            ->first();

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

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('usertokens')
            ->select('userid, sid')
            ->where('token', $token)
            ->where('status', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            // Token not found — still return success per RFC 7009 spirit
            echo json_encode(['success' => true]);
            exit;
        }

        $userId = (int) $result->fields['userid'];
        $sid    = $result->fields['sid'] ?? null;

        // Revoke all tokens for this user session
        $updateQb = $db->queryBuilder()
            ->table('usertokens')
            ->where('userid', $userId)
            ->where('status', 1);

        if ($sid !== null) {
            $updateQb->where('sid', $sid);
        }

        $updateQb->update(['status' => 0]);

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

            $db = \Pramnos\Framework\Factory::getDatabase();
            $db->queryBuilder()
                ->table('oauth2_device_codes')
                ->insert([
                    'device_code' => $deviceCode,
                    'user_code'   => $userCode,
                    'client_id'   => $clientId,
                    'scope'       => $scope,
                    'expires_at'  => time() + $expiresIn,
                    'status'      => 'pending',
                ]);

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
                'redirect_uri'        => $redirectUri,
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
            ->table('oauth2_user_consents')
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
            ->table('oauth2_user_consents')
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
                ->table('oauth2_user_consents')
                ->where('userid', $userId)
                ->where('applicationid', $appId)
                ->update(['scope' => $merged, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $db->queryBuilder()
                ->table('oauth2_user_consents')
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
    private function handleJwtClientCredentials(): string
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
            http_response_code(400);
            return (string) json_encode([
                'error'             => 'invalid_request',
                'error_description' => 'Missing client_id',
            ]);
        }

        // Validate assertion — returns a fully-hydrated Application object so we
        // already have systemuser without a second SELECT (regression fix UW-461).
        $app = $this->validateJwtClientAssertion($assertion, $clientId);
        if ($app === null) {
            http_response_code(401);
            return (string) json_encode([
                'error'             => 'invalid_client',
                'error_description' => 'JWT client assertion validation failed',
            ]);
        }

        // systemuser is already populated by loadByApiKey() — no extra SELECT needed.
        // This is the key fix for UW-461: reuse the existing system user instead of
        // creating a new one on every repeated token request.
        $systemUserId = $app->systemuser ? (int) $app->systemuser : null;

        // Create a system user only when this application has none yet
        if (!$systemUserId) {
            $user             = new \Pramnos\User\User();
            $user->usertype   = 1; // system user
            $user->username   = 'sys_' . bin2hex(random_bytes(8));
            $user->email      = $user->username . '@system.local';
            $user->active     = 1;
            $user->validated  = 1;
            $user->regdate    = time();
            $user->save();
            $systemUserId = (int) $user->userid;

            if (!$app->assignSystemUser($systemUserId)) {
                http_response_code(500);
                return (string) json_encode([
                    'error'             => 'server_error',
                    'error_description' => 'Failed to assign system user to application',
                ]);
            }
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

        return (string) json_encode([
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
