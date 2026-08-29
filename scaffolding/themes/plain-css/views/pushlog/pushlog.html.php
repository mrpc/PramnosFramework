<?php
/**
 * What was pushed, and what came of it (plain-CSS theme).
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
$when   = static function ($value): string {
    $time = (int) $value;

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
        return ['pf-ok', 'Delivered'];
    }

    if ($status === 404 || $status === 410) {
        return ['pf-warn', 'Subscription gone'];
    }

    if ((string) ($row['endpoint_hash'] ?? '') === '') {
        return ['pf-muted', 'Not sent'];
    }

    if ($status === 429 || ($status >= 500 && $status < 600)) {
        return ['pf-warn', 'Push service busy (' . $status . ')'];
    }

    return ['pf-bad', $status > 0 ? 'Failed (' . $status . ')' : 'Never reached a server'];
};
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'pushlog'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h2 class="text-lg font-semibold">Push notifications</h2>
        <span class="pf-muted"><?php echo count($rows); ?></span>
    </div>

    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:16px">
        <div style="border:1px solid #ddd;border-radius:4px;padding:10px 14px;min-width:150px">
            <div style="font-size:0.8em;color:#666">Delivered</div>
            <div style="font-size:1.6em;color:#15803d"><?php echo (int) ($stats['delivered'] ?? 0); ?></div>
            <div style="font-size:0.75em;color:#888">last 7 days</div>
        </div>
        <div style="border:1px solid #ddd;border-radius:4px;padding:10px 14px;min-width:150px">
            <div style="font-size:0.8em;color:#666">Not sent</div>
            <div style="font-size:1.6em"><?php echo (int) ($stats['refused'] ?? 0); ?></div>
            <div style="font-size:0.75em;color:#888">nothing subscribed, no keys, no library</div>
        </div>
        <div style="border:1px solid #ddd;border-radius:4px;padding:10px 14px;min-width:150px">
            <div style="font-size:0.8em;color:#666">Subscription gone</div>
            <div style="font-size:1.6em;color:#b45309"><?php echo (int) ($stats['gone'] ?? 0); ?></div>
            <div style="font-size:0.75em;color:#888">the row was deleted</div>
        </div>
        <div style="border:1px solid #ddd;border-radius:4px;padding:10px 14px;min-width:150px">
            <div style="font-size:0.8em;color:#666">Failed</div>
            <div style="font-size:1.6em;color:#b91c1c"><?php echo (int) ($stats['failed'] ?? 0); ?></div>
            <div style="font-size:0.75em;color:#888">busy, or never reached a server</div>
        </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="<?php echo adminUrl('PushLog'); ?>"
           style="padding:4px 10px;border:1px solid #ccc;border-radius:3px;text-decoration:none">
            Everything
        </a>
        <a href="<?php echo adminUrl('PushLog'); ?>?show=failed"
           style="padding:4px 10px;border:1px solid #ccc;border-radius:3px;text-decoration:none">
            Only what did not arrive
        </a>
        <?php if ($userId > 0): ?>
            <span class="pf-muted">
                account #<?php echo $userId; ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($rows === []): ?>
        <div style="border:1px solid #ddd;border-radius:4px;padding:18px;color:#555">
            Nothing here yet. A row is written for every notification this application pushes —
            <strong>including the ones it decides not to send</strong>, which is usually the row
            somebody is looking for.
        </div>
    <?php else: ?>
        <div style="border:1px solid #ddd;border-radius:4px;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:0.9em">
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
                            <td class="whitespace-nowrap"><?php echo $e($when($row['sent'] ?? 0)); ?></td>
                            <td>
                                <?php if ((int) ($row['userid'] ?? 0) > 0): ?>
                                    <a 
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
                                    <span class="block text-xs pf-muted">
                                        <?php echo $e(mb_substr((string) $row['body'], 0, 120)); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?php echo $class; ?>"><?php echo $e($label); ?></span>
                                <?php if (($row['error'] ?? '') !== ''): ?>
                                    <span class="block text-xs pf-muted">
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
