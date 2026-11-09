@php /**
 * One dashboard card (Bootstrap theme), frame and all — drawn for each card of the dashboard, and
 * alone by DashboardController::card() for a card added or redrawn without a reload.
 *
 * Variables: `$widget` (or `$this->widget`) — {id, size (s|m|l|full), title, link|null, settings,
 * options (name => [value => label], or null for a free value), html}.
 */
$widget = $widget ?? $this->widget;
// A report's section heading: a line across the board, not a card.
$plain = !empty($widget['plain']);
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$options = (array) ($widget['options'] ?? []);
$settings = (array) ($widget['settings'] ?? []);
// The fields, as DashboardController::cardFor() describes them; a card drawn by an older
// controller has only `options` (a list, or null for a free value), read the same way.
$fields = (array) ($widget['fields'] ?? array_map(static fn ($choices): array => ['input' => is_array($choices) ? 'select' : 'text', 'label' => null, 'values' => (array) $choices, 'when' => []], $options));
/** Whether a field is shown for these settings: every setting it names has the value it names. */
$shown = static fn (array $field): bool => array_reduce(array_keys((array) ($field['when'] ?? [])), static fn (bool $all, string $other): bool => $all && (string) ($settings[$other] ?? '') === (string) $field['when'][$other], true); @endphp
<section class="@if ($plain)pf-report-section-card pf-dashboard-full@else card shadow-sm{!! $widget['size'] === 'full' ? ' pf-dashboard-full' : '' !!}@endif"
         data-pf-widget="{{ $widget['id'] }}" data-size="{{ $widget['size'] }}"
         data-settings="{{ json_encode((object) $settings) }}" aria-label="{{ $t((string) ($widget['label'] ?? $widget['title'])) }}">
    <div class="@if ($plain)pf-report-section-body@else card-body@endif">
        <div class="pf-dashboard-head mb-3" data-pf-widget-head>
            @if ($widget['title'] !== '')<h3 class="h6 mb-0">@if ($widget['link'])<a href="{{ $widget['link'] }}">{{ $t($widget['title']) }}</a>@php else: echo $e($t($widget['title'])); endif; @endphp</h3>@endif
            <div class="pf-dashboard-tools btn-group btn-group-sm" data-pf-widget-tools hidden>
                <span class="btn btn-outline-secondary pf-dashboard-grip" data-pf-widget-grip aria-hidden="true" title="{{ $t('Drag to move') }}">&#10303;</span>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="-1" aria-label="{{ $t('Move up') }}">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="1" aria-label="{{ $t('Move down') }}">&darr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-resize aria-label="{{ $t('Width') }}">&harr;</button>
                @if ($fields)<button type="button" class="btn btn-outline-secondary" data-pf-widget-settings-toggle aria-expanded="false" aria-label="{{ $t('Settings') }}">&#9881;</button>@endif
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-group-next aria-label="{{ $t('Group into tabs with the next card') }}">&#10697;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-hide aria-label="{{ $t('Hide') }}">&times;</button>
            </div>
        </div>
        @include('card_settings', ['widget' => $widget])
        {!! $widget['html'] !== '' ? $widget['html'] : '<p class="small text-body-secondary mb-0">' . $e($t('Nothing to show today.')) . '</p>' !!}
    </div>
    <span class="pf-dashboard-size-handle" data-pf-widget-size-handle aria-hidden="true" title="{{ $t('Drag to resize') }}" hidden></span>
</section>
