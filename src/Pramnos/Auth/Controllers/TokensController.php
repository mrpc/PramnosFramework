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
 * Actions:
 *   - display()    — paginated DataTable of active tokens (with user and app info)
 *   - revoke($id)  — revoke a single token by tokenid
 *   - revokeall()  — POST: bulk revoke by filters (userid and/or applicationid)
 *
 * All actions require authentication + usertype >= 90 (admin).
 *
 * Scaffold wrappers at `src/Controllers/Tokens.php` (authserver feature only).
 *
 * @package     PramnosFramework
 * @subpackage  Auth\Controllers
 */
class TokensController extends Controller
{
    /** Minimum usertype to access any tokens action. */
    protected int $requiredUserType = 90;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'revoke', 'revokeall']);
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
            ->join('applications a', 'ut.applicationid', '=', 'a.appid')
            ->select([
                'ut.tokenid', 'u.username', 'u.email', 'a.name AS app_name',
                'ut.scope', 'ut.expires', 'ut.lastused', 'ut.status',
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
        $view->tokens = $qb->orderBy('ut.lastused', 'desc')->forPage($page, 50)->get();
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

        $tokenId = (int) ($id ?? 0);
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

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    protected function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if ($user === null || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
            return true;
        }

        return false;
    }
}
