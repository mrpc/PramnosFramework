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
        <a href="<?php echo sURL; ?>Organizations" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">&larr; Back</a>
        <h2 >Members — <?php echo htmlspecialchars($this->org['name'] ?? ''); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-4">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Add Member</div>
        <div class="p-5">
            <form method="post" action="<?php echo sURL; ?>Organizations/addmember/<?php echo (int)($this->org['id'] ?? 0); ?>" class="flex gap-2">
                <input type="number" name="userid" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded hover:bg-green-700">Add</button>
            </form>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>User ID</th><th>Username</th><th>Email</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->members ?? []) as $m): ?>
                    <tr>
                        <td><?php echo (int)$m['userid']; ?></td>
                        <td><?php echo htmlspecialchars($m['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($m['email'] ?? ''); ?></td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Organizations/removemember/<?php echo (int)($this->org['id'] ?? 0); ?>/<?php echo (int)$m['userid']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" onclick="return confirm('Remove member?')">Remove</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->members)): ?>
                    <tr><td colspan="4" class="text-center text-gray-400 py-8">No members.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
