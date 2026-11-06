@php /**
 * A group of dashboard cards as tabs (bootstrap theme): one frame, a tab per card, only the active
 * tab's card drawn — the others are drawn by the server the first time they are opened
 * (`card?id=…&part=body`). While customising, the group moves, resizes and hides as one card, and
 * its active tab can be moved along the strip or taken out of the group, and set — each panel
 * carries its card's settings form (card_settings.html.php) above its inside (data-pf-tab-body).
 *
 * A theme draws its own tabs by copying this file: the markup the script reads is the
 * data-pf-* attributes — data-pf-widget-group on the frame, data-pf-tabs on the strip (data-active-class: the
 * theme's class for the active tab), data-pf-tab on each tab, and
 * data-pf-tab-panel on each card's panel (data-pf-tab-lazy until it is drawn).
 *
 * Variables: `$widget` — {group: true, id, size, title, active, tabs: [card, …]}, each card as
 * DashboardController::cardFor() gives it, `html` null for one not drawn yet.
 */
$widget = $widget ?? $this->widget;
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$label = ($widget['title'] ?? '') !== '' ? (string) $widget['title'] : implode(' · ', array_map(static fn (array $tab): string => $t((string) $tab['title']), $widget['tabs'])); @endphp
<section class="card shadow-sm pf-dashboard-tabs{!! $widget['size'] === 'full' ? ' pf-dashboard-full' : '' !!}"
         data-pf-widget="{{ $widget['id'] }}" data-pf-widget-group data-size="{{ $widget['size'] }}"
         data-active="{{ $widget['active'] }}" data-title="{{ $widget['title'] ?? '' }}" aria-label="{{ $label }}">
    <div class="card-body">
        <div class="pf-dashboard-head mb-2" data-pf-widget-head>
            <h3 class="h6 mb-0">{!! ($widget['title'] ?? '') !== '' ? $e($widget['title']) : '' !!}</h3>
            <div class="pf-dashboard-tools btn-group btn-group-sm" data-pf-widget-tools hidden>
                <span class="btn btn-outline-secondary pf-dashboard-grip" data-pf-widget-grip aria-hidden="true" title="{{ $t('Drag to move') }}">&#10303;</span>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="-1" aria-label="{{ $t('Move up') }}">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="1" aria-label="{{ $t('Move down') }}">&darr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-resize aria-label="{{ $t('Width') }}">&harr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-tab-move="-1" aria-label="{{ $t('Move this tab left') }}">&laquo;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-tab-move="1" aria-label="{{ $t('Move this tab right') }}">&raquo;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-tab-ungroup aria-label="{{ $t('Take this tab out of the group') }}">&#8689;</button>
                @if (array_filter($widget['tabs'], static fn (array $tab): bool => !empty($tab['fields']) || !empty($tab['options'])))<button type="button" class="btn btn-outline-secondary" data-pf-tab-settings-toggle aria-expanded="false" aria-label="{{ $t('Settings of this tab') }}">&#9881;</button>@endif
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-hide aria-label="{{ $t('Hide') }}">&times;</button>
            </div>
        </div>
        <div class="nav nav-tabs mb-3" role="tablist" data-pf-tabs data-active-class="active">
            @foreach ($widget['tabs'] as $tab) @php $on = $tab['id'] === $widget['active']; @endphp
            <button type="button" role="tab" class="nav-link{!! $on ? ' active' : '' !!}" data-pf-tab="{{ $tab['id'] }}" aria-selected="{!! $on ? 'true' : 'false' !!}">{{ $t((string) $tab['title']) }}</button>
            @endforeach
        </div>
        @foreach ($widget['tabs'] as $tab) @php $on = $tab['id'] === $widget['active']; @endphp
        <div role="tabpanel" data-pf-tab-panel="{{ $tab['id'] }}" data-settings="{{ json_encode((object) ($tab['settings'] ?? [])) }}" aria-label="{{ $t((string) $tab['title']) }}"{!! $on ? '' : ' hidden' !!}{!! $tab['html'] === null ? ' data-pf-tab-lazy' : '' !!}>
            @include('card_settings', ['widget' => $tab])
            <div data-pf-tab-body>
                @if ($tab['html'] !== null)
                {!! $tab['html'] !== '' ? $tab['html'] : '<p class="small text-body-secondary mb-0">' . $e($t('Nothing to show today.')) . '</p>' !!}
                @endif
            </div>
        </div>
        @endforeach
    </div>
    <span class="pf-dashboard-size-handle" data-pf-widget-size-handle aria-hidden="true" title="{{ $t('Drag to resize') }}" hidden></span>
</section>
