{{-- One report for a period. Variables: entry, at, period, previous, next, body (Report::html()), mine, urls. --}}
@php
$query = fn (string $at): string => '?report=' . rawurlencode($this->entry['id']) . '&at=' . $at;
@endphp
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
                <input type="date" name="at" value="{{ $this->at }}" class="pf-input" aria-label="{{ t('Period') }}">
                <button type="submit" class="pf-btn pf-btn-sm">{{ t('Show') }}</button>
            </form>
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['show'] . $query($this->next) }}" aria-label="{{ t('Next period') }}">›</a>
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['csv'] . $query($this->at) }}">CSV</a>
            <button type="button" class="pf-btn pf-btn-ghost pf-btn-sm" data-pf-print>{{ t('Print or save as PDF') }}</button>
            @if ($this->mine)
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['edit'] . '?report=' . rawurlencode($this->entry['id']) }}">{{ t('Edit') }}</a>
            @endif
            <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['list'] }}">{{ t('All reports') }}</a>
        </div>
    </div>
    {!! $this->body !!}
</div>
