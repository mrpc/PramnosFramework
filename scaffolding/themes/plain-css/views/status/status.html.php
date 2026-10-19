<?php
/**
 * The public status page (`Pramnos\Application\Controllers\Status`).
 *
 * The verdict and each check's verdict, by name — **never** a check's message or details: those
 * are measurements of the host (the database's version, the disk's free space) on a page with no
 * sign-in in front of it. The administration's `/health` dashboard shows them.
 *
 * Variables:
 *   $this->report    — HealthRegistry::runAll(): status + checks
 *   $this->checkedAt — when it ran
 */
$report  = $this->report ?? [];
$overall = (string) ($report['status'] ?? 'unknown');
$checks  = (array) ($report['checks'] ?? []);
$e       = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/** The overall verdict → what to say, and its tone. */
$states = [
    'ok'       => [t('Everything is working'), 'success'],
    'notice'   => [t('Working, with something to note'), 'info'],
    'degraded' => [t('Partly available'), 'warning'],
    'down'     => [t('Unavailable'), 'error'],
];
[$headline, $tone] = $states[$overall] ?? [t('Unknown state'), 'info'];

/** One check's verdict → its word and tone. */
$verdict = static fn (string $status): array => match ($status) {
    'ok'       => [t('Working'), 'success'],
    'notice'   => [t('Note'), 'info'],
    'degraded' => [t('Degraded'), 'warning'],
    'down'     => [t('Down'), 'error'],
    default    => [t('Unknown'), 'neutral'],
};

/** A check's machine name, made readable. */
$readable = static fn (string $name): string => ucfirst(str_replace(['_', '-'], ' ', $name));
$monitor  = '<a href="' . $e(sURL . 'Status/check') . '"><code>/Status/check</code></a>';
?>
<div class="page-section" style="max-width:42rem;margin:2rem auto">
    <h1><?php echo $e(t('Service status')); ?></h1>
    <p class="pf-muted"><?php echo $e(t('A live check, run when this page loaded.')); ?></p>

    <div class="alert alert-<?php echo $tone === 'error' ? 'danger' : $tone; ?>" role="status" aria-live="polite"><strong><?php echo $e($headline); ?></strong></div>

    <div class="card" style="margin:1rem 0">
        <div class="card-body">
            <h2 style="font-size:1rem;margin-top:0"><?php echo $e(t('Checks')); ?></h2>
            <?php if ($checks === []): ?>
                <p class="pf-muted"><?php echo $e(t('No check ran. That does not mean everything is working.')); ?></p>
            <?php else: ?>
                <table class="table">
                    <tbody>
                        <?php foreach ($checks as $name => $check): ?>
                            <?php [$word, $checkTone] = $verdict((string) ($check['status'] ?? 'unknown')); ?>
                            <tr>
                                <td><?php echo $e($readable((string) $name)); ?></td>
                                <td style="text-align:right"><span class="badge pf-status-<?php echo $checkTone; ?>"><?php echo $e($word); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($overall !== 'ok'): ?>
        <div role="status" class="alert alert-warning"><?php echo $e(t('The checks shown as degraded or down above say which part is affected. The details are available to the administrators.')); ?></div>
    <?php endif; ?>

    <p class="pf-muted small">
        <?php echo $e(t('Checked: %s', (string) ($this->checkedAt ?? ''))); ?> &middot;
        <?php echo t('For monitoring use %s, which answers 200 or 503.', $monitor); ?>
    </p>
</div>
