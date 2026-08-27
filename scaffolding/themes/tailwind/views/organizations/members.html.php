<?php
/**
 * Organization members (Tailwind theme).
 *
 * Variables:
 *   $this->org     — organization row array
 *   $this->members — iterable user rows
 */
?>
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('Organizations'); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 >Members — <?php echo htmlspecialchars($this->org['name'] ?? ''); ?></h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Add Member</div>
        <div class="p-5">
            <form method="post" action="<?php echo adminUrl('Organizations/addmember/'); ?><?php echo (int)($this->org['organization_id'] ?? 0); ?>" class="flex gap-2">
                <input type="number" name="userid" class="input input-sm w-full" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success btn-sm">Add</button>
            </form>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>User ID</th><th>Username</th><th>Email</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->members ?? []) as $m): ?>
                    <tr>
                        <td><?php echo (int)$m['userid']; ?></td>
                        <td><?php echo htmlspecialchars($m['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($m['email'] ?? ''); ?></td>
                        <td class="text-right">
                            <a href="<?php echo adminUrl('Organizations/removemember/'); ?><?php echo (int)($this->org['organization_id'] ?? 0); ?>?userid=<?php echo (int)$m['userid']; ?>" class="btn btn-outline btn-error btn-xs" data-confirm="Remove member?">Remove</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->members)): ?>
                    <tr><td colspan="4" class="text-center text-base-content/60 py-8">No members.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
