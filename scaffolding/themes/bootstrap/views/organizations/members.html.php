<?php
/**
 * Organization members (Bootstrap theme).
 *
 * Variables:
 *   $this->org     — organization row array
 *   $this->members — iterable user rows
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?php echo sURL; ?>Organizations" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Members — <?php echo htmlspecialchars($this->org['name'] ?? ''); ?></h2>
    </div>
    <div class="card mb-3">
        <div class="card-header fw-semibold">Add Member</div>
        <div class="card-body">
            <form method="post" action="<?php echo sURL; ?>Organizations/addmember/<?php echo (int)($this->org['id'] ?? 0); ?>" class="d-flex gap-2">
                <input type="number" name="userid" class="form-control" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success">Add</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>User ID</th><th>Username</th><th>Email</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->members ?? []) as $m): ?>
                    <tr>
                        <td><?php echo (int)$m['userid']; ?></td>
                        <td><?php echo htmlspecialchars($m['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($m['email'] ?? ''); ?></td>
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Organizations/removemember/<?php echo (int)($this->org['id'] ?? 0); ?>/<?php echo (int)$m['userid']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Remove member?">Remove</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->members)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No members.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
