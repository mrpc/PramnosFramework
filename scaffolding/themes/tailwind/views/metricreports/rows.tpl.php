{{-- The records behind a number. Variables: metric, period, filters, count, rows, urls. --}}
@php
$columns = [];
foreach ($this->rows as $one) {
    $columns += array_flip(array_keys($one));
}
$columns = array_keys($columns);
@endphp
<div class="pf-ui">
    <div class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ t($this->metric->label()) }}</h1>
            <p class="pf-page-sub">{{ $this->period }}@foreach ($this->filters as $name => $value) · {{ \Pramnos\Metrics\Metrics::dimensionOf($name)->label }}: {{ \Pramnos\Metrics\Metrics::dimensionOf($name)->labelOf($value) }}@endforeach</p>
        </div>
        <div class="pf-page-actions"><a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['list'] }}">{{ t('All reports') }}</a></div>
    </div>
    <p>{{ sprintf(t('%d records'), $this->count) }}@if ($this->count > count($this->rows)) · {{ sprintf(t('the first %d shown'), count($this->rows)) }}@endif</p>
    @if (!empty($this->rows))
    <div class="pf-report-scroll"><table class="pf-table">
        <thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($this->rows as $one)
        <tr>@foreach ($columns as $column)<td>{{ is_scalar($one[$column] ?? null) ? $one[$column] : json_encode($one[$column] ?? null) }}</td>@endforeach</tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
