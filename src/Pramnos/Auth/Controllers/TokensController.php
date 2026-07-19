<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;

/**
 * Admin controller for managing OAuth2 tokens.
 *
 * Provides read access to issued tokens and allows revocation for
 * security/compliance purposes. Tokens are never deleted — revoking sets
 * status=3 (revoked) and records a removedate timestamp for the audit trail.
 *
 * This is the single controller for all token management. It serves two tiers:
 *
 *   Global (cross-user) — the OAuth/authserver admin view. Sensitive because it
 *   exposes every user's tokens at once, so it requires usertype >= 90:
 *     - display()    — paginated list of active tokens (with user and app info)
 *     - revoke($id)  — revoke a single token by tokenid
 *     - revokeall()  — POST: bulk revoke by filters (userid and/or applicationid)
 *
 *   Per-user — token management for one user, part of the base `auth` User
 *   admin, so it requires only usertype >= 80 (same tier as the Users admin):
 *     - userid($id)     — all tokens for one user (any status) with management actions
 *     - deactivate()    — POST: set a user's token to inactive (status=0)
 *     - delete()        — POST: soft-delete a user's token (status=2)
 *
 * The per-user view is reachable as `Tokens/userid/{id}`; the legacy
 * `users/tokens/{id}` route redirects here for backward compatibility.
 *
 * Scaffold wrappers at `src/Controllers/Tokens.php` (authserver feature only).
 *
 */
class TokensController extends Controller
{
    /** Minimum usertype for the global, cross-user token views (display/revoke). */
    protected int $requiredUserType = 90;

    /** Minimum usertype for per-user token management (userid/deactivate/delete). */
    protected int $perUserUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'revoke', 'revokeall', 'userid', 'deactivate', 'delete']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated DataTable of active tokens, joined with user and application info.
     * Supports optional query-string filters: user_id, app_id, scope.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'OAuth2 Tokens';

        $db   = \Pramnos\Framework\Factory::getDatabase();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $qb = $db->queryBuilder()
            ->table('#PREFIX#usertokens ut')
            ->join('#PREFIX#users u', 'ut.userid', '=', 'u.userid')
            // LEFT join: session/API tokens (web_session, apns, …) have no
            // applicationid — an inner join would hide every non-OAuth token.
            ->leftJoin('applications a', 'ut.applicationid', '=', 'a.appid')
            ->select([
                'ut.tokenid', 'u.username', 'u.email', 'a.name AS app_name',
                'ut.tokentype', 'ut.scope', 'ut.expires', 'ut.lastused', 'ut.status',
            ])
            ->where('ut.status', 1);

        $filterUserId = (int) ($_GET['user_id'] ?? 0);
        $filterAppId  = (int) ($_GET['app_id']  ?? 0);

        if ($filterUserId > 0) {
            $qb->where('ut.userid', $filterUserId);
        }
        if ($filterAppId > 0) {
            $qb->where('ut.applicationid', $filterAppId);
        }

        $view         = $this->getView('tokens');
        $view->tokens = $qb->orderBy('ut.lastused', 'desc')->forPage($page, 50)->getAll();
        $view->total  = (clone $qb)->count();
        $view->page   = $page;

        return $view->display();
    }

    /**
     * Revoke a single token by tokenid.
     * Sets status=3 (revoked) and records removedate for the audit trail.
     * Redirects back to display after revoking.
     */
    public function revoke(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        // The …/revoke/<id> segment is exposed by the request as the "option"
        // — the dispatcher does not pass it as a method argument.
        $tokenId = (int) (\Pramnos\Http\Request::staticGetOption() ?? 0);
        if ($tokenId <= 0) {
            $this->redirect(sURL . 'tokens?error=invalid_id');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('tokenid', $tokenId)
            ->where('status', 1)
            ->update(['status' => 3, 'removedate' => time()]);

        $this->redirect(sURL . 'tokens?message=revoked');
    }

    /**
     * Bulk revoke tokens matching one or more POST filters.
     * Required: at least one of `userid` or `applicationid` must be provided
     * to prevent accidentally revoking all tokens in the system.
     *
     * Optional POST fields:
     *   - userid        (int) — revoke all active tokens for this user
     *   - applicationid (int) — revoke all active tokens for this application
     *
     * Both filters are combined with AND when both are provided.
     */
    public function revokeall(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $userId = (int) ($_POST['userid']        ?? 0);
        $appId  = (int) ($_POST['applicationid'] ?? 0);

        // Require at least one filter to prevent full-table revocation
        if ($userId <= 0 && $appId <= 0) {
            $this->redirect(sURL . 'tokens?error=filter_required');
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();
        $qb = $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('status', 1);

        if ($userId > 0) {
            $qb->where('userid', $userId);
        }
        if ($appId > 0) {
            $qb->where('applicationid', $appId);
        }

        $qb->update(['status' => 3, 'removedate' => time()]);

        $this->redirect(sURL . 'tokens?message=revoked_all');
    }

    // ── Per-user token management (base auth tier, usertype >= 80) ──────────────

    /**
     * List every token (any status) belonging to a single user, with per-token
     * management actions. Reached as `Tokens/userid/{id}`.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption).
     */
    public function userid(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->perUserUserType)) {
            return null;
        }

        // The …/userid/<id> segment is exposed by the request as the "option".
        $userId = (int) (\Pramnos\Http\Request::staticGetOption() ?? 0);
        if ($userId <= 0) {
            $this->redirect(sURL . 'users');
            return null;
        }

        $user = new \Pramnos\User\User();
        $user->load($userId);
        if ((int) $user->userid !== $userId) {
            $this->redirect(sURL . 'users');
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Tokens: ' . htmlspecialchars((string) $user->username, ENT_QUOTES, 'UTF-8');

        $view            = $this->getView('tokens');
        $view->user      = ['userid' => (int) $user->userid, 'username' => (string) $user->username];
        $view->tokenList = $user->getAllTokens();
        return $view->display('user');
    }

    /**
     * Deactivate (status=0) a specific token belonging to a user.
     * Expects POST: userid, tokenid. Redirects back to the per-user list.
     */
    public function deactivate(): void
    {
        $this->tokenStatusAction('deactivateToken');
    }

    /**
     * Soft-delete (status=2) a specific token belonging to a user.
     * Expects POST: userid, tokenid. Redirects back to the per-user list.
     */
    public function delete(): void
    {
        $this->tokenStatusAction('deleteToken');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Shared handler for the per-user token POST actions (deactivate/delete):
     * validates ownership then calls the matching User method.
     *
     * @param string $method User method to invoke ('deactivateToken'|'deleteToken').
     */
    private function tokenStatusAction(string $method): void
    {
        if ($this->requireMinUserType($this->perUserUserType)) {
            return;
        }

        $userId  = (int) ($_POST['userid']  ?? 0);
        $tokenId = (int) ($_POST['tokenid'] ?? 0);

        if ($userId > 0 && $tokenId > 0) {
            $user = new \Pramnos\User\User();
            $user->load($userId);
            // Only act when the token truly belongs to the loaded user.
            if ((int) $user->userid === $userId) {
                $user->$method($tokenId);
            }
        }

        $this->redirect(sURL . 'Tokens/userid/' . $userId);
    }

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    protected function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if ($user === null || $user === false || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
            return true;
        }

        return false;
    }
}
