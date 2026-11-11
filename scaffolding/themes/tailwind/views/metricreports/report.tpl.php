{{-- One report for a period. Variables: entry, at, period, previous, next, body (the report's cards, Board::render()), mine, urls. --}}
@php
$keep  = ($this->variables ?? []) !== [] ? '&' . http_build_query(['v' => $this->variables]) : '';
$query = fn (string $at): string => '?report=' . rawurlencode($this->entry['id']) . '&at=' . $at . $keep;
@endphp
<style data-pf-report>.pf-report-page .pf-dashboard{gap:.5rem}.pf-report-page [data-pf-widget]>.card-body{padding:.5rem .75rem!important;gap:.35rem!important}.pf-report-page .pf-dashboard-head{margin-bottom:.25rem!important}.pf-report-page .pf-report th,.pf-report-page .pf-report td{padding:.15rem .5rem!important;line-height:1.35}.pf-report-page .pf-report{margin-bottom:0}[data-pf-widget-tools][hidden],[data-pf-widget-size-handle][hidden]{display:none!important}.pf-report-section-card{padding:.75rem 0 .15rem;border-bottom:1px solid rgba(127,127,127,.35)}.pf-report-section-card .pf-dashboard-head{margin:0!important}.pf-report-section-toggle{display:flex;align-items:center;gap:.4rem;width:100%;padding:0;border:0;background:none;color:inherit;font:inherit;font-size:1.05rem;font-weight:600;text-align:left;cursor:pointer}.pf-report-section-toggle::before{content:"\25BE";display:inline-block;transition:transform .15s}.pf-report-section-toggle[aria-expanded=false]::before{transform:rotate(-90deg)}@media print{.pf-report-page .pf-no-print,[data-pf-widget-tools]{display:none!important}}</style>
<div class="pf-ui pf-report-page">
    <div class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ $this->entry['name'] }}</h1>
            <p class="pf-page-sub">{{ $this->period }}</p>
        </div>
        <div class="pf-page-actions pf-no-print">
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['show'] . $query($this->previous) }}" aria-label="{{ t('Previous period') }}">‹</a>
            <form method="get" action="{{ $this->urls['show'] }}" style="display:inline-flex;gap:.25rem">
                <input type="hidden" name="report" value="{{ $this->entry['id'] }}">
                @foreach (($this->pickers ?? []) as $dimension => $picker)
                @if (!empty($picker['search']))
                {{-- A long list: a search field over it, the browser's own. --}}
                <input type="search" name="v[{{ $dimension }}]" list="pf-dimension-{{ $dimension }}" value="{{ $this->variables[$dimension] ?? '' }}" class="pf-input" placeholder="{{ $picker['label'] }}: {{ t('All') }}" aria-label="{{ $picker['label'] }}">
                <datalist id="pf-dimension-{{ $dimension }}">@foreach ($picker['values'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</datalist>
                @else
                <select name="v[{{ $dimension }}]" class="pf-select" aria-label="{{ $picker['label'] }}" data-pf-autosubmit>
                    <option value="">{{ $picker['label'] }}: {{ t('All') }}</option>
                    @foreach ($picker['values'] as $value => $label)<option value="{{ $value }}"{{ ($this->variables[$dimension] ?? '') === (string) $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach
                </select>
                @endif
                @endforeach
                <input type="date" name="at" value="{{ $this->at }}" class="pf-input" aria-label="{{ t('Period') }}">
                <button type="submit" class="pf-btn pf-btn-sm">{{ t('Show') }}</button>
            </form>
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['show'] . $query($this->next) }}" aria-label="{{ t('Next period') }}">›</a>
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['csv'] . $query($this->at) }}">CSV</a>
            @if (isset($this->urls['xlsx']))<a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['xlsx'] . $query($this->at) }}">XLSX</a>@endif
            <button type="button" class="pf-btn pf-btn-ghost pf-btn-sm" data-pf-print>{{ t('Print or save as PDF') }}</button>
            @if ($this->mine)
            <button type="button" class="pf-btn pf-btn-ghost pf-btn-sm" data-pf-dashboard-edit{!! $this->customise ? " data-pf-dashboard-open" : "" !!} aria-pressed="false">{{ t('Customise') }}</button>
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?report=' . rawurlencode($this->entry['id']) }}">{{ t('Settings') }}</a>
            @else
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?copy=' . rawurlencode($this->entry['id']) }}">{{ t('Copy') }}</a>
            @endif
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['list'] }}">{{ t('All reports') }}</a>
        </div>
    </div>
    {!! $this->body !!}
</div>
