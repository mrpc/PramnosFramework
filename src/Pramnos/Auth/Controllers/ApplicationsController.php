<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Html\Icon;

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
 *   - webhook()            — POST: add or replace an endpoint for one event type
 *   - webhookrotate($id)   — POST: a new signing secret for one endpoint
 *   - webhookdelete($id)   — POST: remove one endpoint
 *
 * All actions require authentication + usertype >= 90 (admin).
 *
 * Scaffold wrappers at `src/Controllers/Applications.php` (authserver feature only).
 *
 */
class ApplicationsController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.applications';

    /** Minimum usertype to access any applications action. */
    protected int $requiredUserType = 90;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'data', 'view', 'edit', 'save', 'delete', 'tokens', 'rotate',
            'webhook', 'webhookrotate', 'webhookdelete',
        ]);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['save', 'delete', 'rotate', 'webhook', 'webhookrotate', 'webhookdelete']);
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

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('applications'));
            return null;
        }

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $app = $db->queryBuilder()
            ->table('#PREFIX#applications')
            ->where('appid', $id)
            ->first();

        if (!$app || $app->numRows === 0) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('applications'));
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

        /**
         * What this application has declared it understands.
         *
         * The capabilities RFC's write side existed on its own: an application
         * could push its resources, scopes and ABAC condition keys, and nothing
         * showed an operator what had arrived. A permission grant names a
         * resource, so "which names exist for this client" is a question this page
         * is asked constantly and could not answer.
         *
         * Read here rather than on a screen of its own, because the answer belongs
         * to an application and this is the page for an application.
         */
        $view->capabilities = $this->capabilitiesReader()->describe((int) $app->fields['appid']);

        /*
         * Where this application's events go, whoever put the address there.
         *
         * An application registers its own endpoints through `/Webhook/register`, and until
         * this list existed an operator had no way to see what it had registered, short of a
         * query. The same card is where an administrator enters one: an address typed here is
         * the operator's own and is delivered to as written, private network or not.
         */
        try {
            $view->webhooks = $this->webhookService()->endpointsFor((int) $app->fields['appid']);
        } catch (\Throwable) {
            // Without the authserver webhook tables there is nothing to list.
            $view->webhooks = [];
        }
        $view->webhookTypes = \Pramnos\Auth\WebhookEvents::names();
        $view->webhookRequiresHttps = \Pramnos\Auth\WebhookService::requiresHttps();

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
        $dt->source    = adminUrl('applications/data');
        $dt->bootstrap = false;
        // Per-column filters: one box over every column finds a name, and cannot
        // answer "the inactive clients" or "the key starting pk_live". See
        // Datatable::$minSearchLength for why they wait three characters.
        $dt->footerTextSearch = true;

        $statusFilter = new \Pramnos\Html\Select('status_filter');
        $statusFilter->id = 'dt-applications-status';
        $statusFilter->addOptions(['' => 'Any status', '1' => 'Active', '0' => 'Inactive']);

        $dt->addColumn('ID',       true, true,  true,  'num', '', true, 'left', true)
           ->addColumn('Name',     true, true,  true,  '',    '', true, 'left', true)
           ->addColumn('API Key',  true, true,  true,  '',    '', true, 'left', true)
           ->addColumn(
               'Status',
               true,
               true,
               true,
               'html',
               $statusFilter->render(),
               true,
               'left',
               'dt-applications-status',
               (string) \Pramnos\Http\Request::staticGet('status_filter', '', 'get')
           )
           ->addColumn('Added',    true, true,  false)
           ->addColumn('Actions',  true, false, false, 'html');

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
            $id      = (int) $row[0];
            $status  = (int) $row[3];
            $added   = (int) $row[4];
            $viewUrl = adminUrl('applications/view/') . $id;

            // The id and the name open the record: a row whose only way in is the last
            // cell makes the whole row a target people click with nothing happening.
            $row[0] = '<a href="' . $viewUrl . '">' . $id . '</a>';
            $row[1] = '<a href="' . $viewUrl . '">'
                . htmlspecialchars((string) $row[1], ENT_QUOTES, 'UTF-8') . '</a>';
            $row[2] = '<code>' . htmlspecialchars((string) $row[2], ENT_QUOTES, 'UTF-8') . '</code>';
            $row[3] = $status === 1
                ? '<span class="pf-state pf-state-on">Active</span>'
                : '<span class="pf-state pf-state-off">Inactive</span>';
            $row[4] = $added > 0 ? date('Y-m-d', $added) : '';
            $row[]  = Icon::link($viewUrl, 'view', 'View this application')
                    . Icon::postButton(adminUrl('applications/edit/') . $id, 'edit', 'Edit this application')
                    . Icon::postButton(
                        adminUrl('applications/delete/') . $id,
                        'delete',
                        'Delete this application',
                        ['data-confirm' => 'Delete this application?', 'class' => 'pf-action-danger']
                    );
            unset($row['DT_RowId']);
        }
        unset($row);

        echo json_encode($result);
        $this->terminate();
    }

    /** The capabilities reader (seam so tests can inject a double). */
    protected function capabilitiesReader(): \Pramnos\Auth\CapabilitiesSyncService
    {
        return new \Pramnos\Auth\CapabilitiesSyncService(
            \Pramnos\Framework\Factory::getDatabase()
        );
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

        $id  = (int) \Pramnos\Http\Request::staticGetOption();
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit Application' : 'New Application';

        $view              = $this->getView('applications');
        $view->application = null;
        // From the flash, not the query string — nothing emits those parameters any more.
        //
        // Read from the request rather than from `$view->messages`: a view here may be a test
        // double or an application's own class, and the first version of this assumed the
        // framework's `View` and its new properties, which cost three `implode(): null given`
        // errors. Non-destructive, so a theme header can still print them.
        $request           = \Pramnos\Http\Request::getInstance();
        $view->message     = implode(' ', $request->messages());
        $view->error       = implode(' ', $request->flashErrors());

        if ($id > 0) {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()
                ->table('#PREFIX#applications')
                ->where('appid', $id)
                ->first();

            if (!$result || $result->numRows === 0) {
                $this->addError('That record no longer exists.');
                $this->redirect(adminUrl('applications'));
                return null;
            }

            $view->application = $result->fields;
            // What it may do and how much — the "Access & limits" tab.
            $view->policy      = $this->applicationService()->policy($id);
        }
        $view->grantTypes  = \Pramnos\Auth\OAuthPolicyHelper::getGrantTypes();
        $view->authMethods = \Pramnos\Auth\OAuthPolicyHelper::getAuthenticationMethods();

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

        $id      = (int) ($_POST['appid'] ?? 0);
        $service = $this->applicationService();

        // The rules — callback normalisation, refused schemes, the column's width — are the
        // service's, so an application's own screen calling it gets the same answers as this one.
        try {
            if ($id > 0) {
                if (!$service->update($id, $_POST, $this->callbackCeiling())) {
                    $this->addError('That record no longer exists.');
                    $this->redirect(adminUrl('applications'));
                    return;
                }
                // Only when the form carried the tab: an application's older copy of the form
                // must not reset a policy it cannot see.
                if (isset($_POST['policy_submitted'])) {
                    $refused = $service->updatePolicy($id, $_POST);
                    if ($refused !== []) {
                        // Not escaped here: the form escapes the flash when it prints it.
                        $this->addError('The application was saved; its access and limits were not. ' . implode(' ', $refused));
                        $this->redirect(adminUrl('applications/edit/') . $id);
                        return;
                    }
                }
                $this->addMessage('Saved.');
            } else {
                $created = $service->create($_POST, $this->callbackCeiling());
                $this->addMessage(
                    'Saved. The client secret is ' . $created['secret'] . ' — copy it now, '
                    . 'it is stored hashed and cannot be shown again.'
                );
            }
        } catch (\InvalidArgumentException $refused) {
            $this->addError(htmlspecialchars($refused->getMessage(), ENT_QUOTES));
            $this->redirect(adminUrl('applications/edit/') . $id);
            return;
        }

        $this->redirect(adminUrl('applications'));
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

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('applications'));
            return;
        }

        if (!$this->applicationService()->deactivate($id)) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('applications'));
            return;
        }

        $this->addMessage('Deleted.');
        $this->redirect(adminUrl('applications'));
    }

    /**
     * List active tokens issued to a specific application.
     */
    public function tokens(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $appId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($appId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('applications'));
            return null;
        }

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $app = $db->queryBuilder()
            ->table('#PREFIX#applications')
            ->select(['appid', 'name', 'apikey'])
            ->where('appid', $appId)
            ->first();

        if (!$app || $app->numRows === 0) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('applications'));
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Tokens — ' . htmlspecialchars((string) ($app->fields['name'] ?? ''), ENT_QUOTES);

        $tokens = $this->applicationService()->tokens($appId);

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

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('applications'));
            return;
        }

        $newSecret = $this->applicationService()->rotateSecret($id);
        if ($newSecret === null) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('applications'));
            return;
        }

        // Shown once, here. The column holds a hash from now on, so this message is
        // the only place the value exists in readable form — rotating without
        // copying it means rotating again.
        $this->addMessage(
            'The new client secret is ' . $newSecret . ' — copy it now, it is stored '
            . 'hashed and cannot be shown again.'
        );
        $this->redirect(adminUrl('applications/edit/') . $id);
    }

    /**
     * Add or replace an endpoint for one event type — POST from the application's page.
     *
     * Approved as it is entered: an administrator's address is recorded as
     * `registered_by = admin` and delivered to as written, so a receiver on the VPN, the
     * LAN or this host works without a setting. The scheme follows
     * {@see \Pramnos\Auth\WebhookService::requiresHttps()}, the same rule an application's
     * own registration meets: `https` in production unless configured otherwise.
     *
     * The signing secret is shown once, in the message, and stored encrypted.
     */
    public function webhook(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $appId = (int) ($_POST['appid'] ?? 0);
        $back  = adminUrl('applications/view/') . $appId;

        if (!$this->validWebhookPost()) {
            $this->redirect($back);
            return;
        }

        $url  = trim((string) ($_POST['endpoint_url'] ?? ''));
        $type = trim((string) ($_POST['webhook_type'] ?? ''));

        $https  = \Pramnos\Auth\WebhookService::requiresHttps();
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
            || ($scheme !== 'https' && ($scheme !== 'http' || $https))
        ) {
            $this->addError($https
                ? 'The endpoint must be a full https:// URL.'
                : 'The endpoint must be a full http:// or https:// URL.');
            $this->redirect($back);
            return;
        }

        if (!\Pramnos\Auth\WebhookEvents::isKnown($type)) {
            $this->addError('Choose one of the listed event types.');
            $this->redirect($back);
            return;
        }

        if ($appId <= 0 || !$this->applicationExists($appId)) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('applications'));
            return;
        }

        $secret = bin2hex(random_bytes(32));
        $this->webhookService()->saveEndpoint(
            $appId,
            $url,
            $type,
            $secret,
            \Pramnos\Auth\WebhookService::REGISTERED_BY_ADMIN
        );

        $this->addMessage(
            'Saved. The signing secret for ' . $type . ' is ' . $secret . ' — give it to the '
            . 'receiving application now; it is stored encrypted and cannot be shown again.'
        );
        $this->redirect($back);
    }

    /**
     * A new signing secret for one endpoint — POST from the application's page.
     *
     * The old secret stops working at once, so the message says to hand the new one over.
     */
    public function webhookrotate(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $appId     = (int) ($_POST['appid'] ?? 0);
        $webhookId = (int) \Pramnos\Http\Request::staticGetOption();
        $back      = adminUrl('applications/view/') . $appId;

        if (!$this->validWebhookPost()) {
            $this->redirect($back);
            return;
        }

        $secret = $this->webhookService()->rotateEndpointSecret($appId, $webhookId);
        if ($secret === null) {
            $this->addError('That endpoint no longer exists.');
            $this->redirect($back);
            return;
        }

        $this->addMessage(
            'The new signing secret is ' . $secret . ' — the old one no longer verifies. '
            . 'Give it to the receiving application now; it cannot be shown again.'
        );
        $this->redirect($back);
    }

    /**
     * Remove one endpoint — POST from the application's page.
     */
    public function webhookdelete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $appId     = (int) ($_POST['appid'] ?? 0);
        $webhookId = (int) \Pramnos\Http\Request::staticGetOption();
        $back      = adminUrl('applications/view/') . $appId;

        if (!$this->validWebhookPost()) {
            $this->redirect($back);
            return;
        }

        if (!$this->webhookService()->deleteEndpoint($appId, $webhookId)) {
            $this->addError('That endpoint no longer exists.');
            $this->redirect($back);
            return;
        }

        $this->addMessage('Endpoint removed.');
        $this->redirect($back);
    }

    /** The application rules (seam so tests can inject a double). {@see \Pramnos\Auth\ApplicationService} */
    protected function applicationService(): \Pramnos\Auth\ApplicationService
    {
        return new \Pramnos\Auth\ApplicationService(\Pramnos\Framework\Factory::getDatabase());
    }

    /** The webhook store (seam so tests can inject a double). */
    protected function webhookService(): \Pramnos\Auth\WebhookService
    {
        return new \Pramnos\Auth\WebhookService(\Pramnos\Framework\Factory::getDatabase());
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * A POST carrying this session's CSRF token, or false with the error already added.
     *
     * These three change where signed events go and what they are signed with, so a GET —
     * a link, a prefetch — must not do it, and neither may a form on another site.
     */
    private function validWebhookPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || !\Pramnos\Http\Session::getInstance()->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))
        ) {
            $this->addError('That form had expired. Please try again.');
            return false;
        }

        return true;
    }

    /** Whether an application with this id exists. */
    protected function applicationExists(int $appId): bool
    {
        return $this->applicationService()->exists($appId);
    }

    /**
     * How many characters the `callback` column can hold, or 0 when it is unbounded.
     *
     * Read from `information_schema` rather than assumed, because the answer differs by
     * installation and not by driver: every database the framework created has `text`
     * (unbounded, so 0), and one whose `applications` table predates the migration system
     * still has `varchar(255)` — `create_applications_table` was skipped by the cutoff, so
     * its `text` never applied.
     *
     * Best effort by design: a database that cannot answer gets no ceiling and the driver's
     * own refusal, which is the behaviour before this existed. Failing a save because the
     * catalogue was unreadable would be worse than the message it is trying to improve.
     *
     * @return int The limit, or 0 for none
     */
    protected function callbackCeiling(): int
    {
        return $this->applicationService()->callbackCeiling();
    }
}
