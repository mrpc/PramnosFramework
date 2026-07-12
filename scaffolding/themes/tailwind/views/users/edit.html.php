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
    <h2 class="mb-6"><?php echo $this->isNew ? 'New User' : 'Edit User'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-5">
            <form method="post" action="<?php echo sURL; ?>Users/save">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$this->isNew): ?>
                    <input type="hidden" name="userid" value="<?php echo (int)($u['userid'] ?? 0); ?>">
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" required value="<?php echo htmlspecialchars($u['username'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" required value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>">
                </div>
                <?php if ($this->isNew): ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" required>
                </div>
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                    <input type="text" name="firstname" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars($u['firstname'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                    <input type="text" name="lastname" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars($u['lastname'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">User Type</label>
                    <select name="usertype" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                        <?php $maxType = $this->currentUserType ?? 100; $curType = $u['usertype'] ?? 1; ?>
                        <option value="1" <?php echo $curType == 1 ? 'selected' : ''; ?>>User (1)</option>
                        <?php if ($maxType >= 50): ?><option value="50" <?php echo $curType == 50 ? 'selected' : ''; ?>>Editor (50)</option><?php endif; ?>
                        <?php if ($maxType >= 80): ?><option value="80" <?php echo $curType == 80 ? 'selected' : ''; ?>>Manager (80)</option><?php endif; ?>
                        <?php if ($maxType >= 90): ?><option value="90" <?php echo $curType == 90 ? 'selected' : ''; ?>>Admin (90)</option><?php endif; ?>
                        <?php if ($maxType >= 100): ?><option value="100" <?php echo $curType == 100 ? 'selected' : ''; ?>>Super Admin (100)</option><?php endif; ?>
                    </select>
                </div>
                <div class="mb-4 flex gap-6">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="active" value="1" <?php echo ($u['active'] ?? 1) ? 'checked' : ''; ?>>
                        Active
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="validated" value="1" <?php echo ($u['validated'] ?? 1) ? 'checked' : ''; ?>>
                        Validated
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">Save</button>
                    <a href="<?php echo sURL; ?>Users" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
