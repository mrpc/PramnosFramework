<?php
/**
 * Role create/edit form (plain CSS theme).
 *
 * Variables:
 *   $this->role          — \Pramnos\Auth\Role (null when creating)
 *   $this->organizations — [organization_id => name] for the owner select
 *
 * The organisation select is the field that matters: leaving it on "System-wide"
 * makes a role valid everywhere, and choosing an organisation restricts both who may
 * hold it and where it counts.
 */
$role  = $this->role ?? null;
$isNew = $role === null || (int) $role->roleid === 0;
$orgId = (int) ($role->organization_id ?? 0);
?>
<div class="page-section" style="max-width:640px">
    <?php $this->activeNav = 'roles_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 style="margin-bottom:16px"><?php echo $isNew ? 'New Role' : 'Edit Role'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo adminUrl('Roles/save'); ?>">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$isNew): ?>
                    <input type="hidden" name="roleid" value="<?php echo (int) $role->roleid; ?>">
                <?php endif; ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Name</label>
                    <input type="text" name="role_name" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required
                           value="<?php echo htmlspecialchars((string) ($role->role_name ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Description</label>
                    <textarea name="description" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" rows="3"><?php echo htmlspecialchars((string) ($role->description ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Organisation</label>
                    <select name="organization_id" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                        <option value="0"<?php echo $orgId === 0 ? ' selected' : ''; ?>>System-wide — valid everywhere</option>
                        <?php foreach (($this->organizations ?? []) as $id => $name): ?>
                            <option value="<?php echo (int) $id; ?>"<?php echo $orgId === (int) $id ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$isNew): ?>
                        <small>An organisation cannot be changed once the role is held by
                        anyone — the holders who are not members of the new organisation
                        would silently lose it.</small>
                    <?php endif; ?>
                </div>
                <?php if (!$isNew): ?>
                <div style="margin-bottom:12px">
                    <label>
                        <input type="checkbox" name="is_active" value="1"<?php echo ((int) ($role->is_active ?? 1)) === 1 ? ' checked' : ''; ?>>
                        Active — an inactive role grants nothing to anyone who holds it
                    </label>
                </div>
                <?php endif; ?>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo adminUrl('Roles'); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
