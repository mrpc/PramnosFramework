{{-- The records behind a number. Variables: metric, period, filters, count, rows, urls. --}}
@php
$columns = [];
foreach ($this->rows as $one) {
    $columns += array_flip(array_keys($one));
}
$columns = array_keys($columns);
@endphp
<div class="pf-ui">
    @if (!empty($this->trail))
    <nav class="pf-report-trail" aria-label="{{ t('Breadcrumb') }}" style="font-size:.9rem;margin-bottom:.25rem">@foreach ($this->trail as $i => $one)@if ($i > 0) <span aria-hidden="true">›</span> @endif@if ($one['url'] !== null)<a href="{{ $one['url'] }}">{{ $one['label'] }}</a>@else<span aria-current="page">{{ $one['label'] }}</span>@endif@endforeach</nav>
    @endif
    <div class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ t($this->metric->label()) }}</h1>
            <p class="pf-page-sub">{{ $this->period }}@foreach ($this->filters as $name => $value) · {{ \Pramnos\Metrics\Metrics::dimensionOf($name)->label }}: {{ \Pramnos\Metrics\Metrics::dimensionOf($name)->labelOf($value) }}@endforeach</p>
        </div>
        <div class="pf-page-actions">@php $back = array_values(array_filter(array_slice($this->trail ?? [], 1), static fn (array $one): bool => $one['url'] !== null))[0] ?? null; @endphp<a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $back['url'] ?? $this->urls['list'] }}">{{ $back !== null ? t('Back to the report') : t('All reports') }}</a></div>
    </div>
    <p>{{ sprintf(t('%d records'), $this->count) }}@if ($this->count > count($this->rows)) · {{ sprintf(t('the first %d shown'), count($this->rows)) }}@endif</p>
    @if (!empty($this->rows))
    <div class="pf-report-scroll"><table class="pf-table">
        <thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($this->rows as $one)
        <tr>@foreach ($columns as $column)@php $text = is_scalar($one[$column] ?? null) ? (string) $one[$column] : (string) json_encode($one[$column] ?? null); $href = \Pramnos\Metrics\Metrics::link((string) $column, $one[$column] ?? null, $one); @endphp<td>@if ($href !== null)<a href="{{ $href }}">{{ $text }}</a>@else{{ $text }}@endif</td>@endforeach</tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
