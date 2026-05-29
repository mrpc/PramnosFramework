<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\WebhookService;
use Pramnos\Application\Controller;

/**
 * GDPR data-management endpoints.
 *
 * All endpoints require authentication (session or Bearer token). Admin users
 * may additionally target a specific user_id via the request body.
 *
 * Supported actions:
 *   - request         — create a GDPR export/delete/portability request
 *   - status          — query status of a GDPR request
 *   - listRequests    — paginated list of GDPR requests
 *   - deauthorizeAll  — revoke all OAuth2 tokens for a user
 *   - notifyChange    — queue a profile-changed webhook event
 *
 * Webhook events are queued via WebhookService::queueEvent() so that
 * registered application endpoints receive notifications asynchronously.
 *
 */
class Gdpr extends Controller
{
    private const VALID_REQUEST_TYPES = ['export', 'delete', 'portability'];
    private const VALID_REVOKE_REASONS = [
        'user_revoked', 'admin_revoked', 'gdpr_deletion', 'security_violation',
    ];

    private WebhookService $webhookService;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->addaction(['request', 'status', 'listRequests', 'deauthorizeAll', 'notifyChange']);
        $this->webhookService = new WebhookService(\Pramnos\Framework\Factory::getDatabase());
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * Create a GDPR data request.
     * POST /gdpr/request
     *
     * Body: { "request_type": "export"|"delete"|"portability", "user_id": <int> (admin only) }
     */
    public function request(): void
    {
        header('Content-Type: application/json');

        [$userId, $isAdmin] = $this->resolveActor();
        if ($userId === null && !$isAdmin) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        $input       = $this->readJsonBody();
        $requestType = $input['request_type'] ?? '';

        if (!in_array($requestType, self::VALID_REQUEST_TYPES, true)) {
            http_response_code(400);
            echo json_encode([
                'error'       => 'Invalid request_type',
                'valid_types' => self::VALID_REQUEST_TYPES,
            ]);
            return;
        }

        $targetUserId = (int) $userId;
        if ($isAdmin && isset($input['user_id'])) {
            $targetUserId = (int) $input['user_id'];
        }

        try {
            $db        = \Pramnos\Framework\Factory::getDatabase();
            $requestId = $this->insertGdprRequest($db, $targetUserId, $requestType, (int) $userId);

            // Notify registered endpoints asynchronously
            $this->webhookService->queueEvent(
                'gdpr_request_created',
                $targetUserId,
                ['request_id' => $requestId, 'request_type' => $requestType, 'requested_by' => $userId]
            );

            echo json_encode([
                'success'      => true,
                'request_id'   => $requestId,
                'message'      => "GDPR {$requestType} request created successfully",
                'user_id'      => $targetUserId,
                'request_type' => $requestType,
            ]);
        } catch (\Exception $ex) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create GDPR request: ' . $ex->getMessage()]);
        }
    }

    /**
     * Query the status of a GDPR request.
     * GET /gdpr/status?request_id=<id>
     */
    public function status(): void
    {
        header('Content-Type: application/json');

        $requestId = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;
        if ($requestId === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing request_id']);
            return;
        }

        [$userId, $isAdmin] = $this->resolveActor();
        if ($userId === null && !$isAdmin) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        $db          = \Pramnos\Framework\Factory::getDatabase();
        $userFilter  = $isAdmin ? '' : $db->prepareQuery(
            ' AND (user_id = %d OR requested_by = %d)', $userId, $userId
        );

        $sql = $db->prepareQuery(
            'SELECT request_id, user_id, request_type, status,
                    apps_notified, apps_confirmed, data_export_url,
                    expires_at, created_at, completed_at, requested_by
               FROM oauth2_gdpr_requests
              WHERE request_id = %d' . $userFilter,
            $requestId
        );

        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            http_response_code(404);
            echo json_encode(['error' => 'GDPR request not found']);
            return;
        }

        echo json_encode(['request' => (array) $result->fields]);
    }

    /**
     * Paginated list of GDPR requests for the current user (or all, for admins).
     * GET /gdpr/listRequests?page=<n>&limit=<n>&user_id=<n> (admin filter)
     */
    public function listRequests(): void
    {
        header('Content-Type: application/json');

        [$userId, $isAdmin] = $this->resolveActor();
        if ($userId === null && !$isAdmin) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $db          = \Pramnos\Framework\Factory::getDatabase();
        $whereClause = '1=1';

        if (!$isAdmin) {
            $whereClause = $db->prepareQuery('user_id = %d', $userId);
        } elseif (isset($_GET['user_id'])) {
            $whereClause = $db->prepareQuery('user_id = %d', (int) $_GET['user_id']);
        }

        $sql = $db->prepareQuery(
            'SELECT request_id, user_id, request_type, status, created_at, completed_at, requested_by
               FROM oauth2_gdpr_requests
              WHERE ' . $whereClause . '
              ORDER BY created_at DESC
              LIMIT %d OFFSET %d',
            $limit,
            $offset
        );

        $result   = $db->query($sql);
        $requests = [];

        if ($result) {
            while ($result->fetch()) {
                $requests[] = (array) $result->fields;
            }
        }

        $countSql    = $db->prepareQuery(
            'SELECT COUNT(*) AS total FROM oauth2_gdpr_requests WHERE ' . $whereClause
        );
        $countResult = $db->query($countSql);
        $total       = $countResult ? (int) ($countResult->fields['total'] ?? 0) : 0;

        echo json_encode([
            'requests'   => $requests,
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Revoke all active OAuth2 tokens for a user across all applications.
     * POST /gdpr/deauthorizeAll
     *
     * Body: { "reason": "user_revoked|admin_revoked|gdpr_deletion|security_violation",
     *         "user_id": <int> (admin only) }
     */
    public function deauthorizeAll(): void
    {
        header('Content-Type: application/json');

        [$userId, $isAdmin] = $this->resolveActor();
        if ($userId === null && !$isAdmin) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        $input        = $this->readJsonBody();
        $reason       = $input['reason'] ?? 'user_revoked';
        $targetUserId = (int) $userId;

        if ($isAdmin && isset($input['user_id'])) {
            $targetUserId = (int) $input['user_id'];
        }

        if (!in_array($reason, self::VALID_REVOKE_REASONS, true)) {
            http_response_code(400);
            echo json_encode([
                'error'         => 'Invalid reason',
                'valid_reasons' => self::VALID_REVOKE_REASONS,
            ]);
            return;
        }

        try {
            $db  = \Pramnos\Framework\Factory::getDatabase();

            // Revoke all active tokens for the target user
            $revokedCount = $this->revokeUserTokens($db, $targetUserId);

            // Queue a webhook event for each distinct application
            $this->webhookService->queueEvent(
                'token_revoked',
                $targetUserId,
                ['reason' => $reason, 'revoked_count' => $revokedCount, 'revoked_by' => $userId]
            );

            echo json_encode([
                'success'             => true,
                'message'             => 'User deauthorized from all applications',
                'user_id'             => $targetUserId,
                'reason'              => $reason,
                'total_tokens_revoked' => $revokedCount,
            ]);
        } catch (\Exception $ex) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to deauthorize user: ' . $ex->getMessage()]);
        }
    }

    /**
     * Notify registered endpoints that the user's profile has changed.
     * POST /gdpr/notifyChange
     *
     * Body: { "changes": ["email", "name", ...], "user_id": <int> (admin only) }
     */
    public function notifyChange(): void
    {
        header('Content-Type: application/json');

        [$userId, $isAdmin] = $this->resolveActor();
        if ($userId === null && !$isAdmin) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        $input        = $this->readJsonBody();
        $changes      = $input['changes'] ?? [];
        $targetUserId = (int) $userId;

        if ($isAdmin && isset($input['user_id'])) {
            $targetUserId = (int) $input['user_id'];
        }

        if (empty($changes)) {
            http_response_code(400);
            echo json_encode(['error' => 'No changes specified']);
            return;
        }

        try {
            $this->webhookService->queueEvent(
                'profile_changed',
                $targetUserId,
                ['changes' => $changes, 'changed_by' => $userId]
            );

            echo json_encode([
                'success' => true,
                'message' => 'Profile change notifications queued',
                'user_id' => $targetUserId,
                'changes' => $changes,
            ]);
        } catch (\Exception $ex) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to queue notifications: ' . $ex->getMessage()]);
        }
    }

    // ── Auth helpers ──────────────────────────────────────────────────────────

    /**
     * Resolve the current user ID and admin flag from session or Bearer token.
     *
     * @return array{0: int|null, 1: bool}  [userId, isAdmin]
     */
    private function resolveActor(): array
    {
        // Bearer token auth
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);

        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            $db  = \Pramnos\Framework\Factory::getDatabase();
            $sql = $db->prepareQuery(
                "SELECT ut.userid, u.is_admin
                   FROM usertokens ut
                   JOIN users u ON ut.userid = u.userid
                  WHERE ut.token = %s AND ut.tokentype = 'access_token'
                    AND ut.status = 1 AND ut.expires > %d",
                $m[1],
                time()
            );
            $result = $db->query($sql);
            if ($result && $result->numRows > 0) {
                return [
                    (int) $result->fields['userid'],
                    (bool) ($result->fields['is_admin'] ?? false),
                ];
            }
            return [null, false];
        }

        // Session auth
        $userId  = $_SESSION['user_id']  ?? (isset($_SESSION['user']) ? ($_SESSION['user']['userid'] ?? null) : null);
        $isAdmin = (bool) ($_SESSION['is_admin'] ?? false);

        return [$userId !== null ? (int) $userId : null, $isAdmin];
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    /**
     * Insert a GDPR request row and return the new request ID.
     */
    private function insertGdprRequest(
        \Pramnos\Database\Database $db,
        int $userId,
        string $requestType,
        int $requestedBy
    ): int {
        $sql = $db->prepareQuery(
            "INSERT INTO oauth2_gdpr_requests (user_id, request_type, status, requested_by, created_at)
             VALUES (%d, %s, 'pending', %d, NOW())",
            $userId,
            $requestType,
            $requestedBy
        );
        $db->query($sql);
        return (int) $db->getInsertId();
    }

    /**
     * Set status = 0 (revoked) on all active tokens for a user.
     * Returns the number of rows affected.
     */
    private function revokeUserTokens(\Pramnos\Database\Database $db, int $userId): int
    {
        $sql    = $db->prepareQuery(
            'UPDATE usertokens SET status = 0 WHERE userid = %d AND status = 1',
            $userId
        );
        $result = $db->query($sql);
        return $result ? $result->getAffectedRows() : 0;
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Decode the JSON request body into an associative array.
     *
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        return json_decode($raw, true) ?? [];
    }
}
