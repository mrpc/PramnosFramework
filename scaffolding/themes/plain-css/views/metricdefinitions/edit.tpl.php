{{-- A metric's form, defined on the screen (BI phase 7i). Variables: id, row (saved row, or
checked data after "Check"), tables (NoCodeMetrics::allowedTables()), where, dimensions (as text
lines), cost (null, or NoCodeMetrics::cost()'s answer), csrfField, urls. --}}
@php
$saved = $this->row ?? [];
$functions = ['sum' => t('Sum'), 'count' => t('Count'), 'countDistinct' => t('Count, distinct'), 'avg' => t('Average'), 'min' => t('Minimum'), 'max' => t('Maximum')];
@endphp
<div class="pf-ui">
    <div class="pf-page-head"><h1 class="pf-page-title">{{ $this->id > 0 ? t('Edit a metric') : t('New metric') }}</h1></div>
    <div class="pf-card"><div class="pf-card-body">
        <p class="pf-label">{{ t('Allowed tables and columns') }}</p>
        @if (empty($this->tables))
        <p class="pf-muted">{{ t('No table is allowed here yet — an application lists them under metrics.no_code.tables.') }}</p>
        @else
        <ul class="pf-stack" style="list-style:none;padding:0;margin:0">
        @foreach ($this->tables as $table => $allowed)
            <li><code>{{ $table }}</code> — {{ t('time') }}: <code>{{ implode(', ', $allowed['time']) }}</code>, {{ t('columns') }}: <code>{{ implode(', ', $allowed['columns']) }}</code></li>
        @endforeach
        </ul>
        @endif
    </div></div>
    <form method="post" action="{{ $this->urls['save'] }}" class="pf-card"><div class="pf-card-body pf-stack">{!! $this->csrfField !!}
        <input type="hidden" name="id" value="{{ $this->id }}">
        <div class="pf-field"><label class="pf-label" for="pf-metric-name">{{ t('Name') }}</label>
            <input id="pf-metric-name" type="text" name="name" value="{{ $saved['name'] ?? '' }}" maxlength="100" required class="pf-input" placeholder="store.paid_orders"></div>
        <div class="pf-field"><label class="pf-label" for="pf-metric-label">{{ t('Label') }}</label>
            <input id="pf-metric-label" type="text" name="label" value="{{ $saved['label'] ?? '' }}" maxlength="200" required class="pf-input"></div>
        <div class="pf-grid-2">
            <div class="pf-field"><label class="pf-label" for="pf-metric-table">{{ t('Table') }}</label>
                <select id="pf-metric-table" name="source_table" class="pf-select">
                    <option value=""></option>
                @foreach (array_keys($this->tables) as $table)
                    <option value="{{ $table }}"{{ ($saved['source_table'] ?? '') === $table ? ' selected' : '' }}>{{ $table }}</option>
                @endforeach
                </select></div>
            <div class="pf-field"><label class="pf-label" for="pf-metric-time">{{ t('Time column') }}</label>
                <input id="pf-metric-time" type="text" name="time_column" value="{{ $saved['time_column'] ?? '' }}" class="pf-input"></div>
            <div class="pf-field"><label class="pf-label" for="pf-metric-fn">{{ t('Counts') }}</label>
                <select id="pf-metric-fn" name="fn" class="pf-select">@foreach ($functions as $value => $label)<option value="{{ $value }}"{{ ($saved['fn'] ?? '') === $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-metric-column">{{ t('Column') }}</label>
                <input id="pf-metric-column" type="text" name="value_column" value="{{ $saved['value_column'] ?? '' }}" class="pf-input">
                <p class="pf-hint">{{ t('Left empty, counts rows.') }}</p></div>
        </div>
        <div class="pf-field"><label class="pf-label" for="pf-metric-where">{{ t('Only where') }}</label>
            <textarea id="pf-metric-where" name="where" rows="3" class="pf-textarea" style="font-family:monospace" placeholder="status=paid">{{ $this->where }}</textarea>
            <p class="pf-hint">{{ t('column=value, one a line; column=a,b,c for one of several.') }}</p></div>
        <div class="pf-field"><label class="pf-label" for="pf-metric-dimensions">{{ t('Broken down by') }}</label>
            <textarea id="pf-metric-dimensions" name="dimensions" rows="3" class="pf-textarea" style="font-family:monospace" placeholder="region=region_column">{{ $this->dimensions }}</textarea>
            <p class="pf-hint">{{ t('name=column, one a line.') }}</p></div>
        <div class="pf-field"><label class="pf-label" for="pf-metric-currency">{{ t('Currency') }}</label>
            <input id="pf-metric-currency" type="text" name="currency" value="{{ $saved['currency'] ?? '' }}" maxlength="3" class="pf-input" style="width:6em" placeholder="EUR"></div>
        <div>
            <button type="submit" name="confirm" value="0" class="pf-btn">{{ t('Check') }}</button>
        @if (($this->cost ?? null) !== null)
            <button type="submit" name="confirm" value="1" class="pf-btn pf-btn-primary">{{ t('Confirm and save') }}</button>
        @endif
            <a class="pf-btn pf-btn-ghost" href="{{ $this->urls['list'] }}">{{ t('Cancel') }}</a>
        </div>
    </div></form>
@if (($this->cost ?? null) !== null)
    <div class="pf-card"><div class="pf-card-body">
        <h2 class="pf-section-title">{{ t('What it would cost') }}</h2>
        <p>{{ sprintf(t('About %d row(s) read for a day.'), $this->cost['rows']) }}
        @if ($this->cost['indexed'] === true) {{ t('An index is used.') }}
        @elseif ($this->cost['indexed'] === false) <strong>{{ t('No index is used — a full scan of the table.') }}</strong>
        @endif
        </p>
    </div></div>
@endif
</div>
