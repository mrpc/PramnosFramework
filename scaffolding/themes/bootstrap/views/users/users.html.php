<?php
/**
 * Users list (Bootstrap theme).
 *
 * Variables:
 *   $this->users   — iterable rows (userid, username, email, active, regdate)
 *   $this->page    — current page (1-based)
 *   $this->total   — total row count
 *   $this->success — optional success flash message
 *   $this->error   — optional error flash message
 */
?>
<div class="container-fluid py-4">
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Users</h2>
        <a href="<?php echo sURL; ?>Users/edit" class="btn btn-primary">+ New User</a>
    </div>
    <?php
    $_doc = \Pramnos\Framework\Factory::getDocument();
    $_hasDt = $_doc->isScriptRegistered('datatables');
    if ($_hasDt) { $_doc->enqueueScript('datatables'); if ($_doc->isStyleRegistered('datatables')) { $_doc->enqueueStyle('datatables'); } }
    ?>
    <div class="card">
        <div class="card-body p-0">
            <table id="dt-users" class="table table-hover mb-0">
                <thead class="table-light">
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
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Users/edit/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Users/sessions/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-info">Sessions</a>
                            <a href="<?php echo sURL; ?>Users/resetpassword/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-warning" data-confirm="Send password reset email to this user?">Reset Password</a>
                            <a href="<?php echo sURL; ?>Users/delete/<?php echo (int)$u['userid']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Deactivate this user?">Deactivate</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->users)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!($_hasDt ?? false) && ($this->total ?? 0) > 50): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted">Showing page <?php echo (int)($this->page ?? 1); ?> of <?php echo ceil(($this->total ?? 0) / 50); ?></small>
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
<?php if ($_hasDt ?? false): ?>
<script>$(document).ready(function(){ $('#dt-users').DataTable({pageLength:25,order:[]}); });</script>
<?php endif; ?>
