<?php
/**
 * One push notification in full (Tailwind / daisyUI theme).
 *
 * Variables:
 *   $this->row          — the push log row
 *   $this->outcome      — Log::outcome(): kind, label, explanation
 *   $this->subscription — Subscriptions::describe() of the device it went to, or null
 *   $this->history      — the latest attempts to that same device, newest first
 *
 * The list says what happened; this page answers "why did this person not see it". The
 * device's own history is the evidence: deliveries to a browser the person says shows nothing
 * point at the device, a run of failures at the subscription.
 *
 * The endpoint is not shown because it is not stored: whoever holds it can push to that browser.
 */
$row     = is_array($this->row ?? null) ? $this->row : [];
$outcome = is_array($this->outcome ?? null) ? $this->outcome : \Pramnos\Push\Log::outcome($row);
$device  = is_array($this->subscription ?? null) ? $this->subscription : null;
$history = is_array($this->history ?? null) ? $this->history : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when    = static function (array $r): string {
    $time = \Pramnos\Push\Log::sentAt($r);

    return $time > 0 ? localDateTime($time) : '—';
};
$css = ['delivered' => 'badge-success', 'gone' => 'badge-warning', 'not_sent' => 'badge-ghost', 'busy' => 'badge-warning', 'failed' => 'badge-error'];
$badge = static fn (array $what): string => '<span class="badge badge-sm ' . ($css[$what['kind']] ?? '') . '">'
    . htmlspecialchars($what['label'], ENT_QUOTES, 'UTF-8') . '</span>';
$userId = (int) ($row['userid'] ?? 0);
$hash   = (string) ($row['endpoint_hash'] ?? '');
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'pushlog'; $this->insert('../partials/admin_breadcrumb'); ?>

    <p><a href="<?php echo $e(adminUrl('PushLog')); ?>">&larr; All push notifications</a></p>

    <h2 class="text-lg font-semibold mb-3 flex items-center gap-2">Push notification #<?php echo (int) ($row['pushid'] ?? 0); ?> <?php echo $badge($outcome); ?></h2>

    <div class="alert mb-4 block" role="status">
        <strong><?php echo $e($outcome['label']); ?>.</strong>
        <?php echo $e($outcome['explanation']); ?>
        <?php if ((string) ($row['error'] ?? '') !== ''): ?>
            <div class="text-xs text-base-content/60">Reason: <?php echo $e($row['error']); ?></div>
        <?php endif; ?>
    </div>

    <div class="card bg-base-100 border border-base-300 p-4 mb-4">
        <h3 class="font-semibold mb-2">The message</h3>
        <table class="table table-sm">
            <tbody>
                <tr><th>Sent</th><td><?php echo $e($when($row)); ?></td></tr>
                <tr><th>Account</th><td>
                    <?php if ($userId > 0): ?>
                        <a href="<?php echo $e(adminUrl('Users/view/') . $userId); ?>">#<?php echo $userId; ?></a>
                        · <a href="<?php echo $e(adminUrl('PushLog') . '?userid=' . $userId); ?>">every push to this account</a>
                    <?php else: ?>—<?php endif; ?>
                </td></tr>
                <tr><th>Notification</th><td><code><?php echo $e(($row['notification'] ?? '') ?: '—'); ?></code></td></tr>
                <tr><th>Title</th><td><?php echo $e(($row['title'] ?? '') ?: '—'); ?></td></tr>
                <tr><th>Text</th><td><?php echo nl2br($e(($row['body'] ?? '') ?: '—')); ?></td></tr>
                <tr><th>Opens</th><td>
                    <?php if ((string) ($row['url'] ?? '') !== ''): ?>
                        <a href="<?php echo $e($row['url']); ?>" target="_blank" rel="noopener"><?php echo $e($row['url']); ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td></tr>
                <tr><th>Tag</th><td><?php echo $e(($row['tag'] ?? '') ?: '—'); ?>
                    <?php if ((string) ($row['tag'] ?? '') !== ''): ?>
                        <span class="text-xs text-base-content/60">— a newer notification with the same tag replaces this one on the device</span>
                    <?php endif; ?>
                </td></tr>
                <tr><th>Push service answer</th><td><?php echo (int) ($row['status'] ?? 0) > 0 ? 'HTTP ' . (int) $row['status'] : 'none'; ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="card bg-base-100 border border-base-300 p-4 mb-4">
        <h3 class="font-semibold mb-2">The device</h3>
        <?php if ($hash === ''): ?>
            <p class="text-xs text-base-content/60">None: nothing was sent, so no device was chosen.</p>
        <?php elseif ($device === null): ?>
            <p class="text-xs text-base-content/60">This subscription no longer exists. It is deleted when the push service says it is
                gone, or when the person turns notifications off on that device.</p>
        <?php else: ?>
            <table class="table table-sm">
                <tbody>
                    <tr><th>Browser</th><td><?php echo $e($device['user_agent'] ?: 'not recorded'); ?></td></tr>
                    <tr><th>Subscribed</th><td><?php echo $device['created_at'] > 0 ? $e(localDateTime($device['created_at'])) : '—'; ?></td></tr>
                    <tr><th>Last delivered</th><td><?php echo $device['last_success_at'] ? $e(localDateTime($device['last_success_at'])) : 'never'; ?></td></tr>
                    <tr><th>Failures since</th><td><?php echo (int) $device['failure_count']; ?></td></tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($history !== []): ?>
    <div class="card bg-base-100 border border-base-300 p-4 mb-4">
        <h3 class="font-semibold mb-2">Recent pushes to this device</h3>
        <table class="table table-sm">
            <thead><tr><th>Sent</th><th>Title</th><th>Outcome</th></tr></thead>
            <tbody>
                <?php foreach ($history as $other): ?>
                    <tr<?php echo (int) ($other['pushid'] ?? 0) === (int) ($row['pushid'] ?? 0) ? ' class="bg-base-200"' : ''; ?>>
                        <td><a href="<?php echo $e(adminUrl('PushLog/view/') . (int) ($other['pushid'] ?? 0)); ?>"><?php echo $e($when($other)); ?></a></td>
                        <td><?php echo $e(($other['title'] ?? '') ?: '—'); ?></td>
                        <td><?php echo $badge(\Pramnos\Push\Log::outcome($other)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
