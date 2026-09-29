<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\AdminAccess;

/**
 * The "Administration screens" panel on a user's and on a role's page.
 *
 * One checkbox per screen, writing {@see AdminAccess::setGrants()}. Used by the Users and the
 * Roles screens, so a grant can be made to one person or to everybody holding a role, and both
 * pages read the same rows.
 *
 * Who may change it: the superuser, or somebody holding `admin.permissions`. And only for the
 * screens that editor may open themselves — a grant is the access, so handing out one you do not
 * hold would be the way round every other check.
 */
trait AdminScreenGrants
{
    /** 'user' or 'role'. */
    abstract protected function adminScreenSubject(): string;

    /** Where the panel lives, to come back to after saving. */
    abstract protected function adminScreenReturnUrl(int $subjectId): string;

    /**
     * The panel's data, for `$view->adminScreens`.
     *
     * @param object|null $holder For a user's page, the user — to show what they can open in
     *                            the end, through roles and usertype as well as direct grants
     * @return array<string, mixed>
     */
    protected function adminScreensFor(int $subjectId, ?object $holder = null): array
    {
        $editor    = \Pramnos\User\User::getCurrentUser() ?: null;
        $features  = (array) ($this->application->applicationInfo['features'] ?? []);
        $grantable = $this->adminScreensGrantableBy($editor, $features);
        $granted   = AdminAccess::grantsFor($this->adminScreenSubject(), $subjectId);
        $floors    = [];
        foreach (\Pramnos\Application\NavRegistry::all() as $item) {
            $floors[$item->id] = $item->minUserType;
        }

        $rows = [];
        foreach (AdminAccess::abilities($features) as $ability => $label) {
            $rows[] = [
                'ability'   => $ability,
                'label'     => $label,
                'granted'   => in_array($ability, $granted, true),
                'effective' => $holder === null
                    ? null
                    : AdminAccess::allows($holder, $ability, $floors[$ability] ?? 0),
                'grantable' => in_array($ability, $grantable, true),
            ];
        }

        return [
            'permissionsMode' => AdminAccess::usesPermissions(),
            'canEdit'         => $grantable !== [],
            'subject'         => $this->adminScreenSubject(),
            'action'          => adminUrl($this->adminScreenSubject() === 'user' ? 'users' : 'roles')
                . '/adminscreens/' . $subjectId,
            'rows'            => $rows,
        ];
    }

    /** Save the panel. POST only, CSRF-checked. */
    public function adminscreens(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $subjectId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($subjectId <= 0) {
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
        }
        $back = $this->adminScreenReturnUrl($subjectId);

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || $subjectId <= 0) {
            $this->redirect($back);
            return;
        }

        if (!\Pramnos\Http\Session::getInstance()->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))) {
            $this->addError('That form had expired. Please try again.');
            $this->redirect($back);
            return;
        }

        $editor    = \Pramnos\User\User::getCurrentUser() ?: null;
        $features  = (array) ($this->application->applicationInfo['features'] ?? []);
        $grantable = $this->adminScreensGrantableBy($editor, $features);
        if ($grantable === []) {
            $this->addError('You may not change which administration screens are granted.');
            $this->redirect($back);
            return;
        }

        $wanted = array_values(array_filter(
            array_map('strval', (array) ($_POST['abilities'] ?? [])),
            static fn (string $a): bool => $a !== ''
        ));

        $changed = AdminAccess::setGrants(
            $this->adminScreenSubject(),
            $subjectId,
            $wanted,
            $grantable,
            $editor !== null ? (int) $editor->userid : null
        );

        if ($changed['added'] !== [] || $changed['removed'] !== []) {
            \Pramnos\Auth\ActivityLog::record((int) ($editor->userid ?? 0), 'admin_screens_changed', [
                'subject_type' => $this->adminScreenSubject(),
                'subject_id'   => $subjectId,
                'added'        => $changed['added'],
                'removed'      => $changed['removed'],
            ]);
        }

        $this->addMessage('Saved.');
        $this->redirect($back);
    }

    /**
     * The screens this editor may grant or take away.
     *
     * @param list<string> $features
     * @return list<string>
     */
    protected function adminScreensGrantableBy(?object $editor, array $features): array
    {
        if ($editor === null || (int) ($editor->userid ?? 0) < 2) {
            return [];
        }

        $superuser = (int) ($editor->usertype ?? 0) >= AdminAccess::superuserUsertype();
        if (!$superuser && !AdminAccess::allows($editor, 'admin.permissions', 90)) {
            return [];
        }

        $floors = [];
        foreach (\Pramnos\Application\NavRegistry::all() as $item) {
            $floors[$item->id] = $item->minUserType;
        }

        $grantable = [];
        foreach (array_keys(AdminAccess::abilities($features)) as $ability) {
            if ($superuser || AdminAccess::allows($editor, $ability, $floors[$ability] ?? 0)) {
                $grantable[] = $ability;
            }
        }

        return $grantable;
    }
}
