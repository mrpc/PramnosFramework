<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;

/**
 * Session management controller.
 *
 * Exposes three public endpoints for AJAX clients and OAuth2 relying parties:
 *   - check      — is the session / Bearer token still valid?
 *   - heartbeat  — keep the session alive (session-based only)
 *   - info       — detailed user + token data
 *   - refresh    — extend session lifetime (session-based only)
 *
 * Supports two authentication methods transparently:
 *   1. Session cookies (`$_SESSION`)
 *   2. Bearer tokens (`Authorization: Bearer <token>` header)
 *
 * @package     PramnosFramework
 * @subpackage  Auth\Controllers
 */
class Session extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->addaction(['check', 'info', 'heartbeat', 'refresh']);

        header('Access-Control-Allow-Origin: *');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            exit(0);
        }
    }

    // ── Public endpoints ──────────────────────────────────────────────────────

    /**
     * Check whether the current session or Bearer token is active.
     * Safe to poll frequently — does not extend session lifetime.
     */
    public function check(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $isBearerAuth = $this->extractBearerToken() !== null;
        $isLoggedIn   = $this->isUserLoggedIn();

        if ($isLoggedIn) {
            if (!$isBearerAuth) {
                $_SESSION['last_activity'] = time();
            }

            $sessionData = $this->getSessionData();

            echo json_encode([
                'status'        => 'active',
                'logged_in'     => true,
                'auth_method'   => $isBearerAuth ? 'bearer_token' : 'session',
                'user_id'       => $this->extractField($sessionData, 'userid'),
                'username'      => $this->extractField($sessionData, 'username'),
                'last_activity' => $isBearerAuth ? null : ($_SESSION['last_activity'] ?? null),
                'expires_in'    => $this->getTimeRemaining(),
            ]);
        } else {
            echo json_encode([
                'status'    => 'expired',
                'logged_in' => false,
                'message'   => 'Session expired or user not authenticated',
            ]);
        }

        exit;
    }

    /**
     * Heartbeat — update `last_activity` to prevent session timeout.
     * For Bearer-token clients this is a no-op (tokens expire on their own).
     */
    public function heartbeat(): void
    {
        header('Content-Type: application/json');

        $isBearerAuth = $this->extractBearerToken() !== null;

        if ($this->isUserLoggedIn()) {
            if (!$isBearerAuth) {
                $_SESSION['last_activity'] = time();
            }

            echo json_encode([
                'status'      => 'ok',
                'auth_method' => $isBearerAuth ? 'bearer_token' : 'session',
                'timestamp'   => time(),
                'expires_in'  => $this->getTimeRemaining(),
            ]);
        } else {
            http_response_code(401);
            echo json_encode([
                'status'  => 'unauthorized',
                'message' => $isBearerAuth ? 'Invalid or expired token' : 'Session expired',
            ]);
        }

        exit;
    }

    /**
     * Return detailed information about the authenticated user and their active
     * OAuth2 tokens.
     */
    public function info(): void
    {
        header('Content-Type: application/json');

        if (!$this->isUserLoggedIn()) {
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }

        $isBearerAuth = $this->extractBearerToken() !== null;
        $sessionData  = $this->getSessionData();
        $userId       = $this->extractField($sessionData, 'userid');
        $userTokens   = $this->getActiveTokens((int) $userId);

        $response = [
            'user' => [
                'id'       => $userId,
                'username' => $this->extractField($sessionData, 'username'),
                'email'    => $this->extractField($sessionData, 'email'),
            ],
            'authentication' => [
                'method'     => $isBearerAuth ? 'bearer_token' : 'session',
                'expires_in' => $this->getTimeRemaining(),
            ],
            'tokens' => [
                'active_count' => count($userTokens),
                'applications' => $this->groupTokensByApp($userTokens),
            ],
        ];

        if (!$isBearerAuth) {
            $response['session'] = [
                'started'       => $_SESSION['login_time'] ?? null,
                'last_activity' => $_SESSION['last_activity'] ?? null,
                'max_lifetime'  => (int) ini_get('session.gc_maxlifetime'),
            ];
        }

        echo json_encode($response);
        exit;
    }

    /**
     * Refresh / extend session lifetime.
     * Returns HTTP 400 for Bearer-token clients — use the refresh_token grant
     * at the token endpoint instead.
     */
    public function refresh(): void
    {
        header('Content-Type: application/json');

        if ($this->extractBearerToken() !== null) {
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Bearer tokens cannot be refreshed through this endpoint. '
                           . 'Use the refresh_token grant type instead.',
            ]);
            exit;
        }

        if ($this->isUserLoggedIn()) {
            $_SESSION['last_activity'] = time();

            echo json_encode([
                'status'     => 'refreshed',
                'timestamp'  => time(),
                'expires_in' => $this->getTimeRemaining(),
            ]);
        } else {
            http_response_code(401);
            echo json_encode([
                'status'  => 'failed',
                'message' => 'Cannot refresh expired session',
            ]);
        }

        exit;
    }

    // ── Auth helpers ──────────────────────────────────────────────────────────

    /**
     * Returns true when either Bearer-token auth or session auth succeeds.
     */
    private function isUserLoggedIn(): bool
    {
        if ($this->authenticateWithBearerToken() !== null) {
            return true;
        }

        // Session path: let Auth.php sync the session if needed
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser) {
            $_SESSION['user']          = $currentUser;
            $_SESSION['last_activity'] = time();
        }

        if (empty($_SESSION['user'])) {
            return false;
        }

        // Enforce session idle timeout
        $maxLifetime = (int) ini_get('session.gc_maxlifetime');
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $maxLifetime) {
            unset($_SESSION['user']);
            return false;
        }

        return true;
    }

    /**
     * Returns unified user data regardless of auth method.
     *
     * @return array<string, mixed>
     */
    private function getSessionData(): array
    {
        $bearerUser = $this->authenticateWithBearerToken();
        if ($bearerUser !== null) {
            return $bearerUser;
        }

        $user = $_SESSION['user'] ?? [];
        return is_array($user) ? $user : [];
    }

    /**
     * Remaining lifetime in seconds for the current credential.
     */
    private function getTimeRemaining(): int
    {
        $token = $this->extractBearerToken();
        if ($token !== null) {
            $tokenData = $this->validateAccessToken($token);
            if ($tokenData && isset($tokenData['expires'])) {
                return max(0, (int) $tokenData['expires'] - time());
            }
            return 0;
        }

        $maxLifetime  = (int) ini_get('session.gc_maxlifetime');
        $lastActivity = (int) ($_SESSION['last_activity'] ?? time());
        return max(0, $maxLifetime - (time() - $lastActivity));
    }

    // ── Bearer-token helpers ──────────────────────────────────────────────────

    /**
     * Extract the raw token value from the `Authorization: Bearer …` header.
     */
    private function extractBearerToken(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);

        if ($authHeader === null) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Validate the Bearer token and return the corresponding user row, or null.
     *
     * @return array<string, mixed>|null
     */
    private function authenticateWithBearerToken(): ?array
    {
        $token = $this->extractBearerToken();
        if ($token === null) {
            return null;
        }

        $tokenData = $this->validateAccessToken($token);
        if (!$tokenData) {
            return null;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $sql    = $db->prepareQuery(
            'SELECT userid, username, email FROM users WHERE userid = %d AND active = 1',
            $tokenData['userid']
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            return null;
        }

        return (array) $result->fields;
    }

    /**
     * Validate an access token against the database and JWT signature.
     * Returns the token row on success, false on failure.
     *
     * @return array<string, mixed>|false
     */
    private function validateAccessToken(string $token): array|false
    {
        try {
            $db  = \Pramnos\Framework\Factory::getDatabase();
            $sql = $db->prepareQuery(
                "SELECT ut.*, a.apikey
                   FROM usertokens ut
                   JOIN applications a ON ut.applicationid = a.appid
                  WHERE ut.token = %s
                    AND ut.tokentype = 'access_token'
                    AND ut.status = 1
                    AND ut.expires > %d",
                $token,
                time()
            );

            $result = $db->query($sql);
            if (!$result || $result->numRows == 0) {
                return false;
            }

            $tokenData = (array) $result->fields;

            // Verify JWT signature
            $privatePath = ROOT . '/app/keys/private.key';
            $publicPath  = ROOT . '/app/keys/public.key';

            if (file_exists($privatePath) && file_exists($publicPath)) {
                $publicKey = file_get_contents($publicPath);
                \Pramnos\Auth\JWT::decode($token, $publicKey, ['RS256']);
            } else {
                // Fallback to symmetric HMAC verification
                \Pramnos\Auth\JWT::decode($token, $tokenData['apikey'], ['HS256']);
            }

            return $tokenData;
        } catch (\Exception $ex) {
            return false;
        }
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    /**
     * Return active token rows for a user (joined with application name).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getActiveTokens(int $userId): array
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT ut.tokentype, ut.created, ut.expires, ut.lastused, a.name AS app_name
               FROM usertokens ut
               JOIN applications a ON ut.applicationid = a.appid
              WHERE ut.userid = %d AND ut.status = 1
                AND (ut.expires = 0 OR ut.expires > %d)',
            $userId,
            time()
        );

        $result = $db->query($sql);
        $tokens = [];

        if ($result) {
            while ($result->fetch()) {
                $tokens[] = (array) $result->fields;
            }
        }

        return $tokens;
    }

    /**
     * Group token rows by application name and compute per-app aggregates.
     *
     * @param  array<int, array<string, mixed>> $tokens
     * @return array<int, array<string, mixed>>
     */
    private function groupTokensByApp(array $tokens): array
    {
        $byApp = [];

        foreach ($tokens as $token) {
            $name = (string) ($token['app_name'] ?? 'unknown');
            if (!isset($byApp[$name])) {
                $byApp[$name] = ['name' => $name, 'token_count' => 0, 'last_used' => 0];
            }
            $byApp[$name]['token_count']++;
            $byApp[$name]['last_used'] = max(
                $byApp[$name]['last_used'],
                (int) ($token['lastused'] ?? 0)
            );
        }

        return array_values($byApp);
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Extract a field from session data that may be an array or an object.
     */
    private function extractField(mixed $data, string $field): mixed
    {
        if (is_array($data)) {
            return $data[$field] ?? null;
        }
        if (is_object($data)) {
            return $data->$field ?? null;
        }
        return null;
    }
}
