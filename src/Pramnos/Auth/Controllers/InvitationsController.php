<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\AdminAccess;
use Pramnos\Auth\InvitationException;
use Pramnos\Auth\Invitations;

/**
 * The Invitations screen: invite an address, see who was invited, resend, withdraw.
 *
 * Opened by `admin.invitations`, or by usertype 98 under `admin_access = usertype` — inviting
 * somebody creates an account at the end of it, which is a superuser's decision unless it has
 * been granted.
 *
 * The link is shown once, right after it is made: only its hash is stored, and showing it lets
 * the operator hand it over another way when the mail is slow or filtered.
 */
class InvitationsController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.invitations';

    protected int $requiredUserType = 98;

    /** Where the link just made waits for the page that shows it. */
    private const FRESH_LINK_KEY = 'invitations_fresh_link';

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'invite', 'resend', 'revoke']);
        parent::__construct($application);
    }

    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Invitations';

        $fresh = $_SESSION[self::FRESH_LINK_KEY] ?? null;
        unset($_SESSION[self::FRESH_LINK_KEY]);

        $view                = $this->getView('invitations');
        $view->invitations   = $this->service()->all();
        $view->organizations = $this->organizations();
        $view->roles         = $this->mayGiveRoles() ? $this->roles() : [];
        $view->freshLink     = is_array($fresh) ? $fresh : null;
        $view->ttlHours      = (int) round(Invitations::ttlSeconds() / 3600);

        return $view->display('invitations');
    }

    /** POST: invite an address. */
    public function invite(): void
    {
        if ($this->requireMinUserType($this->requiredUserType) || !$this->validPost()) {
            return;
        }

        $roleId = (int) ($_POST['role_id'] ?? 0);
        if ($roleId > 0 && !$this->mayGiveRoles()) {
            $this->addError('You may not give roles, so the invitation cannot carry one.');
            $this->redirect(adminUrl('Invitations'));
            return;
        }

        $current = \Pramnos\User\User::getCurrentUser() ?: null;
        try {
            $made = $this->service()->invite((string) ($_POST['email'] ?? ''), [
                'invitedBy'      => $current !== null ? (int) $current->userid : null,
                'organizationId' => (int) ($_POST['organization_id'] ?? 0),
                'roleId'         => $roleId,
                'note'           => (string) ($_POST['note'] ?? ''),
            ]);
        } catch (InvitationException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('Invitations'));
            return;
        }

        $_SESSION[self::FRESH_LINK_KEY] = [
            'email' => \Pramnos\Auth\Invitations::normalizeEmail((string) ($_POST['email'] ?? '')),
            'link'  => $made['link'],
            'sent'  => $made['sent'],
        ];
        $this->addMessage($made['sent']
            ? 'Invitation sent.'
            : 'The invitation was made but the mail could not be queued — give the person the link below.');
        $this->redirect(adminUrl('Invitations'));
    }

    /** POST: mail a new link for a waiting (or expired) invitation. */
    public function resend(): void
    {
        if ($this->requireMinUserType($this->requiredUserType) || !$this->validPost()) {
            return;
        }

        $made = $this->service()->resend((int) ($_POST['invitation_id'] ?? 0));
        if ($made === null) {
            $this->addError('That invitation was already used or withdrawn.');
        } else {
            $_SESSION[self::FRESH_LINK_KEY] = ['email' => '', 'link' => $made['link'], 'sent' => $made['sent']];
            $this->addMessage('A new link was sent. The previous one no longer works.');
        }
        $this->redirect(adminUrl('Invitations'));
    }

    /** POST: withdraw an invitation that was not used. */
    public function revoke(): void
    {
        if ($this->requireMinUserType($this->requiredUserType) || !$this->validPost()) {
            return;
        }

        if ($this->service()->revoke((int) ($_POST['invitation_id'] ?? 0))) {
            $this->addMessage('Withdrawn. Its link no longer works.');
        } else {
            $this->addError('That invitation was already used or withdrawn.');
        }
        $this->redirect(adminUrl('Invitations'));
    }

    // ── Internal ────────────────────────────────────────────────────────────────

    protected function service(): Invitations
    {
        return new Invitations();
    }

    /** POST with a valid form token; otherwise says so and sends back. */
    private function validPost(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->redirect(adminUrl('Invitations'));
            return false;
        }
        if (!\Pramnos\Http\Session::getInstance()->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))) {
            $this->addError('That form had expired. Please try again.');
            $this->redirect(adminUrl('Invitations'));
            return false;
        }

        return true;
    }

    /** A role given by invitation is a role assignment: whoever may assign roles may. */
    protected function mayGiveRoles(): bool
    {
        $user = \Pramnos\User\User::getCurrentUser() ?: null;

        return AdminAccess::allows($user, 'admin.roles', 90);
    }

    /** @return array<int, string> organization_id => name */
    protected function organizations(): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->schema()->hasTable('organizations')) {
            return [];
        }

        $names = [];
        $result = $db->queryBuilder()->table('organizations')->select(['organization_id', 'name'])->orderBy('name')->get();
        while ($result && $result->fetch()) {
            $names[(int) $result->fields['organization_id']] = (string) $result->fields['name'];
        }

        return $names;
    }

    /** @return array<int, string> roleid => name, active roles */
    protected function roles(): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->schema()->hasTable('authserver.roles')) {
            return [];
        }

        $names = [];
        $result = $db->queryBuilder()->table('authserver.roles')->select(['roleid', 'role_name'])
            ->where('is_active', 1)->orderBy('role_name')->get();
        while ($result && $result->fetch()) {
            $names[(int) $result->fields['roleid']] = (string) $result->fields['role_name'];
        }

        return $names;
    }
}
