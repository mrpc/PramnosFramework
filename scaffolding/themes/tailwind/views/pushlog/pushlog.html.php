<?php
/**
 * What was pushed, and what came of it (Tailwind theme).
 *
 * Variables:
 *   $this->rows   — the recent attempts, newest first
 *   $this->stats  — the week's shape: total, delivered, gone, refused, failed
 *   $this->userId — the account being filtered to, or 0
 *   $this->only   — 'failed' when only the problems are shown
 *
 * The refusals are on the same screen as the deliveries, not on their own. "No browser on this
 * account is subscribed" is the commonest answer to *why did they not get it*, and it is only
 * useful beside the sends that did work — a screen listing only successes makes an installation
 * with no key pair look identical to one where everything is arriving.
 *
 * The endpoint is not shown because it is not stored: whoever holds it can push to that browser.
 */
$rows   = is_array($this->rows ?? null) ? $this->rows : [];
$stats  = is_array($this->stats ?? null) ? $this->stats : [];
$userId = (int) ($this->userId ?? 0);
$only   = (string) ($this->only ?? '');
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
/*
 * One reader for the timestamp.
 *
 * `sent` is a `timestamptz`, so PostgreSQL hands back `2026-08-29 14:49:32.517335+00` and MySQL
 * `2026-08-29 14:49:32`. `Log::sentAt()` is the one place that knows, so no view parses a date
 * itself — which is how `d/m/Y` ended up being sorted as a string elsewhere in this framework.
 */
$when   = static function (array $row): string {
    $time = \Pramnos\Push\Log::sentAt($row);

    return $time > 0 ? localDateTime($time) : '—';
};

/*
 * What a status means, in words.
 *
 * `410` is not a number an operator should have to look up: it is the browser telling us the
 * subscription is gone, which is a different problem from a busy service and from a request
 * that never reached one.
 */
$outcome = static function (array $row): array {
    $status = (int) ($row['status'] ?? 0);

    if ($status >= 200 && $status < 300) {
        return ['badge-success', 'Delivered'];
    }

    if ($status === 404 || $status === 410) {
        return ['badge-warning', 'Subscription gone'];
    }

    if ((string) ($row['endpoint_hash'] ?? '') === '') {
        return ['badge-ghost', 'Not sent'];
    }

    if ($status === 429 || ($status >= 500 && $status < 600)) {
        return ['badge-warning', 'Push service busy (' . $status . ')'];
    }

    return ['badge-error', $status > 0 ? 'Failed (' . $status . ')' : 'Never reached a server'];
};
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'pushlog'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h2 class="text-lg font-semibold">Push notifications</h2>
        <span class="badge badge-neutral badge-sm"><?php echo count($rows); ?></span>
    </div>

    <div class="stats stats-horizontal shadow-xs border border-base-300 mb-4 w-full overflow-x-auto">
        <div class="stat">
            <div class="stat-title">Delivered</div>
            <div class="stat-value text-2xl text-success"><?php echo (int) ($stats['delivered'] ?? 0); ?></div>
            <div class="stat-desc">last 7 days</div>
        </div>
        <div class="stat">
            <div class="stat-title">Not sent</div>
            <div class="stat-value text-2xl"><?php echo (int) ($stats['refused'] ?? 0); ?></div>
            <div class="stat-desc">nothing subscribed, no keys, no library</div>
        </div>
        <div class="stat">
            <div class="stat-title">Subscription gone</div>
            <div class="stat-value text-2xl text-warning"><?php echo (int) ($stats['gone'] ?? 0); ?></div>
            <div class="stat-desc">the row was deleted</div>
        </div>
        <div class="stat">
            <div class="stat-title">Failed</div>
            <div class="stat-value text-2xl text-error"><?php echo (int) ($stats['failed'] ?? 0); ?></div>
            <div class="stat-desc">busy, or never reached a server</div>
        </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="<?php echo adminUrl('PushLog'); ?>"
           class="btn btn-xs <?php echo $only === '' && $userId === 0 ? 'btn-primary' : 'btn-ghost'; ?>">
            Everything
        </a>
        <a href="<?php echo adminUrl('PushLog'); ?>?show=failed"
           class="btn btn-xs <?php echo $only === 'failed' ? 'btn-primary' : 'btn-ghost'; ?>">
            Only what did not arrive
        </a>
        <?php if ($userId > 0): ?>
            <span class="badge badge-outline badge-sm self-center">
                account #<?php echo $userId; ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($rows === []): ?>
        <div class="card bg-base-100 border border-base-300 shadow-xs p-6 text-sm text-base-content/70">
            Nothing here yet. A row is written for every notification this application pushes —
            <strong>including the ones it decides not to send</strong>, which is usually the row
            somebody is looking for.
        </div>
    <?php else: ?>
        <div class="card bg-base-100 border border-base-300 shadow-xs overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Account</th>
                        <th>Notification</th>
                        <th>Title</th>
                        <th>Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php [$class, $label] = $outcome($row); ?>
                        <tr>
                            <td class="whitespace-nowrap"><?php echo $e($when($row)); ?></td>
                            <td>
                                <?php if ((int) ($row['userid'] ?? 0) > 0): ?>
                                    <a class="link link-hover"
                                       href="<?php echo adminUrl('Users/view/') . (int) $row['userid']; ?>">
                                        #<?php echo (int) $row['userid']; ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-xs">
                                <?php
                                $name  = (string) ($row['notification'] ?? '');
                                $short = $name === '' ? '—' : substr((string) strrchr('\\' . $name, '\\'), 1);
                                echo $e($short);
                                ?>
                            </td>
                            <td>
                                <?php echo $e($row['title'] ?? ''); ?>
                                <?php if (($row['body'] ?? '') !== ''): ?>
                                    <span class="block text-xs text-base-content/60">
                                        <?php echo $e(mb_substr((string) $row['body'], 0, 120)); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-sm <?php echo $class; ?>"><?php echo $e($label); ?></span>
                                <?php if (($row['error'] ?? '') !== ''): ?>
                                    <span class="block text-xs text-base-content/60">
                                        <?php echo $e($row['error']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
