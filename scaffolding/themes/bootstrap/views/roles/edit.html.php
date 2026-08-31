<?php
/**
 * Role create/edit form (Bootstrap theme).
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
<div class="container py-4" style="max-width:640px">
    <?php $this->activeNav = 'roles_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo $isNew ? 'New Role' : 'Edit Role'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="post" action="<?php echo adminUrl('Roles/save'); ?>">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$isNew): ?>
                    <input type="hidden" name="roleid" value="<?php echo (int) $role->roleid; ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="role_name" class="form-control" required
                           value="<?php echo htmlspecialchars((string) ($role->role_name ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars((string) ($role->description ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Organisation</label>
                    <select name="organization_id" class="form-select">
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
                <div class="mb-3">
                    <label>
                        <input type="checkbox" name="is_active" value="1"<?php echo ((int) ($role->is_active ?? 1)) === 1 ? ' checked' : ''; ?>>
                        Active — an inactive role grants nothing to anyone who holds it
                    </label>
                </div>
                <?php endif; ?>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo adminUrl('Roles'); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
