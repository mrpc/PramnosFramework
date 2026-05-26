<?php
/**
 * Users list (Tailwind theme).
 *
 * Variables:
 *   $this->users   — iterable rows (userid, username, email, active, regdate)
 *   $this->page    — current page (1-based)
 *   $this->total   — total row count
 *   $this->success — optional success flash message
 *   $this->error   — optional error flash message
 */
?>
<div class="px-4 py-6">
    <?php if (!empty($this->success)): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded text-green-800 text-sm"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded text-red-800 text-sm"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="flex justify-between items-center mb-4">
        <h2 >Users</h2>
        <a href="<?php echo sURL; ?>Users/edit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">+ New User</a>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th>ID</th><th>Username</th><th>Email</th><th>Status</th><th>Registered</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (($this->users ?? []) as $u): ?>
                    <tr>
                        <td><?php echo (int)$u['userid']; ?></td>
                        <td><?php echo htmlspecialchars($u['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                        <td>
                            <?php echo $u['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?>
                        </td>
                        <td><?php echo htmlspecialchars($u['regdate'] ?? ''); ?></td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Users/edit/<?php echo (int)$u['userid']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Edit</a>
                            <a href="<?php echo sURL; ?>Users/sessions/<?php echo (int)$u['userid']; ?>" class="px-3 py-1 border border-blue-300 text-blue-700 text-xs rounded hover:bg-blue-50">Sessions</a>
                            <a href="<?php echo sURL; ?>Users/resetpassword/<?php echo (int)$u['userid']; ?>" class="px-3 py-1 border border-yellow-300 text-yellow-700 text-xs rounded hover:bg-yellow-50" onclick="return confirm('Send password reset email to this user?')">Reset Password</a>
                            <a href="<?php echo sURL; ?>Users/delete/<?php echo (int)$u['userid']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" onclick="return confirm('Deactivate this user?')">Deactivate</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->users)): ?>
                    <tr><td colspan="6" class="text-center text-gray-400 py-8">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (($this->total ?? 0) > 50): ?>
        <div class="px-5 py-3 bg-gray-50 border-t border-gray-200 flex justify-between items-center">
            <small class="text-gray-500">Showing page <?php echo (int)($this->page ?? 1); ?> of <?php echo ceil(($this->total ?? 0) / 50); ?></small>
            <div>
                <?php if (($this->page ?? 1) > 1): ?>
                    <a href="?page=<?php echo (int)($this->page - 1); ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Previous</a>
                <?php endif; ?>
                <?php if (($this->page ?? 1) * 50 < ($this->total ?? 0)): ?>
                    <a href="?page=<?php echo (int)($this->page + 1); ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
