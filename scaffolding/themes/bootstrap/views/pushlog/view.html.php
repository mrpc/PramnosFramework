<?php
/**
 * One push notification in full (Bootstrap theme).
 *
 * Variables:
 *   $this->row          — the push log row
 *   $this->outcome      — Log::outcome(): kind, label, explanation
 *   $this->subscription — Subscriptions::describe() of the device it went to, or null
 *   $this->history      — the latest attempts to that same device, newest first
 *   $this->receipt      — for a test notification, TestPush::status(): whether the device
 *                         itself confirmed it arrived; null for any other push
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
$css = ['delivered' => 'text-bg-success', 'gone' => 'text-bg-warning', 'not_sent' => 'text-bg-secondary', 'busy' => 'text-bg-warning', 'failed' => 'text-bg-danger'];
$badge = static fn (array $what): string => '<span class="badge ' . ($css[$what['kind']] ?? '') . '">'
    . htmlspecialchars($what['label'], ENT_QUOTES, 'UTF-8') . '</span>';
$receipt = is_array($this->receipt ?? null) ? $this->receipt : null;
$userId = (int) ($row['userid'] ?? 0);
$hash   = (string) ($row['endpoint_hash'] ?? '');
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'pushlog'; $this->insert('../partials/admin_breadcrumb'); ?>

    <p><a href="<?php echo $e(adminUrl('PushLog')); ?>">&larr; All push notifications</a></p>

    <h2 class="h4 mb-3">Push notification #<?php echo (int) ($row['pushid'] ?? 0); ?> <?php echo $badge($outcome); ?></h2>

    <div role="status" class="alert alert-light border mb-3">
        <strong><?php echo $e($outcome['label']); ?>.</strong>
        <?php echo $e($outcome['explanation']); ?>
        <?php if ((string) ($row['error'] ?? '') !== ''): ?>
            <div class="small text-muted">Reason: <?php echo $e($row['error']); ?></div>
        <?php endif; ?>
    </div>

    <?php if ($receipt !== null): ?>
        <?php if ($receipt['received_at'] !== null): ?>
            <div role="status" class="alert alert-success">
                <strong>The device received this test</strong> at <?php echo $e(localDateTime($receipt['received_at'])); ?>.
                If nothing appeared on its screen, the cause is on the device: notifications for this site
                or for the browser, battery optimisation, or Do Not Disturb.
            </div>
        <?php elseif ($receipt['expired']): ?>
            <div role="status" class="alert alert-warning">
                <strong>The device never confirmed this test.</strong> It did not reach the browser: the
                phone may have been off or offline for the hour the receipt was accepted, or the browser
                was closed and is not woken for pushes.
            </div>
        <?php else: ?>
            <div role="status" class="alert alert-warning">
                <strong>Waiting for the device to confirm.</strong> Its service worker sends a receipt the
                moment the push arrives; reload in a moment.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card p-3 mb-3">
        <h3 class="h6 mb-2">The message</h3>
        <table class="table table-sm mb-0">
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
                        <span class="small text-muted">— a newer notification with the same tag replaces this one on the device</span>
                    <?php endif; ?>
                </td></tr>
                <tr><th>Push service answer</th><td><?php echo (int) ($row['status'] ?? 0) > 0 ? 'HTTP ' . (int) $row['status'] : 'none'; ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="card p-3 mb-3">
        <h3 class="h6 mb-2">The device</h3>
        <?php if ($hash === ''): ?>
            <p class="small text-muted">None: nothing was sent, so no device was chosen.</p>
        <?php elseif ($device === null): ?>
            <p class="small text-muted">This subscription no longer exists. It is deleted when the push service says it is
                gone, or when the person turns notifications off on that device.</p>
        <?php else: ?>
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><th>Browser</th><td><?php echo $e($device['user_agent'] ?: 'not recorded'); ?></td></tr>
                    <tr><th>Subscribed</th><td><?php echo $device['created_at'] > 0 ? $e(localDateTime($device['created_at'])) : '—'; ?></td></tr>
                    <tr><th>Last delivered</th><td><?php echo $device['last_success_at'] ? $e(localDateTime($device['last_success_at'])) : 'never'; ?></td></tr>
                    <tr><th>Failures since</th><td><?php echo (int) $device['failure_count']; ?></td></tr>
                </tbody>
            </table>
            <form method="post" action="<?php echo $e(adminUrl('PushLog/test/') . (int) ($row['pushid'] ?? 0)); ?>" style="margin-top:12px">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-sm btn-outline-primary">Send a test to this device</button>
                <span class="small text-muted">
                    A fixed notification; its page shows whether the device confirms it.
                </span>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($history !== []): ?>
    <div class="card p-3 mb-3">
        <h3 class="h6 mb-2">Recent pushes to this device</h3>
        <table class="table table-sm mb-0">
            <thead><tr><th>Sent</th><th>Title</th><th>Outcome</th></tr></thead>
            <tbody>
                <?php foreach ($history as $other): ?>
                    <tr<?php echo (int) ($other['pushid'] ?? 0) === (int) ($row['pushid'] ?? 0) ? ' class="table-active"' : ''; ?>>
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
