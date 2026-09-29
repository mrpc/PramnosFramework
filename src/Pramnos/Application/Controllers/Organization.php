<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\OrganizationAdmin;
use Pramnos\Http\Response;

/**
 * `/organization` — the organisations a person manages, outside the administration area.
 *
 * For somebody allowed `manage` on an organisation ({@see OrganizationAdmin}): its members, who
 * holds its roles, and its invitations, with the actions that change them. Screens for a person,
 * and the same addresses answer JSON for a script (`Accept: application/json`), so a
 * single-page application or another front end drives the same checks.
 *
 * | Address | Does |
 * | --- | --- |
 * | `GET organization` | the organisations you manage |
 * | `GET organization/view/{id}` | members, roles, invitations |
 * | `POST organization/add/{id}` | `email`, optional `roleid` — a member now, or an invitation |
 * | `POST organization/remove/{id}` | `userid` |
 * | `POST organization/giverole/{id}` / `takerole/{id}` | `userid`, `roleid` |
 * | `POST organization/withdraw/{id}` | `invitation` |
 *
 * Every write is a `POST` with the session's token — the form field, or `X-CSRF-Token`.
 */
class Organization extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view', 'add', 'remove', 'giverole', 'takerole', 'withdraw']);
        $this->addWriteAction(['add', 'remove', 'giverole', 'takerole', 'withdraw']);
        parent::__construct($application);
    }

    /** The organisations the signed-in person manages. */
    public function display(): mixed
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->refuse('Sign in first.', 401);
        }

        $managed = OrganizationAdmin::managedBy($user);
        if ($this->wantsJson()) {
            return Response::json(['organizations' => array_map(
                static fn (int $id, string $name): array => ['organization_id' => $id, 'name' => $name],
                array_keys($managed),
                array_values($managed)
            )]);
        }

        \Pramnos\Framework\Factory::getDocument()->title = t('Your organisations');
        $view = $this->getView('organization');
        $view->organizations = $managed;

        return $view->display();
    }

    /** One organisation: its members, roles and invitations. */
    public function view(mixed $id = null): mixed
    {
        $orgId = $this->organizationId();
        if (($refused = $this->refuseUnlessManaged($orgId)) !== null) {
            return $refused;
        }

        $admin = $this->admin();
        $data  = [
            'organization_id' => $orgId,
            'name'            => OrganizationAdmin::nameOf($orgId),
            'members'         => $admin->members($orgId),
            'roles'           => $admin->roles($orgId),
            'invitations'     => array_map(
                static fn (array $i): array => array_intersect_key($i, array_flip(['invitation_id', 'email', 'role_id', 'state', 'created_at', 'expires_at'])),
                $admin->invitations($orgId)
            ),
        ];
        if ($this->wantsJson()) {
            return Response::json($data);
        }

        \Pramnos\Framework\Factory::getDocument()->title = (string) $data['name'];
        $view = $this->getView('organization');
        foreach ($data as $key => $value) {
            $view->$key = $value;
        }

        return $view->display('view');
    }

    /** Add somebody by address: a member at once, or an invitation. */
    public function add(mixed $id = null): mixed
    {
        return $this->change(function (OrganizationAdmin $admin, int $orgId): string {
            $roleId = (int) ($_POST['roleid'] ?? 0);
            $done   = $admin->add($orgId, (string) ($_POST['email'] ?? ''), $roleId > 0 ? $roleId : null);

            return $done === 'added' ? 'Added to the organisation.' : 'No account has that address; an invitation was sent.';
        });
    }

    /** Take a member out. */
    public function remove(mixed $id = null): mixed
    {
        return $this->change(function (OrganizationAdmin $admin, int $orgId): string {
            if (!$admin->remove($orgId, (int) ($_POST['userid'] ?? 0))) {
                throw new \InvalidArgumentException('That person is not a member.');
            }

            return 'Removed from the organisation.';
        });
    }

    /** Give a member one of the organisation's roles. */
    public function giverole(mixed $id = null): mixed
    {
        return $this->change(function (OrganizationAdmin $admin, int $orgId): string {
            $admin->giveRole($orgId, (int) ($_POST['roleid'] ?? 0), (int) ($_POST['userid'] ?? 0));

            return 'Role given.';
        });
    }

    /** Take one of the organisation's roles from a member. */
    public function takerole(mixed $id = null): mixed
    {
        return $this->change(function (OrganizationAdmin $admin, int $orgId): string {
            $admin->takeRole($orgId, (int) ($_POST['roleid'] ?? 0), (int) ($_POST['userid'] ?? 0));

            return 'Role taken away.';
        });
    }

    /** Withdraw a waiting invitation. */
    public function withdraw(mixed $id = null): mixed
    {
        return $this->change(function (OrganizationAdmin $admin, int $orgId): string {
            if (!$admin->withdraw($orgId, (int) ($_POST['invitation'] ?? 0))) {
                throw new \InvalidArgumentException('That invitation is not waiting.');
            }

            return 'Invitation withdrawn.';
        });
    }

    // ── Seams and helpers ─────────────────────────────────────────────────────

    /** The service, for the signed-in person. A seam. */
    protected function admin(): OrganizationAdmin
    {
        return new OrganizationAdmin((int) ($this->currentUser()->userid ?? 0));
    }

    /** The signed-in person, or null. A seam. */
    protected function currentUser(): ?object
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) && (int) ($user->userid ?? 0) >= 2 ? $user : null;
    }

    /** Whether the caller wants JSON: `Accept: application/json` or a script's `X-Requested-With`. */
    protected function wantsJson(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** Run one write, and answer it: JSON for a script, a message and the organisation's page otherwise. */
    private function change(callable $work): mixed
    {
        $orgId = $this->organizationId();
        if (($refused = $this->refuseUnlessManaged($orgId)) !== null) {
            return $refused;
        }

        try {
            $message = (string) $work($this->admin(), $orgId);
        } catch (\InvalidArgumentException | \Pramnos\Auth\InvitationException $e) {
            return $this->refuse($e->getMessage(), 422, $orgId);
        }

        if ($this->wantsJson()) {
            return Response::json(['ok' => true, 'message' => $message]);
        }
        // Translated for the page; a script gets the sentence as written, to match on.
        $this->addMessage(t($message));
        $this->redirect(sURL . 'organization/view/' . $orgId);

        return null;
    }

    private function organizationId(): int
    {
        return (int) \Pramnos\Http\Request::staticGetOption();
    }

    private function refuseUnlessManaged(int $orgId): mixed
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->refuse('Sign in first.', 401);
        }
        if (!OrganizationAdmin::manages($user, $orgId)) {
            return $this->refuse('You do not manage that organisation.', 403);
        }

        return null;
    }

    /**
     * Answer a refusal. Never null: callers test for a refusal with `!== null`, and a page that
     * was refused must not go on to render what it refused.
     */
    private function refuse(string $message, int $status, int $orgId = 0): mixed
    {
        if ($this->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $message], $status);
        }
        $this->addError(t($message));
        $this->redirect(sURL . ($orgId > 0 && $status === 422 ? 'organization/view/' . $orgId : 'organization'));

        return '';
    }
}
