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
    /**
     * Request types this endpoint accepts.
     *
     * The stored vocabulary is the GDPR one the table documents — `access`,
     * `erasure`, `portability`, `rectification`, `restriction`. `export` and
     * `delete` are kept as accepted spellings because they are what this
     * endpoint has always documented, and are normalised on write by
     * {@see storedRequestType()} so the column holds one vocabulary rather than
     * two names for the same right.
     */
    private const VALID_REQUEST_TYPES = [
        'export', 'delete', 'portability', 'access', 'erasure',
        'rectification', 'restriction',
    ];

    /**
     * Accepted spelling → the vocabulary the column stores.
     */
    private const REQUEST_TYPE_ALIASES = [
        'export' => 'access',
        'delete' => 'erasure',
    ];
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

        // The response keys are kept as they were documented; only the columns
        // they are read from are corrected.
        $query = $this->requests()
            ->select([
                'id AS request_id',
                'userid AS user_id',
                'request_type',
                'status',
                'response_data AS data_export_url',
                'requested_at AS created_at',
                'completed_at',
                'processed_by',
            ])
            ->where('id', $requestId);

        if (!$isAdmin) {
            $query->where('userid', $userId);
        }

        $result = $query->get();

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

        // One filter, applied to both the page and the count — built once so the
        // two can never disagree about what is being listed.
        $scope = null;
        if (!$isAdmin) {
            $scope = (int) $userId;
        } elseif (isset($_GET['user_id'])) {
            $scope = (int) $_GET['user_id'];
        }

        $pageQuery = $this->requests()
            ->select([
                'id AS request_id',
                'userid AS user_id',
                'request_type',
                'status',
                'requested_at AS created_at',
                'completed_at',
                'processed_by',
            ])
            ->orderBy('requested_at', 'desc')
            ->limit($limit)
            ->offset($offset);

        $counter = $this->requests();

        if ($scope !== null) {
            $pageQuery->where('userid', $scope);
            $counter->where('userid', $scope);
        }

        $requests = [];
        foreach ($pageQuery->get() as $row) {
            $requests[] = (array) $row;
        }

        $total = $counter->count();

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
    protected function resolveActor(): array
    {
        // Token auth, through the framework's own token loader. It knows what a
        // valid token is on this schema — status, both `auth` and `access_token`
        // types, and `expires = 0` meaning "never", which a hand-written
        // `expires > now` comparison rejects.
        $token = \Pramnos\Http\Request::accessToken();
        if ($token !== null && $token !== '') {
            $user = $this->userFromToken($token);

            if ($user !== null && (int) $user->userid >= 2) {
                return [(int) $user->userid, $this->isAdmin($user)];
            }

            return [null, false];
        }

        // Session auth. `$_SESSION['user']` holds a User object everywhere in
        // the framework, so reading it as an array — as this did — always
        // yielded null, and every session-authenticated request was refused.
        $user = \Pramnos\User\User::getCurrentUser();
        if (is_object($user) && (int) ($user->userid ?? 0) >= 2) {
            return [(int) $user->userid, $this->isAdmin($user)];
        }

        return [null, false];
    }

    /**
     * The user a bearer token identifies, or null.
     *
     * A seam of its own so the token path can be exercised without standing up
     * the whole token schema: what belongs to this controller is *what it does*
     * with the resolved user, not how `loadByToken()` finds them.
     *
     * @param  string $token
     * @return \Pramnos\User\User|null
     */
    protected function userFromToken(string $token): ?\Pramnos\User\User
    {
        $user = new \Pramnos\User\User();
        $user->loadByToken($token, 'auth', false);

        return (int) $user->userid >= 2 ? $user : null;
    }

    /**
     * Is this an administrator?
     *
     * The framework's admin tier is `usertype >= 90`, which is what the Users,
     * Applications and Permissions admin controllers all require. This
     * controller previously read a boolean `is_admin` column that exists in no
     * migration and no schema, so its admin branch could never be reached: on a
     * real database the query failed outright, and via the session it read a key
     * nothing ever sets.
     *
     * @param  object $user
     * @return bool
     */
    protected function isAdmin(object $user): bool
    {
        return (int) ($user->usertype ?? 0) >= 90;
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
        // The table records the data subject, not the actor. When an admin files
        // a request on somebody else's behalf, that fact belongs in the audit
        // trail, and request_details is where the schema keeps it — there is no
        // column for a second user, and adding one to record it would be a
        // schema change this controller has no business making.
        $details = $requestedBy !== $userId
            ? 'Submitted on behalf of the user by userid ' . $requestedBy
            : null;

        $this->requests()->insert([
            'userid'          => $userId,
            'request_type'    => $this->storedRequestType($requestType),
            'status'          => 'pending',
            'requested_at'    => $db->queryBuilder()->raw('NOW()'),
            'request_details' => $details,
            'ip_address'      => \Pramnos\Http\Request::clientIp() ?: null,
        ]);

        return (int) $db->getInsertId();
    }

    /**
     * The vocabulary the column stores for an accepted request type.
     *
     * @param  string $requestType As submitted
     * @return string As stored
     */
    private function storedRequestType(string $requestType): string
    {
        return self::REQUEST_TYPE_ALIASES[$requestType] ?? $requestType;
    }

    /**
     * A query builder scoped to the GDPR requests table.
     *
     * The table lives in the `authserver` schema — `authserver.gdpr_requests` on
     * PostgreSQL, `authserver_gdpr_requests` on MySQL — and the builder is what
     * knows the difference.
     *
     * This controller previously queried `oauth2_gdpr_requests`, a table no
     * migration has ever created: it was backported from an OAuth server whose
     * schema was never adopted here, so every one of these endpoints failed at
     * runtime.
     *
     * @return \Pramnos\Database\QueryBuilder
     */
    private function requests()
    {
        return \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('authserver.gdpr_requests');
    }

    /**
     * Set status = 0 (revoked) on all active tokens for a user.
     * Returns the number of rows affected.
     */
    private function revokeUserTokens(\Pramnos\Database\Database $db, int $userId): int
    {
        $result = $db->queryBuilder()
            ->table('usertokens')
            ->where('userid', $userId)
            ->where('status', 1)
            ->update(['status' => 0]);

        return is_object($result) ? (int) $result->getAffectedRows() : 0;
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Decode the JSON request body into an associative array.
     *
     * @return array<string, mixed>
     */
    protected function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        return json_decode($raw, true) ?? [];
    }
}
