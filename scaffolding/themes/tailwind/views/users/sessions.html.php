<?php
/**
 * User sessions list (Tailwind theme).
 *
 * Variables:
 *   $this->user         — user row array (empty when this is the whole site's list)
 *   $this->sessionList  — iterable session rows
 *   $this->scopedToUser — bool: one account, or everybody
 *   $this->activeWindow — seconds a session counts as "active" for, matching the dashboard
 */
$scoped = (bool) ($this->scopedToUser ?? true);
$window = (int) ($this->activeWindow ?? 300);
$fresh  = time() - $window;
$rows   = $this->sessionList ?? [];
$live   = 0;

foreach ($rows as $row) {
    if ((int) ($row['logout'] ?? 0) === 0 && (int) ($row['time'] ?? 0) >= $fresh) {
        $live++;
    }
}
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'users_sessions'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <?php if ($scoped): ?>
        <h2>Sessions — <?php echo htmlspecialchars($this->user['username'] ?? ''); ?></h2>
        <?php else: ?>
        <h2>Active sessions</h2>
        <span class="badge badge-primary badge-sm"><?php echo $live; ?> in the last
            <?php echo (int) round($window / 60); ?> min</span>
        <span class="text-xs text-base-content/60">
            <?php /* The same window the dashboard's "Active users (now)" figure counts, so
                     the number somebody clicked and the list they land on agree. */ ?>
            Rows older than that are kept until the session table is swept.
        </span>
        <?php endif; ?>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr>
                        <?php if (!$scoped): ?><th>Who</th><?php endif; ?>
                        <th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Last Active</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (($this->sessionList ?? []) as $s): ?>
                    <?php
                    $active = (int) ($s['logout'] ?? 0) === 0 && (int) ($s['time'] ?? 0) >= $fresh;
                    $rowUserId = (int) ($s['userid'] ?? 0);
                    ?>
                    <tr>
                        <?php if (!$scoped): ?>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <?php if ($rowUserId > 0): ?>
                                <a class="link" href="<?php echo adminUrl('Users/view/') . $rowUserId; ?>">
                                    <?php echo htmlspecialchars(($s['uname'] ?? '') !== '' ? $s['uname'] : ('#' . $rowUserId)); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-base-content/60">guest</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-base-content/70"><?php echo htmlspecialchars(substr((string) ($s['visitorid'] ?? ''), 0, 16)) . '…'; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-base-content"><?php echo htmlspecialchars($s['host_addr'] ?? ''); ?></td>
                        <td class="px-6 py-4 text-sm text-base-content/70"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? ''), 0, 60)); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-base-content/70"><?php echo isset($s['time']) ? htmlspecialchars(localDateTime( (int) $s['time'])) : ''; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $active ? 'bg-success/10 text-success' : 'bg-base-200 text-base-content/80'; ?>"><?php echo $active ? 'Active' : 'Logged out'; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?php echo $scoped ? 5 : 6; ?>" class="text-center text-base-content/60 py-8">No sessions recorded.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
