@php /**
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
$monitor  = '<a href="' . $e(sURL . 'Status/check') . '"><code>/Status/check</code></a>'; @endphp
@php $alert = ['success' => 'alert-success', 'info' => 'alert-info', 'warning' => 'alert-warning', 'error' => 'alert-danger'];
$badge = ['success' => 'bg-success', 'info' => 'bg-info', 'warning' => 'bg-warning text-dark', 'error' => 'bg-danger', 'neutral' => 'bg-secondary']; @endphp
<div class="container py-5" style="max-width:42rem">
    <h1 class="h2 mb-2">{{ t('Service status') }}</h1>
    <p class="text-muted mb-4">{{ t('A live check, run when this page loaded.') }}</p>

    <div class="alert {!! $alert[$tone] !!} fw-semibold" role="status" aria-live="polite">{{ $headline }}</div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">{{ t('Checks') }}</div>
        @if ($checks === [])
            <div class="card-body text-muted small">{{ t('No check ran. That does not mean everything is working.') }}</div>
        @else
            <ul class="list-group list-group-flush">
                @foreach ($checks as $name => $check)
                    @php [$word, $checkTone] = $verdict((string) ($check['status'] ?? 'unknown')); @endphp
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>{{ $readable((string) $name) }}</span>
                        <span class="badge {!! $badge[$checkTone] !!}">{{ $word }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($overall !== 'ok')
        <div role="status" class="alert alert-warning small">{{ t('The checks shown as degraded or down above say which part is affected. The details are available to the administrators.') }}</div>
    @endif

    <p class="small text-muted">
        {{ t('Checked: %s', (string) ($this->checkedAt ?? '')) }} &middot;
        {!! t('For monitoring use %s, which answers 200 or 503.', $monitor) !!}
    </p>
</div>
