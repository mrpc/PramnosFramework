<?php
/**
 * "Administration screens" — which screens a user or a role is granted.
 *
 * Inserted by the user's and the role's page. Data: `$this->adminScreens` from
 * `AdminScreenGrants::adminScreensFor()` — permissionsMode, canEdit, subject, action, rows of
 * [ability, label, granted, effective (a user's page only), grantable].
 */
$_as = $this->adminScreens ?? null;
if (!is_array($_as) || empty($_as['rows'])) {
    return;
}
$_e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$_forUser = ($_as['subject'] ?? '') === 'user';
?>
<div class="card bg-base-100 border border-base-300 shadow-xs mb-4" id="admin-screens">
    <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Administration screens</div>
    <div class="p-5">
        <?php if (empty($_as['permissionsMode'])): ?>
            <div role="status" class="alert alert-info text-sm mb-4">
                Screens are opened by usertype on this installation (<code>admin_access = usertype</code>), so
                these grants take effect only once it is set to <code>permissions</code>.
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo $_e($_as['action']); ?>">
            <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr>
                        <th>Screen</th>
                        <th><?php echo $_forUser ? 'Granted directly' : 'Granted'; ?></th>
                        <?php if ($_forUser): ?><th>Can open</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($_as['rows'] as $_row): ?>
                    <tr>
                        <td><?php echo $_e($_row['label']); ?> <code class="text-xs text-base-content/60"><?php echo $_e($_row['ability']); ?></code></td>
                        <td>
                            <input type="checkbox" class="checkbox checkbox-sm" name="abilities[]"
                                   value="<?php echo $_e($_row['ability']); ?>"
                                   aria-label="<?php echo $_e($_row['label']); ?>"
                                   <?php echo !empty($_row['granted']) ? 'checked' : ''; ?>
                                   <?php echo (empty($_as['canEdit']) || empty($_row['grantable'])) ? 'disabled' : ''; ?>>
                        </td>
                        <?php if ($_forUser): ?>
                            <td><?php echo !empty($_row['effective'])
                                ? '<span class="badge badge-success badge-sm">yes</span>'
                                : '<span class="badge badge-ghost badge-sm">no</span>'; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($_forUser): ?>
                <p class="text-xs text-base-content/60 mt-2">"Can open" also counts the user's roles and the superuser usertype.</p>
            <?php endif; ?>
            <?php if (!empty($_as['canEdit'])): ?>
                <button type="submit" class="btn btn-primary btn-sm mt-3">Save</button>
            <?php endif; ?>
        </form>
    </div>
</div>
