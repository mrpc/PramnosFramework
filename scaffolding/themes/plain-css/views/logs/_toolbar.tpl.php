@php /**
 * Log-management toolbar (plain-CSS theme) — shared partial.
 *
 * Rendered via $this->insert('_toolbar') from every logs view that shows the
 * navigation bar (display/stats/search/archive/rotate).
 *
 * Variables:
 *   $this->toolbar   — array[] {url, label, variant, icon, confirm?}
 *   $this->clearList — string[] log files the "Clear Logs" action will wipe
 */
$variantColors = [
    'info'      => '#0ea5e9',
    'primary'   => '#2563eb',
    'warning'   => '#f59e0b',
    'secondary' => '#4b5563',
    'danger'    => '#dc2626',
]; @endphp
<div class="card" style="margin-bottom:24px">
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:8px">
        @foreach (($this->toolbar ?? []) as $link)
            @php if (!empty($link['post'])): // a write: a POST form carrying the token
                echo \Pramnos\Html\Icon::postControl($link['url'], htmlspecialchars($link['label']), ['class' => 'btn', 'style' => 'background:' . ($variantColors[$link['variant']] ?? $variantColors['secondary']) . ';color:#fff']
                    + (!empty($link['confirm']) ? ['data-confirm' => $link['confirm']] : []));
                continue;
            endif; @endphp
            @php $bg = $variantColors[$link['variant']] ?? $variantColors['secondary']; @endphp
            <a href="{{ $link['url'] }}"
               class="btn"
               style="background:{!! $bg !!};color:#fff"
               @if (!empty($link['confirm']))data-confirm="{{ $link['confirm'] }}"@endif>
                {{ $link['label'] }}
            </a>
        @endforeach
    </div>
    @if (!empty($this->clearList))
        <p style="font-size:12px;color:#888;margin:12px 0 0">
            &#9432; "Clear Logs" will clear: {{ implode(', ', $this->clearList) }}
        </p>
    @endif
</div>
