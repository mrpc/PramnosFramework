<?php
/**
 * Organization members (plain-CSS theme).
 *
 * Variables:
 *   $this->org     — organization row array
 *   $this->members — iterable user rows
 */
?>
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="<?php echo sURL; ?>Organizations" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 >Members — <?php echo htmlspecialchars($this->org['name'] ?? ''); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Add Member</div>
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo sURL; ?>Organizations/addmember/<?php echo (int)($this->org['id'] ?? 0); ?>" style="display:flex;gap:8px">
                <input type="number" name="userid" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success">Add</button>
            </form>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>User ID</th><th>Username</th><th>Email</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->members ?? []) as $m): ?>
                    <tr>
                        <td><?php echo (int)$m['userid']; ?></td>
                        <td><?php echo htmlspecialchars($m['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($m['email'] ?? ''); ?></td>
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Organizations/removemember/<?php echo (int)($this->org['id'] ?? 0); ?>/<?php echo (int)$m['userid']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove member?')">Remove</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->members)): ?>
                    <tr><td colspan="4" style="text-align:center;color:#888;padding:24px">No members.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
