<?php
/**
 * User create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->user   — user row array (null when creating)
 *   $this->isNew  — bool
 *   $this->error  — string error message
 */
$u = $this->user ?? [];
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <?php $this->activeNav = 'users_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-6"><?php echo $this->isNew ? 'New User' : 'Edit User'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
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
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">User Type</label>
                    <select name="usertype" class="input input-sm w-full">
                        <?php $maxType = $this->currentUserType ?? 100; $curType = $u['usertype'] ?? 1; ?>
                        <option value="1" <?php echo $curType == 1 ? 'selected' : ''; ?>>User (1)</option>
                        <?php if ($maxType >= 50): ?><option value="50" <?php echo $curType == 50 ? 'selected' : ''; ?>>Editor (50)</option><?php endif; ?>
                        <?php if ($maxType >= 80): ?><option value="80" <?php echo $curType == 80 ? 'selected' : ''; ?>>Manager (80)</option><?php endif; ?>
                        <?php if ($maxType >= 90): ?><option value="90" <?php echo $curType == 90 ? 'selected' : ''; ?>>Admin (90)</option><?php endif; ?>
                        <?php if ($maxType >= 100): ?><option value="100" <?php echo $curType == 100 ? 'selected' : ''; ?>>Super Admin (100)</option><?php endif; ?>
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
</div>
