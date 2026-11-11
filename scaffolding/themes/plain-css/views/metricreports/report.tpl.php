{{-- One report for a period. Variables: entry, at, period, previous, next, body (the report's cards, Board::render()), mine, customise, pickers (dimension => label, values, search), pinned (the pickers always in sight), variables (the filters chosen), urls. --}}
@php
$variables = $this->variables ?? [];
$pickers   = $this->pickers ?? [];
$pinned    = array_flip($this->pinned ?? []);
$keep      = static fn (array $v): string => $v !== [] ? '&' . http_build_query(['v' => $v]) : '';
$query     = fn (string $at, ?array $v = null): string => '?report=' . rawurlencode($this->entry['id']) . '&at=' . $at . $keep($v ?? $variables);
$folded    = array_diff_key($pickers, $pinned);
$active    = count(array_filter(array_intersect_key($variables, $folded), static fn ($v): bool => (string) $v !== ''));
$chips     = array_filter($variables, static fn ($v): bool => (string) $v !== '');
@endphp
<style data-pf-report>.pf-report-page .pf-dashboard{gap:.5rem}.pf-report-page [data-pf-widget]>.card-body{padding:.5rem .75rem!important;gap:.35rem!important}.pf-report-page .pf-dashboard-head{margin-bottom:.25rem!important}.pf-report-page .pf-report th,.pf-report-page .pf-report td{padding:.15rem .5rem!important;line-height:1.35}.pf-report-page .pf-report{margin-bottom:0}[data-pf-widget-tools][hidden],[data-pf-widget-size-handle][hidden]{display:none!important}.pf-report-section-card{padding:.75rem 0 .15rem;border-bottom:1px solid rgba(127,127,127,.35)}.pf-report-section-card .pf-dashboard-head{margin:0!important}.pf-report-section-toggle{display:flex;align-items:center;gap:.4rem;width:100%;padding:0;border:0;background:none;color:inherit;font:inherit;font-size:1.05rem;font-weight:600;text-align:left;cursor:pointer}.pf-report-section-toggle::before{content:"\25BE";display:inline-block;transition:transform .15s}.pf-report-section-toggle[aria-expanded=false]::before{transform:rotate(-90deg)}.pf-report-head{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.5rem}.pf-report-period{display:flex;align-items:center;gap:.25rem;margin-top:.15rem}.pf-report-tools{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.5rem}.pf-report-field{display:flex;flex-direction:column;gap:.15rem;font-size:.8rem;opacity:.95}.pf-report-field>span{opacity:.75}.pf-report-pop{position:relative}.pf-report-pop>summary{list-style:none;cursor:pointer}.pf-report-pop>summary::-webkit-details-marker{display:none}.pf-report-pop>div{position:absolute;right:0;z-index:30;margin-top:.25rem;min-width:16rem;max-height:70vh;overflow:auto;padding:.75rem;border:1px solid rgba(127,127,127,.35);border-radius:.5rem;background:var(--pf-surface,#fff);color:inherit;box-shadow:0 8px 24px rgba(0,0,0,.15);display:flex;flex-direction:column;gap:.5rem}.pf-report-menu>div a,.pf-report-menu>div button{text-align:left;justify-content:flex-start}.pf-report-count{display:inline-block;min-width:1.25rem;margin-left:.25rem;padding:0 .35rem;border-radius:999px;background:currentColor;font-size:.75rem;line-height:1.25rem;text-align:center}.pf-report-count>span{color:var(--pf-surface,#fff)}.pf-report-chips{display:flex;flex-wrap:wrap;gap:.35rem;margin:0 0 .5rem}.pf-report-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.1rem .6rem;border:1px solid rgba(127,127,127,.4);border-radius:999px;font-size:.85rem;text-decoration:none;color:inherit}.pf-report-chip:hover{background:rgba(127,127,127,.12)}@media print{.pf-report-page .pf-no-print,[data-pf-widget-tools]{display:none!important}}</style>
<div class="pf-ui pf-report-page">
    <div class="pf-report-head">
        <div>
            <h1 class="pf-page-title">{{ $this->entry['name'] }}</h1>
            <div class="pf-report-period">
                <a class="pf-btn pf-btn-ghost pf-btn-sm pf-no-print" href="{{ $this->urls['show'] . $query($this->previous) }}" aria-label="{{ t('Previous period') }}">‹</a>
                <span class="pf-page-sub">{{ $this->period }}</span>
                <a class="pf-btn pf-btn-ghost pf-btn-sm pf-no-print" href="{{ $this->urls['show'] . $query($this->next) }}" aria-label="{{ t('Next period') }}">›</a>
            </div>
        </div>
        <div class="pf-report-tools pf-no-print">
            <form method="get" action="{{ $this->urls['show'] }}" class="pf-report-tools">
                <input type="hidden" name="report" value="{{ $this->entry['id'] }}">
                @foreach ($pickers as $dimension => $picker)
                @if (isset($pinned[$dimension]))
                <label class="pf-report-field"><span>{{ $picker['label'] }}</span>
                    @if (!empty($picker['search']))
                    <input type="search" name="v[{{ $dimension }}]" list="pf-dimension-{{ $dimension }}" value="{{ $variables[$dimension] ?? '' }}" class="pf-input" placeholder="{{ t('All') }}">
                    <datalist id="pf-dimension-{{ $dimension }}">@foreach ($picker['values'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</datalist>
                    @else
                    <select name="v[{{ $dimension }}]" class="pf-select" data-pf-autosubmit>
                        <option value="">{{ t('All') }}</option>
                        @foreach ($picker['values'] as $value => $label)<option value="{{ $value }}"{{ ($variables[$dimension] ?? '') === (string) $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach
                    </select>
                    @endif
                </label>
                @endif
                @endforeach
                <label class="pf-report-field"><span>{{ t('Period') }}</span><input type="date" name="at" value="{{ $this->at }}" class="pf-input" data-pf-autosubmit></label>
                @if ($folded !== [])
                <details class="pf-report-pop pf-report-filters">
                    <summary class="pf-btn pf-btn-sm">{{ t('Filters') }}@if ($active > 0)<span class="pf-report-count"><span>{!! (int) $active !!}</span></span>@endif</summary>
                    <div>
                        @foreach ($folded as $dimension => $picker)
                        <label class="pf-report-field"><span>{{ $picker['label'] }}</span>
                            @if (!empty($picker['search']))
                            <input type="search" name="v[{{ $dimension }}]" list="pf-dimension-{{ $dimension }}" value="{{ $variables[$dimension] ?? '' }}" class="pf-input" placeholder="{{ t('All') }}">
                            <datalist id="pf-dimension-{{ $dimension }}">@foreach ($picker['values'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</datalist>
                            @else
                            <select name="v[{{ $dimension }}]" class="pf-select">
                                <option value="">{{ t('All') }}</option>
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
                    @else
                    <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?copy=' . rawurlencode($this->entry['id']) }}">{{ t('Copy') }}</a>
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
