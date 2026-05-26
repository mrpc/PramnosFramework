<?php
/**
 * Users list (plain-CSS theme).
 *
 * Variables:
 *   $this->users   — iterable rows (userid, username, email, active, regdate)
 *   $this->page    — current page (1-based)
 *   $this->total   — total row count
 *   $this->success — optional success flash message
 *   $this->error   — optional error flash message
 */
?>
<div class="page-section">
    <?php if (!empty($this->success)): ?>
        <div style="padding:10px 14px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;margin-bottom:12px;color:#155724"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div style="padding:10px 14px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin-bottom:12px;color:#721c24"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >Users</h2>
        <a href="<?php echo sURL; ?>Users/edit" class="btn btn-primary">+ New User</a>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
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
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Users/edit/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Users/sessions/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-info">Sessions</a>
                            <a href="<?php echo sURL; ?>Users/resetpassword/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-warning" onclick="return confirm('Send password reset email to this user?')">Reset Password</a>
                            <a href="<?php echo sURL; ?>Users/delete/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this user?')">Deactivate</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->users)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (($this->total ?? 0) > 50): ?>
        <div class="card-footer" style="padding:10px 16px;background:#f5f5f5;border-top:1px solid #ddd;display:flex;justify-content:space-between;align-items:center">
            <small style="color:#888">Showing page <?php echo (int)($this->page ?? 1); ?> of <?php echo ceil(($this->total ?? 0) / 50); ?></small>
            <div>
                <?php if (($this->page ?? 1) > 1): ?>
                    <a href="?page=<?php echo (int)($this->page - 1); ?>" class="btn btn-sm btn-outline-secondary">Previous</a>
                <?php endif; ?>
                <?php if (($this->page ?? 1) * 50 < ($this->total ?? 0)): ?>
                    <a href="?page=<?php echo (int)($this->page + 1); ?>" class="btn btn-sm btn-outline-secondary">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
