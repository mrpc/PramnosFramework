@php /**
 * The administration dashboard (Bootstrap theme): the signed-in person's widgets, in their order and
 * widths, and the controls to change them — Customise, then move, resize, hide or add a card.
 * pf-utils.js does the editing through the data-pf-dashboard* attributes and saves each change.
 *
 * Variables:
 *   $this->widgets  — array[] {id, size (s|m|l|full), title, link|null, settings, options, html},
 *                     each drawn by card.html.php — or a group of them as tabs {group: true, id,
 *                     size, title, active, tabs}, drawn by tabs.html.php
 *   $this->addable  — array   id => title: the widgets this person may add (a view of the older shape)
 *   $this->catalogue — array  id => {title, description, placed}: every card this person may have,
 *                     for the catalogue to add from (a dialog, searched)
 *   $this->cardUrl  — string  where one card is drawn alone (GET ?id=)
 *   $this->saveUrl  — string  where a layout is saved (POST, JSON)
 *   $this->resetUrl — string  where it goes back to the default (POST)
 *   $this->presets, presetUrls, managesPresets, roles, csrfField — the board's presets, for presets_dialog.html.php
 *   $this->embedded — bool    drawn inside another screen (Board::render()): no page heading or frame
 *   $this->boardTitle — string the board's heading (a person's own board's name, or Board::render()'s title); '' for the default
 *   $this->userBoard, boardUrls — a person's own board (or null), and its new/settings/delete actions, for presets_dialog.html.php
 *   ($this->activeUsers, dbStats, apiStats, healthResults — the figures, for a view of the older shape)
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words; @endphp
<div class="{!! !empty($this->embedded) ? 'pf-dashboard-embed' : 'container-fluid py-4' !!}">
    <div class="d-flex align-items-center gap-2 mb-3">
        @if (empty($this->embedded))<h2 class="mb-0">{{ ($this->boardTitle ?? '') !== '' ? $this->boardTitle : $t('Dashboard') }}</h2>@elseif (($this->boardTitle ?? '') !== '')<h3 class="mb-0">{{ $this->boardTitle }}</h3>@endif
        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-pf-dashboard-edit aria-pressed="false">{{ $t('Customise') }}</button>
    </div>

    <div class="card border-primary border-dashed mb-3 pf-dashboard-bar" data-pf-dashboard-bar hidden>
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
            <span class="small">{{ $t('Move, resize, set or hide each card; add the ones you want.') }}</span>
            @if (!empty($this->catalogue))
            <button type="button" class="btn btn-sm btn-primary" data-pf-dashboard-catalogue>{{ $t('Add a card') }}</button>
            @endif
            @if (isset($this->presetUrls))
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pf-dashboard-presets>{{ $t('Presets and dashboards') }}</button>
            @endif
            <span class="ms-auto">{!! \Pramnos\Html\Icon::postControl($this->resetUrl, $e($t('Back to the default')), ['class' => 'btn btn-sm btn-link', 'data-confirm' => $t('Go back to the default dashboard?')]) !!}</span>
            <button type="button" class="btn btn-sm btn-primary" data-pf-dashboard-done>{{ $t('Done') }}</button>
        </div>
    </div>

    <p class="visually-hidden" data-pf-dashboard-status aria-live="polite"></p>
    <div class="pf-dashboard" data-pf-dashboard data-save="{{ $this->saveUrl }}" data-card="{{ $this->cardUrl ?? '' }}" data-pf-dashboard-say="{{ json_encode(['saved' => $t('saved'), 'hidden' => $t('hidden'), 'added' => $t('added'), 'failed' => $t('The dashboard could not be saved.'), 'sizes' => ['s' => $t('small'), 'm' => $t('medium'), 'l' => $t('large'), 'full' => $t('full width')]], JSON_UNESCAPED_UNICODE) }}">
        @foreach ($this->widgets as $widget)
        @include(!empty($widget['group']) ? 'tabs' : 'card', ['widget' => $widget])
        @endforeach
        <p class="text-body-secondary pf-dashboard-full" data-pf-dashboard-empty{!! $this->widgets ? ' hidden' : '' !!}>{{ $t('Your dashboard is empty: choose Customise, then add a card.') }}</p>
    </div>
    @if (!empty($this->catalogue))
    <dialog class="border-0 rounded-3 shadow p-0" data-pf-dashboard-catalogue-dialog aria-labelledby="pf-dashboard-catalogue-title">
        <div class="p-3">
            <h3 id="pf-dashboard-catalogue-title">{{ $t('Add a card') }}</h3>
            <input type="search" class="form-control form-control-sm" data-pf-dashboard-catalogue-search placeholder="{{ $t('Search the cards') }}" aria-label="{{ $t('Search the cards') }}">
            <ul style="list-style:none;padding:0;margin:.75rem 0;max-height:60vh;overflow:auto">
                @foreach ($this->catalogue as $id => $one)
                <li class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom" data-pf-dashboard-option="{{ $id }}">
                    <div style="flex:1;min-width:12rem">
                        <strong>{{ $t((string) $one['title']) }}</strong>
                        @if (($one['description'] ?? '') !== '')<div class="small text-body-secondary">{{ $t((string) $one['description']) }}</div>@endif
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" data-pf-dashboard-add="{{ $id }}"{!! !empty($one['repeatable']) ? ' data-pf-dashboard-repeat' : '' !!}{!! !empty($one['placed']) ? ' hidden' : '' !!}>{{ $t('Add') }}</button>
                    <span class="badge text-bg-secondary" data-pf-dashboard-present{!! !empty($one['placed']) ? '' : ' hidden' !!}>{{ $t('On the board') }}</span>
                </li>
                @endforeach
            </ul>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pf-dashboard-catalogue-close>{{ $t('Close') }}</button>
        </div>
    </dialog>
    @endif
@if (isset($this->presetUrls))
    @include('presets_dialog')
    @endif
</div>
