<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;

/**
 * Admin controller for managing registered OAuth2 applications (clients).
 *
 * Operates on the `applications` table created by the
 * `create_applications_table` migration (authserver feature).
 *
 * Actions:
 *   - display()      — paginated DataTable list of OAuth2 applications
 *   - edit($id)      — create/edit form (name, description, redirect URIs, scopes)
 *   - save()         — POST handler; generates client_id/client_secret on create
 *   - delete($id)    — soft-delete (status=0) + revoke all active tokens
 *   - tokens($id)    — list active tokens for an application
 *   - rotate($id)    — regenerate the client secret (apisecret)
 *
 * All actions require authentication + usertype >= 90 (admin).
 *
 * Scaffold wrappers at `src/Controllers/Applications.php` (authserver feature only).
 *
 * @package     PramnosFramework
 * @subpackage  Auth\Controllers
 */
class ApplicationsController extends Controller
{
    /** Minimum usertype to access any applications action. */
    protected int $requiredUserType = 90;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'edit', 'save', 'delete', 'tokens', 'rotate']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated DataTable list of registered OAuth2 applications.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'OAuth2 Applications';

        $db   = \Pramnos\Framework\Factory::getDatabase();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $view  = $this->getView('applications');
        $view->applications = $db->queryBuilder()
            ->table('applications')
            ->select(['appid', 'name', 'description', 'apikey', 'status', 'added'])
            ->orderBy('name')
            ->forPage($page, 50)
            ->get();
        $view->total = $db->queryBuilder()->table('applications')->count();
        $view->page  = $page;

        return $view->display();
    }

    /**
     * Create/edit form for an OAuth2 application.
     * id=0 opens the create form; existing id loads the current data.
     */
    public function edit(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id  = (int) ($id ?? 0);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit Application' : 'New Application';

        $view = $this->getView('applications');
        $view->application = null;

        if ($id > 0) {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()
                ->table('applications')
                ->where('appid', $id)
                ->first();

            if (!$result || $result->numRows === 0) {
                $this->redirect(sURL . 'applications?error=not_found');
                return null;
            }

            $view->application = $result->fields;
        }

        return $view->display('edit');
    }

    /**
     * POST handler: create a new application or update an existing one.
     * On create, generates a cryptographically random client_id (apikey) and
     * client_secret (apisecret). Existing credentials are never overwritten on update.
     */
    public function save(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id          = (int)    ($_POST['appid']       ?? 0);
        $name        = trim((string) ($_POST['name']        ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $callback    = trim((string) ($_POST['callback']    ?? ''));
        $scope       = trim((string) ($_POST['scope']       ?? ''));
        $status      = (int) ($_POST['status'] ?? 1);

        if ($name === '') {
            $this->redirect(sURL . 'applications/edit/' . $id . '?error=name_required');
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();

        if ($id > 0) {
            $db->queryBuilder()
                ->table('applications')
                ->where('appid', $id)
                ->update([
                    'name'        => $name,
                    'description' => $description !== '' ? $description : null,
                    'callback'    => $callback !== '' ? $callback : null,
                    'scope'       => $scope !== '' ? $scope : null,
                    'status'      => $status,
                ]);
        } else {
            $apiKey    = bin2hex(random_bytes(16));
            $apiSecret = bin2hex(random_bytes(32));

            $db->queryBuilder()
                ->table('applications')
                ->insert([
                    'name'        => $name,
                    'description' => $description !== '' ? $description : null,
                    'callback'    => $callback !== '' ? $callback : null,
                    'scope'       => $scope !== '' ? $scope : null,
                    'apikey'      => $apiKey,
                    'apisecret'   => $apiSecret,
                    'status'      => 1,
                    'added'       => time(),
                ]);
        }

        $this->redirect(sURL . 'applications?message=saved');
    }

    /**
     * Soft-delete an application (status=0) and revoke all of its active tokens.
     * Tokens are kept in the database with status=3 (revoked) for the audit trail.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) ($id ?? 0);
        if ($id <= 0) {
            $this->redirect(sURL . 'applications?error=invalid_id');
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();

        // Revoke all active tokens for this application
        $db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('applicationid', $id)
            ->where('status', 1)
            ->update(['status' => 3, 'removedate' => time()]);

        // Soft-delete the application
        $db->queryBuilder()
            ->table('applications')
            ->where('appid', $id)
            ->update(['status' => 0]);

        $this->redirect(sURL . 'applications?message=deleted');
    }

    /**
     * List active tokens issued to a specific application.
     */
    public function tokens(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $appId = (int) ($id ?? 0);
        if ($appId <= 0) {
            $this->redirect(sURL . 'applications?error=invalid_id');
            return null;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $app    = $db->queryBuilder()
            ->table('applications')
            ->select(['appid', 'name', 'apikey'])
            ->where('appid', $appId)
            ->first();

        if (!$app || $app->numRows === 0) {
            $this->redirect(sURL . 'applications?error=not_found');
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Tokens — ' . htmlspecialchars((string) ($app->fields['name'] ?? ''), ENT_QUOTES);

        $tokens = $db->queryBuilder()
            ->table('#PREFIX#usertokens ut')
            ->join('#PREFIX#users u', 'ut.userid', '=', 'u.userid')
            ->select(['ut.tokenid', 'u.username', 'ut.scope', 'ut.expires', 'ut.lastused', 'ut.status'])
            ->where('ut.applicationid', $appId)
            ->where('ut.status', 1)
            ->orderBy('ut.lastused', 'desc')
            ->get();

        $view          = $this->getView('applications');
        $view->app     = $app->fields;
        $view->tokens  = $tokens;

        return $view->display('tokens');
    }

    /**
     * Rotate the client secret (apisecret) for an application.
     * Generates a new 256-bit hex secret. All existing tokens remain valid until
     * they expire naturally — they do not depend on the current client secret.
     */
    public function rotate(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) ($id ?? 0);
        if ($id <= 0) {
            $this->redirect(sURL . 'applications?error=invalid_id');
            return;
        }

        $newSecret = bin2hex(random_bytes(32));

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('applications')
            ->where('appid', $id)
            ->update(['apisecret' => $newSecret]);

        $this->redirect(sURL . 'applications/edit/' . $id . '?message=secret_rotated');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    private function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if ($user === null || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
            return true;
        }

        return false;
    }
}
