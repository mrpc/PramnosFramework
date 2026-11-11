{{-- One report for a period. Variables: entry, at, period, previous, next, body (the report's cards, Board::render()), mine, customise, pickers (dimension => label, values, search), pinned (the pickers always in sight), variables (the filters chosen), urls. --}}
@php
$variables = $this->variables ?? [];
$pickers   = $this->pickers ?? [];
$pinned    = array_flip($this->pinned ?? []);
$keep      = static fn (array $v): string => $v !== [] ? '&' . http_build_query(['v' => $v]) : '';
$rid       = (string) ($this->reportId ?? $this->entry['id']);
$query     = fn (string $at, ?array $v = null): string => '?report=' . rawurlencode($rid) . '&at=' . $at . $keep($v ?? $variables);
$post      = fn (string $url, array $fields, string $label): string => '<form method="post" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . ($this->csrfField ?? '')
    . implode('', array_map(static fn ($k, $v): string => '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">', array_keys($fields), $fields))
    . '<button type="submit" class="pf-btn pf-btn-ghost pf-btn-sm">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></form>';
$folded    = array_diff_key($pickers, $pinned);
$active    = count(array_filter(array_intersect_key($variables, $folded), static fn ($v): bool => (string) $v !== ''));
$chips     = array_filter($variables, static fn ($v): bool => (string) $v !== '');
// One period control, by the report's grain: a month picker for a month, a week for a week.
$day       = strtotime($this->at) ?: time();
[$periodKind, $periodValue] = match ($this->grain ?? 'month') {
    'month', 'quarter' => ['month', date('Y-m', $day)],
    'week'             => ['week', date('o-\\WW', $day)],
    'year'             => ['number', date('Y', $day)],
    default            => ['date', date('Y-m-d', $day)],
};
@endphp
<style data-pf-report>.pf-report-page .pf-dashboard{gap:.5rem}.pf-report-page [data-pf-widget]>.card-body{padding:.5rem .75rem!important;gap:.35rem!important}.pf-report-page .pf-dashboard-head{margin-bottom:.25rem!important}.pf-report-page .pf-report th,.pf-report-page .pf-report td{padding:.15rem .5rem!important;line-height:1.35}.pf-report-page .pf-report{margin-bottom:0}[data-pf-widget-tools][hidden],[data-pf-widget-size-handle][hidden]{display:none!important}.pf-report-section-card{padding:.75rem 0 .15rem;border-bottom:1px solid rgba(127,127,127,.35)}.pf-report-section-card .pf-dashboard-head{margin:0!important}.pf-report-section-toggle{display:flex;align-items:center;gap:.4rem;width:100%;padding:0;border:0;background:none;color:inherit;font:inherit;font-size:17px;font-weight:600;text-align:left;cursor:pointer}.pf-report-section-toggle::before{content:"\25BE";display:inline-block;transition:transform .15s}.pf-report-section-toggle[aria-expanded=false]::before{transform:rotate(-90deg)}.pf-report-head{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.5rem}.pf-report-period{display:flex;align-items:center;gap:4px}.pf-report-page .pf-report-group th{padding-top:.4rem!important;font-weight:600}.pf-report-page .pf-report .pf-report-part>th{padding-left:1.5em!important}.pf-report-tools{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.5rem}.pf-report-field{display:flex;flex-direction:column;gap:2px;font-size:13px;opacity:.95}.pf-report-field>span{opacity:.75}.pf-report-pop{position:relative}.pf-report-pop>summary{list-style:none;cursor:pointer}.pf-report-pop>summary::-webkit-details-marker{display:none}.pf-report-pop>div{position:absolute;right:0;z-index:30;margin-top:.25rem;min-width:16rem;max-height:70vh;overflow:auto;padding:.75rem;border:1px solid rgba(127,127,127,.35);border-radius:.5rem;background:var(--pf-surface,#fff);color:inherit;box-shadow:0 8px 24px rgba(0,0,0,.15);display:flex;flex-direction:column;gap:.5rem}.pf-report-menu>div a,.pf-report-menu>div button{text-align:left;justify-content:flex-start}.pf-report-count{display:inline-block;min-width:1.25rem;margin-left:.25rem;padding:0 .35rem;border-radius:999px;background:currentColor;font-size:.75rem;line-height:1.25rem;text-align:center}.pf-report-count>span{color:var(--pf-surface,#fff)}.pf-report-chips{display:flex;flex-wrap:wrap;gap:.35rem;margin:0 0 .5rem}.pf-report-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.1rem .6rem;border:1px solid rgba(127,127,127,.4);border-radius:999px;font-size:.85rem;text-decoration:none;color:inherit}.pf-report-chip:hover{background:rgba(127,127,127,.12)}@media print{.pf-report-page .pf-no-print,[data-pf-widget-tools]{display:none!important}}</style>
<div class="pf-ui pf-report-page">
    <div class="pf-report-head">
        <div>
            <h1 class="pf-page-title">{{ $this->entry['name'] }}</h1>
            @if (!empty($this->original))<p class="pf-page-sub pf-no-print">{{ t('Your own arrangement of this report') }}</p>@endif
            <p class="pf-page-sub">{{ $this->period }}</p>
        </div>
        <div class="pf-report-tools pf-no-print">
            <form method="get" action="{{ $this->urls['show'] }}" class="pf-report-tools">
                <input type="hidden" name="report" value="{{ $rid }}">
                @foreach ($pickers as $dimension => $picker)
                @if (isset($pinned[$dimension]))
                <label class="pf-report-field"><span>{{ $picker['label'] }}</span>
                    @if (!empty($picker['search']))
                    <input type="search" name="v[{{ $dimension }}]" list="pf-dimension-{{ $dimension }}" value="{{ $variables[$dimension] ?? '' }}" class="pf-input" placeholder="{{ t('All values') }}">
                    <datalist id="pf-dimension-{{ $dimension }}">@foreach ($picker['values'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</datalist>
                    @else
                    <select name="v[{{ $dimension }}]" class="pf-select" data-pf-autosubmit>
                        <option value="">{{ t('All values') }}</option>
                        @foreach ($picker['values'] as $value => $label)<option value="{{ $value }}"{{ ($variables[$dimension] ?? '') === (string) $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach
                    </select>
                    @endif
                </label>
                @endif
                @endforeach
                <div class="pf-report-field"><span id="pf-report-period">{{ t('Period') }}</span>
                    <div class="pf-report-period">
                        <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['show'] . $query($this->previous) }}" aria-label="{{ t('Previous period') }}">‹</a>
                        <input type="{!! $periodKind !!}" name="at" value="{{ $periodValue }}"@if ($periodKind === 'number') min="2000" max="2100" style="width:6em"@endif class="pf-input" aria-labelledby="pf-report-period" data-pf-autosubmit>
                        <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['show'] . $query($this->next) }}" aria-label="{{ t('Next period') }}">›</a>
                    </div>
                </div>
                @if ($folded !== [])
                <details class="pf-report-pop pf-report-filters">
                    <summary class="pf-btn pf-btn-sm">{{ t('Filters') }}@if ($active > 0)<span class="pf-report-count"><span>{!! (int) $active !!}</span></span>@endif</summary>
                    <div>
                        @foreach ($folded as $dimension => $picker)
                        <label class="pf-report-field"><span>{{ $picker['label'] }}</span>
                            @if (!empty($picker['search']))
                            <input type="search" name="v[{{ $dimension }}]" list="pf-dimension-{{ $dimension }}" value="{{ $variables[$dimension] ?? '' }}" class="pf-input" placeholder="{{ t('All values') }}">
                            <datalist id="pf-dimension-{{ $dimension }}">@foreach ($picker['values'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</datalist>
                            @else
                            <select name="v[{{ $dimension }}]" class="pf-select">
                                <option value="">{{ t('All values') }}</option>
                                @foreach ($picker['values'] as $value => $label)<option value="{{ $value }}"{{ ($variables[$dimension] ?? '') === (string) $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach
                            </select>
                            @endif
                        </label>
                        @endforeach
                        <button type="submit" class="pf-btn pf-btn-sm">{{ t('Apply') }}</button>
                    </div>
                </details>
                @endif
                <noscript><button type="submit" class="pf-btn pf-btn-sm">{{ t('Show') }}</button></noscript>
            </form>
            <details class="pf-report-pop pf-report-menu">
                <summary class="pf-btn pf-btn-ghost pf-btn-sm" aria-label="{{ t('More actions') }}" title="{{ t('More actions') }}">⋯</summary>
                <div>
                    @if ($this->mine)
                    <button type="button" class="pf-btn pf-btn-ghost pf-btn-sm" data-pf-dashboard-edit{!! $this->customise ? " data-pf-dashboard-open" : "" !!} aria-pressed="false">{{ t('Customise') }}</button>
                    <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?report=' . rawurlencode($this->entry['id']) }}">{{ t('Settings') }}</a>
                    @if (!empty($this->original))
                    {!! $post($this->urls['uncustomise'] ?? '', ['report' => $rid], t('Reset to the original')) !!}
                    @endif
                    @else
                    {!! $post($this->urls['customise'] ?? '', ['report' => $rid], t('Customise')) !!}
                    <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?copy=' . rawurlencode($rid) }}">{{ t('Copy') }}</a>
                    @endif
                    @if (!empty($this->editsMenu))
                    {!! $post($this->urls['menu'] ?? '', ['report' => $rid, 'on' => empty($this->inMenu) ? '1' : ''], empty($this->inMenu) ? t('Show in the menu') : t('Take off the menu')) !!}
                    @endif
                    <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['csv'] . $query($this->at) }}">{{ t('Export') }} CSV</a>
                    @if (isset($this->urls['xlsx']))<a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['xlsx'] . $query($this->at) }}">{{ t('Export') }} XLSX</a>@endif
                    <button type="button" class="pf-btn pf-btn-ghost pf-btn-sm" data-pf-print>{{ t('Print or save as PDF') }}</button>
                    <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['list'] }}">{{ t('All reports') }}</a>
                </div>
            </details>
        </div>
    </div>
    @if ($chips !== [])
    <div class="pf-report-chips pf-no-print" aria-label="{{ t('Filters') }}">
        @foreach ($chips as $dimension => $value)
        @php $without = $variables; unset($without[$dimension]); $picker = $pickers[$dimension] ?? null; @endphp
        <a class="pf-report-chip" href="{{ $this->urls['show'] . $query($this->at, $without) }}" aria-label="{{ sprintf(t('Remove the filter %s'), ($picker['label'] ?? $dimension)) }}">{{ ($picker['label'] ?? $dimension) . ': ' . ($picker['values'][$value] ?? $value) }} <span aria-hidden="true">×</span></a>
        @endforeach
    </div>
    @endif
    {!! $this->body !!}
</div>
