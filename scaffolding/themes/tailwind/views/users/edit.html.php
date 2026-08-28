<?php
/**
 * User create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->user        — user row array (null when creating)
 *   $this->isNew       — bool
 *   $this->error       — string error message
 *   $this->success     — string confirmation
 *   $this->settings    — per-user settings, from User::listSettings()
 *   $this->permissions — permission rows granted directly to this user
 *   $this->usertypes   — the bands, from UserTypes::labels()
 *
 * Three forms, not one. The account's own fields post to `Users/save`; a setting posts to
 * `users/savesetting/{id}`; a permission to `users/grantpermission/{id}`. They are separate
 * because they are separate decisions: saving a name should not require re-submitting a
 * permission, and a failed permission should not lose a typed name.
 */
$u = $this->user ?? [];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="max-w-4xl mx-auto py-6 px-4">
    <?php $this->activeNav = 'users_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-6"><?php echo $this->isNew ? 'New User' : 'Edit User'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-error mb-4"><?php echo $e($this->error); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success mb-4"><?php echo $e($this->success); ?></div>
    <?php endif; ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="p-5">
            <form method="post" action="<?php echo adminUrl('Users/save'); ?>">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$this->isNew): ?>
                    <input type="hidden" name="userid" value="<?php echo (int)($u['userid'] ?? 0); ?>">
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Username</label>
                    <input type="text" name="username" class="input input-sm w-full" required value="<?php echo htmlspecialchars($u['username'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Email</label>
                    <input type="email" name="email" class="input input-sm w-full" required value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>">
                </div>
                <?php if ($this->isNew): ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Password</label>
                    <input type="password" name="password" class="input input-sm w-full" required>
                </div>
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">First Name</label>
                    <input type="text" name="firstname" class="input input-sm w-full" value="<?php echo htmlspecialchars($u['firstname'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Last Name</label>
                    <input type="text" name="lastname" class="input input-sm w-full" value="<?php echo htmlspecialchars($u['lastname'] ?? ''); ?>">
                </div>
                <div class="grid gap-4 md:grid-cols-2 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-base-content mb-1">Phone</label>
                        <input type="text" name="phone" class="input input-sm w-full" value="<?php echo $e($u['phone'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-base-content mb-1">Mobile</label>
                        <input type="text" name="mobile" class="input input-sm w-full" value="<?php echo $e($u['mobile'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-base-content mb-1">Language</label>
                        <input type="text" name="language" class="input input-sm w-full" placeholder="en"
                               value="<?php echo $e($u['language'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-base-content mb-1">Timezone</label>
                        <input type="text" name="timezone" class="input input-sm w-full" placeholder="Europe/Athens"
                               value="<?php echo $e($u['timezone'] ?? ''); ?>">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">User Type</label>
                    <select name="usertype" class="input input-sm w-full">
                        <?php $maxType = $this->currentUserType ?? 100; $curType = $u['usertype'] ?? 1; ?>
                        <?php
                        /**
                         * The bands this application declares, not a hardcoded five.
                         *
                         * `UserTypes::labels()` is where they live, so an application that
                         * renamed them in `app.php` sees its own names here — and this
                         * select cannot disagree with the badge on the user's own screen.
                         *
                         * Capped at the operator's own type: nobody may create an account
                         * more privileged than their own, which `save()` enforces again.
                         */
                        $bands = is_array($this->usertypes ?? null) && $this->usertypes !== []
                            ? $this->usertypes
                            : \Pramnos\User\UserTypes::labels();
                        foreach ($bands as $floor => $label) {
                            if ((int) $floor > $maxType) {
                                continue;
                            }
                            echo '<option value="' . (int) $floor . '"'
                                . ((int) $curType === (int) $floor ? ' selected' : '') . '>'
                                . $e($label) . ' (' . (int) $floor . ')</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="mb-4 flex gap-6">
                    <label class="flex items-center gap-2 text-sm text-base-content">
                        <input type="checkbox" name="active" value="1" <?php echo ($u['active'] ?? 1) ? 'checked' : ''; ?>>
                        Active
                    </label>
                    <label class="flex items-center gap-2 text-sm text-base-content">
                        <input type="checkbox" name="validated" value="1" <?php echo ($u['validated'] ?? 1) ? 'checked' : ''; ?>>
                        Validated
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <a href="<?php echo adminUrl('Users'); ?>" class="btn btn-outline btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$this->isNew): ?>
    <?php
    $uid      = (int) ($u['userid'] ?? 0);
    $settings = is_array($this->settings ?? null) ? $this->settings : [];
    $grants   = is_array($this->permissions ?? null) ? $this->permissions : [];
    ?>

    <!-- Per-user settings: the switches an application keeps about one account -->
    <div class="card bg-base-100 border border-base-300 shadow-xs mt-4">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 flex items-center gap-2">
            <span class="text-sm font-semibold">Settings</span>
            <span class="badge badge-neutral badge-sm"><?php echo count($settings); ?></span>
            <span class="ms-auto text-xs text-base-content/60">
                Values are stored as JSON — a list stays a list
            </span>
        </div>

        <?php if ($settings !== []): ?>
        <table class="table table-sm text-sm">
            <thead class="bg-base-100 text-xs uppercase text-base-content/60">
                <tr><th>Setting</th><th>Value</th><th>Changed</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($settings as $setting): ?>
                <?php
                $value = $setting['value'];
                $shown = is_array($value) || is_object($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) ($value ?? '');
                ?>
                <tr>
                    <td class="font-mono text-xs"><?php echo $e($setting['setting']); ?></td>
                    <td>
                        <?php if ($value === null): ?>
                        <span class="pf-muted">null</span>
                        <?php else: ?>
                        <code class="text-xs break-all"><?php echo $e($shown); ?></code>
                        <?php endif; ?>
                    </td>
                    <td class="text-xs text-base-content/60">
                        <?php echo $setting['updated_at'] ? $e(localDateTime( (int) $setting['updated_at'])) : '—'; ?>
                    </td>
                    <td class="text-end whitespace-nowrap">
                        <?php
                        /**
                         * Edit is a copy into the form below rather than a second form per
                         * row: one row's worth of inputs, reused, is less markup and — more
                         * to the point — the same code path saves an edit and a new setting,
                         * so they cannot behave differently.
                         */
                        ?>
                        <button type="button" class="btn btn-ghost btn-xs"
                                data-pf-fill-setting="<?php echo $e($setting['setting']); ?>"
                                data-pf-fill-value="<?php echo $e($shown); ?>">Edit</button>
                        <?php echo \Pramnos\Html\Icon::link(
                            adminUrl('users/deletesetting/' . $uid) . '?setting=' . urlencode((string) $setting['setting']),
                            'delete',
                            'Remove this setting',
                            [
                                'data-confirm' => 'Remove the setting "' . $setting['setting'] . '"?',
                                'class'        => 'pf-action-danger',
                            ]
                        ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="px-5 py-3 text-sm text-base-content/60">
            Nothing set on this account — the application's own defaults apply.
        </div>
        <?php endif; ?>

        <form method="post" action="<?php echo adminUrl('users/savesetting/' . $uid); ?>"
              class="px-5 py-4 border-t border-base-300 grid gap-2 md:grid-cols-[1fr_2fr_auto] md:items-end">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
            <div>
                <label class="block text-xs font-medium mb-1" for="pf-setting-name">Name</label>
                <input type="text" id="pf-setting-name" name="setting" class="input input-sm w-full"
                       placeholder="notifications.email" required>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="pf-setting-value">Value (text or JSON)</label>
                <input type="text" id="pf-setting-value" name="value" class="input input-sm w-full"
                       placeholder="true, 42, &quot;text&quot; or [1,2,3]">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save setting</button>
        </form>
    </div>

    <!-- Direct permission grants: what this account may do, beyond its usertype -->
    <div class="card bg-base-100 border border-base-300 shadow-xs mt-4">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 flex items-center gap-2">
            <span class="text-sm font-semibold">Permissions</span>
            <span class="badge badge-neutral badge-sm"><?php echo count($grants); ?></span>
            <span class="ms-auto text-xs text-base-content/60">
                Granted to this user directly — usertype and group grants are not listed
            </span>
        </div>

        <?php if ($grants !== []): ?>
        <table class="table table-sm text-sm">
            <thead class="bg-base-100 text-xs uppercase text-base-content/60">
                <tr><th>Object</th><th>Action</th><th>Grant</th><th>Priority</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($grants as $grant): ?>
                <tr>
                    <td class="font-mono text-xs">
                        <?php echo $e($grant['object_type'] ?? ''); ?>
                        <?php if (($grant['object_id'] ?? null) !== null && $grant['object_id'] !== ''): ?>
                        <span class="pf-muted">#<?php echo $e($grant['object_id']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $e($grant['action'] ?? ''); ?></td>
                    <td>
                        <span class="pf-state <?php echo ($grant['grant_type'] ?? 'allow') === 'deny' ? 'pf-state-off' : 'pf-state-on'; ?>">
                            <?php echo $e($grant['grant_type'] ?? 'allow'); ?>
                        </span>
                    </td>
                    <td class="text-xs"><?php echo (int) ($grant['priority'] ?? 0); ?></td>
                    <td class="text-end">
                        <?php echo \Pramnos\Html\Icon::link(
                            adminUrl('users/revokepermission/' . $uid) . '?permission=' . (int) ($grant['permissionid'] ?? 0),
                            'delete',
                            'Revoke this permission',
                            ['data-confirm' => 'Revoke this permission?', 'class' => 'pf-action-danger']
                        ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="px-5 py-3 text-sm text-base-content/60">
            No permission granted to this account directly. What it may do comes from its
            usertype and any groups it belongs to.
        </div>
        <?php endif; ?>

        <form method="post" action="<?php echo adminUrl('users/grantpermission/' . $uid); ?>"
              class="px-5 py-4 border-t border-base-300 grid gap-2 md:grid-cols-5 md:items-end">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
            <div>
                <label class="block text-xs font-medium mb-1">Object type</label>
                <input type="text" name="object_type" class="input input-sm w-full" placeholder="report" required>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Object id</label>
                <input type="text" name="object_id" class="input input-sm w-full" placeholder="optional">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Action</label>
                <input type="text" name="action" class="input input-sm w-full" placeholder="read" required>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Grant</label>
                <select name="grant_type" class="select select-sm w-full">
                    <option value="allow">allow</option>
                    <option value="deny">deny</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add permission</button>
        </form>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-outline btn-sm gap-2">
            <?php echo \Pramnos\Html\Icon::svg('view'); ?> Back to the record
        </a>
        <a href="<?php echo adminUrl('users/notify/' . $uid); ?>" class="btn btn-outline btn-sm gap-2">
            <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send a message
        </a>
    </div>
    <?php endif; ?>
</div>
