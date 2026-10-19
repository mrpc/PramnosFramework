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
<?php
$alert = ['success' => 'alert-success', 'info' => 'alert-info', 'warning' => 'alert-warning', 'error' => 'alert-error'];
$badge = ['success' => 'badge-success', 'info' => 'badge-info', 'warning' => 'badge-warning', 'error' => 'badge-error', 'neutral' => 'badge-neutral'];
?>
<div class="max-w-2xl mx-auto px-4 py-10">
    <h1 class="text-3xl font-bold mb-2"><?php echo $e(t('Service status')); ?></h1>
    <p class="text-base-content/70 mb-6"><?php echo $e(t('A live check, run when this page loaded.')); ?></p>

    <div class="alert <?php echo $alert[$tone]; ?> mb-6" role="status" aria-live="polite">
        <span class="font-semibold"><?php echo $e($headline); ?></span>
    </div>

    <div class="card bg-base-100 shadow-sm mb-6">
        <div class="px-4 py-3 border-b border-base-300 font-semibold"><?php echo $e(t('Checks')); ?></div>
        <?php if ($checks === []): ?>
            <p class="px-4 py-6 text-sm text-base-content/60"><?php echo $e(t('No check ran. That does not mean everything is working.')); ?></p>
        <?php else: ?>
            <ul class="divide-y divide-base-200">
                <?php foreach ($checks as $name => $check): ?>
                    <?php [$word, $checkTone] = $verdict((string) ($check['status'] ?? 'unknown')); ?>
                    <li class="flex items-center justify-between gap-4 px-4 py-3">
                        <span class="font-medium text-sm"><?php echo $e($readable((string) $name)); ?></span>
                        <span class="badge <?php echo $badge[$checkTone]; ?> badge-sm shrink-0"><?php echo $e($word); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($overall !== 'ok'): ?>
        <div role="status" class="alert alert-warning text-sm mb-6"><?php echo $e(t('The checks shown as degraded or down above say which part is affected. The details are available to the administrators.')); ?></div>
    <?php endif; ?>

    <p class="text-xs text-base-content/60">
        <?php echo $e(t('Checked: %s', (string) ($this->checkedAt ?? ''))); ?> &middot;
        <?php echo t('For monitoring use %s, which answers 200 or 503.', $monitor); ?>
    </p>
</div>
