@php /**
 * Log-management toolbar (Bootstrap theme) — shared partial.
 *
 * Rendered via $this->insert('_toolbar') from every logs view that shows the
 * navigation bar (display/stats/search/archive/rotate).
 *
 * Variables:
 *   $this->toolbar   — array[] {url, label, variant, icon, confirm?}
 *   $this->clearList — string[] log files the "Clear Logs" action will wipe
 */
$variantClasses = [
    'info'      => 'btn btn-info',
    'primary'   => 'btn btn-primary',
    'warning'   => 'btn btn-warning',
    'secondary' => 'btn btn-secondary',
    'danger'    => 'btn btn-danger',
]; @endphp
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            @foreach (($this->toolbar ?? []) as $link)
            @php if (!empty($link['post'])): // a write: a POST form carrying the token
                echo \Pramnos\Html\Icon::postControl($link['url'], htmlspecialchars($link['label']), ['class' => $variantClasses[$link['variant']] ?? $variantClasses['secondary']]
                    + (!empty($link['confirm']) ? ['data-confirm' => $link['confirm']] : []));
                continue;
            endif; @endphp
                <a href="{{ $link['url'] }}"
                   class="{!! $variantClasses[$link['variant']] ?? $variantClasses['secondary'] !!}"
                   @if (!empty($link['confirm']))data-confirm="{{ $link['confirm'] }}"@endif>
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
        @if (!empty($this->clearList))
            <p class="small text-muted mt-3 mb-0">
                &#9432; "Clear Logs" will clear: {{ implode(', ', $this->clearList) }}
            </p>
        @endif
    </div>
</div>
