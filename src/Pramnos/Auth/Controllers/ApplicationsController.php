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
 *   - edit($id)      — create/edit form (all application fields)
 *   - save()         — POST handler; generates client_id/client_secret on create
 *   - delete($id)    — soft-delete (status=0) + revoke all active tokens
 *   - tokens($id)    — list active tokens for an application
 *   - rotate($id)    — regenerate the client secret (apisecret)
 *
 * All actions require authentication + usertype >= 90 (admin).
 *
 * Scaffold wrappers at `src/Controllers/Applications.php` (authserver feature only).
 *
 */
class ApplicationsController extends Controller
{
    /** Minimum usertype to access any applications action. */
    protected int $requiredUserType = 90;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'data', 'view', 'edit', 'save', 'delete', 'tokens', 'rotate']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Read-only detail view for a single OAuth2 application.
     *
     * Shows full application metadata, API key (read-only), token statistics
     * (total/active/revoked), and the 5 most recent users who accessed the app.
     */
    public function view(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id = (int) ($id ?? 0);
        if ($id <= 0) {
            $this->redirect(sURL . 'applications?error=invalid_id');
            return null;
        }

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $app = $db->queryBuilder()
            ->table('applications')
            ->where('appid', $id)
            ->first();

        if (!$app || $app->numRows === 0) {
            $this->redirect(sURL . 'applications?error=not_found');
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Application: ' . htmlspecialchars((string) ($app->fields['name'] ?? ''), ENT_QUOTES);

        $tokenStats = ['total' => 0, 'active' => 0, 'revoked' => 0];
        $lastUsers  = [];
        try {
            $tokenStats['total']   = $db->queryBuilder()->table('#PREFIX#usertokens')->where('applicationid', $id)->count();
            $tokenStats['active']  = $db->queryBuilder()->table('#PREFIX#usertokens')->where('applicationid', $id)->where('status', 1)->count();
            $tokenStats['revoked'] = $db->queryBuilder()->table('#PREFIX#usertokens')->where('applicationid', $id)->where('status', 3)->count();

            $lastUsers = $db->queryBuilder()
                ->table('#PREFIX#usertokens ut')
                ->join('#PREFIX#users u', 'ut.userid', '=', 'u.userid')
                ->select(['u.userid', 'u.username', 'ut.lastused', 'ut.ipaddress', 'ut.scope'])
                ->where('ut.applicationid', $id)
                ->orderBy('ut.lastused', 'desc')
                ->limit(5)
                ->get();
        } catch (\Exception $e) {
            // usertokens or users may not exist in all deployments
        }

        $view             = $this->getView('applications');
        $view->app        = $app->fields;
        $view->tokenStats = $tokenStats;
        $view->lastUsers  = $lastUsers;

        return $view->display('view');
    }

    /**
     * DataTable list of OAuth2 applications — shell only; rows loaded via AJAX from data().
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'OAuth2 Applications';

        $dt = new \Pramnos\Html\Datatable('dt-applications');
        $dt->source    = sURL . 'applications/data';
        $dt->bootstrap = false;
        $dt->addColumn('ID',          true,  true,  true,  'num')
           ->addColumn('Name',        true,  true,  true)
           ->addColumn('API Key',     true,  true,  true)
           ->addColumn('Status',      true,  true,  false, 'html')
           ->addColumn('Added',       true,  true,  false)
           ->addColumn('Actions',     true,  false, false, 'html');

        $view            = $this->getView('applications');
        $view->datatable = $dt;
        return $view->display();
    }

    /**
     * AJAX data endpoint for the applications DataTable.
     */
    public function data(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }
        \Pramnos\Framework\Factory::getDocument('json');

        $fields = ['appid', 'name', 'apikey', 'status', 'added'];
        $result = \Pramnos\Html\Datatable\Datasource::getList(
            'applications',
            $fields,
            false
        );

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            $id     = (int) $row[0];
            $status = (int) $row[3];
            $added  = (int) $row[4];
            $row[3] = $status === 1
                ? '<span style="color:green">Active</span>'
                : '<span style="color:#888">Inactive</span>';
            $row[4] = $added > 0 ? date('Y-m-d', $added) : '';
            $row[]  = '<a href="' . sURL . 'applications/view/' . $id . '">View</a> '
                    . '<a href="' . sURL . 'applications/edit/' . $id . '">Edit</a> '
                    . '<a href="' . sURL . 'applications/delete/' . $id . '" data-confirm="Delete this application?">Delete</a>';
            unset($row['DT_RowId']);
        }
        unset($row);

        echo json_encode($result);
        $this->terminate();
    }

    /**
     * Terminate the request. Can be mocked in tests.
     */
    protected function terminate(): void
    {
        exit;
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

        $view              = $this->getView('applications');
        $view->application = null;
        $view->message     = $_GET['message'] ?? '';
        $view->error       = $_GET['error'] ?? '';

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

        $id          = (int)    ($_POST['appid']           ?? 0);
        $name        = trim((string) ($_POST['name']           ?? ''));
        $description = trim((string) ($_POST['description']    ?? ''));
        $callback    = trim((string) ($_POST['callback']       ?? ''));
        $scope       = trim((string) ($_POST['scope']          ?? ''));
        $status      = (int)    ($_POST['status']          ?? 1);
        $apptype     = (int)    ($_POST['apptype']         ?? 0);
        $accesstype  = (int)    ($_POST['accesstype']      ?? 0);
        $apiversion  = trim((string) ($_POST['apiversion']     ?? 'v1'));
        $appversion  = trim((string) ($_POST['appversion']     ?? ''));
        $public      = isset($_POST['public']) ? 1 : 0;
        $organization    = trim((string) ($_POST['organization']    ?? ''));
        $organizationurl = trim((string) ($_POST['organizationurl'] ?? ''));
        $url             = trim((string) ($_POST['url']             ?? ''));
        $supportemail    = trim((string) ($_POST['supportemail']    ?? ''));
        $termsurl        = trim((string) ($_POST['termsurl']        ?? ''));
        $privacyurl      = trim((string) ($_POST['privacyurl']      ?? ''));
        $public_key      = trim((string) ($_POST['public_key']      ?? ''));
        $jwks_uri        = trim((string) ($_POST['jwks_uri']        ?? ''));

        if ($name === '') {
            $this->redirect(sURL . 'applications/edit/' . $id . '?error=name_required');
            return;
        }

        // Clamp to valid apptype/accesstype ranges.
        $apptype    = max(0, min(5, $apptype));
        $accesstype = max(0, min(2, $accesstype));
        $status     = max(0, min(1, $status));

        $db = \Pramnos\Framework\Factory::getDatabase();

        $fields = [
            'name'            => $name,
            'description'     => $description !== '' ? $description : null,
            'callback'        => $callback !== '' ? $callback : null,
            'scope'           => $scope !== '' ? $scope : null,
            'status'          => $status,
            'apptype'         => $apptype,
            'accesstype'      => $accesstype,
            'apiversion'      => $apiversion !== '' ? $apiversion : 'v1',
            'appversion'      => $appversion,
            'public'          => $public,
            'organization'    => $organization !== '' ? $organization : null,
            'organizationurl' => $organizationurl !== '' ? $organizationurl : null,
            'url'             => $url !== '' ? $url : null,
            'supportemail'    => $supportemail !== '' ? $supportemail : null,
            'termsurl'        => $termsurl !== '' ? $termsurl : null,
            'privacyurl'      => $privacyurl !== '' ? $privacyurl : null,
            'public_key'      => $public_key !== '' ? $public_key : null,
            'jwks_uri'        => $jwks_uri !== '' ? $jwks_uri : null,
        ];

        if ($id > 0) {
            $db->queryBuilder()
                ->table('applications')
                ->where('appid', $id)
                ->update($fields);
        } else {
            $fields['apikey']    = bin2hex(random_bytes(16));
            $fields['apisecret'] = bin2hex(random_bytes(32));
            $fields['added']     = time();
            $db->queryBuilder()
                ->table('applications')
                ->insert($fields);
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

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $app = $db->queryBuilder()
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
            ->select(['ut.tokenid', 'u.username', 'ut.scope', 'ut.expires', 'ut.lastused', 'ut.status', 'ut.ipaddress', 'ut.tokentype'])
            ->where('ut.applicationid', $appId)
            ->where('ut.status', 1)
            ->orderBy('ut.lastused', 'desc')
            ->get();

        $view         = $this->getView('applications');
        $view->app    = $app->fields;
        $view->tokens = $tokens;

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
