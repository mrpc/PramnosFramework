{{-- A report's form. Variables: id, reportName, definition, columns and sectionsText (as text; empty for a report with cards), copyOf, row, metrics, roles, csrfField, urls. The cards are arranged on the report itself, with Customise. --}}
@php
$saved = $this->row ?? [];
$grains = ['day' => t('Day'), 'week' => t('Week'), 'month' => t('Month'), 'quarter' => t('Quarter'), 'year' => t('Year')];
$compares = ['' => t('Nothing'), 'previous' => t('The period before'), 'year' => t('A year earlier')];
$shown = ['private' => t('Only me'), 'role' => t('From a user type up'), 'everyone' => t('Everyone')];
$schedules = ['' => t('Never'), 'day' => t('Daily'), 'week' => t('Weekly'), 'month' => t('Monthly')];
@endphp
<div class="pf-ui">
    <div class="pf-page-head"><h1 class="pf-page-title">{{ $this->id > 0 ? t('Edit a report') : t('New report') }}</h1></div>
    <form method="post" action="{{ $this->urls['save'] }}" class="pf-card"><div class="pf-card-body pf-stack">{!! $this->csrfField !!}
        <input type="hidden" name="reportid" value="{{ $this->id }}"><input type="hidden" name="copyof" value="{{ $this->copyOf ?? '' }}">
        <div class="pf-field"><label class="pf-label" for="pf-report-name">{{ t('Name') }}</label>
            <input id="pf-report-name" type="text" name="name" value="{{ $this->reportName }}" maxlength="120" required class="pf-input"></div>
        <div class="pf-grid-2">
            <div class="pf-field"><label class="pf-label" for="pf-report-grain">{{ t('Period') }}</label>
                <select id="pf-report-grain" name="grain" class="pf-select">@foreach ($grains as $value => $label)<option value="{{ $value }}"{{ $this->definition['grain'] === $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-report-compare">{{ t('Compare with') }}</label>
                <select id="pf-report-compare" name="compare" class="pf-select">@foreach ($compares as $value => $label)<option value="{{ $value }}"{{ (string) $this->definition['compare'] === $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
        </div>
        {{-- Cards are added on the report itself, with Customise; the text is for the advanced. --}}
        <details class="pf-field"{!! trim((string) $this->sectionsText) !== '' ? ' open' : '' !!}><summary class="pf-label">{{ t('For the advanced') }}</summary>
            <div class="pf-field"><label class="pf-label" for="pf-report-columns">{{ t('Columns of its tables, unless a table sets its own') }}</label>
                <textarea id="pf-report-columns" name="columns" rows="4" class="pf-textarea" style="font-family:monospace">{{ $this->columns }}</textarea>
                <p class="pf-hint">{{ t('periods=12 — or dimension=name value,value total — or one column a line: Label | name=value, … | metric | offset') }}</p></div>
                <div class="pf-field"><label class="pf-label" for="pf-report-sections">{{ t('The report as text, in place of its cards') }}</label>
                <textarea id="pf-report-sections" name="sections" rows="14" class="pf-textarea" style="font-family:monospace">{{ $this->sectionsText }}</textarea>
                <p class="pf-hint">{{ t('# Title [chart=line] [total] starts a section; each row: Label | metric | name=value, … | by=dimension total') }}</p></div>
            <p class="pf-label">{{ t('Metrics') }}</p>
            <ul class="pf-stack" style="list-style:none;padding:0;margin:0">
        @foreach ($this->metrics as $metric)
            <li><code>{{ $metric->name }}</code> · {{ t($metric->label()) }}@if ($metric->getDimensions() !== []) <span class="pf-muted">({{ implode(', ', $metric->getDimensions()) }})</span>@endif</li>
        @endforeach
        </ul>
        </details>
        <div class="pf-grid-2">
            <div class="pf-field"><label class="pf-label" for="pf-report-visibility">{{ t('Who sees it') }}</label>
                <select id="pf-report-visibility" name="visibility" class="pf-select">@foreach ($shown as $value => $label)<option value="{{ $value }}"{{ ($saved['visibility'] ?? 'private') === $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-report-audience">{{ t('User type') }}</label>
                <select id="pf-report-audience" name="audience" class="pf-select"><option value=""></option>@foreach ((array) $this->roles as $level => $label)<option value="{{ $level }}"{{ (string) ($saved['audience'] ?? '') === (string) $level ? ' selected' : '' }}>{{ t((string) $label) }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-report-schedule">{{ t('Mail it') }}</label>
                <select id="pf-report-schedule" name="schedule" class="pf-select">@foreach ($schedules as $value => $label)<option value="{{ $value }}"{{ ($saved['schedule'] ?? '') === $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-report-to">{{ t('To') }}</label>
                <input id="pf-report-to" type="text" name="recipients" value="{{ $saved['recipients'] ?? '' }}" class="pf-input" placeholder="name@example.com, …"></div>
        </div>
        <div><button type="submit" class="pf-btn pf-btn-primary">{{ t('Save') }}</button> <a class="pf-btn pf-btn-ghost" href="{{ $this->urls['list'] }}">{{ t('Cancel') }}</a></div>
    </div></form>
</div>
