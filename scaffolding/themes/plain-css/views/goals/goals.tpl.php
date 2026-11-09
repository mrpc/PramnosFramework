{{-- The goals, the form to add or change one, and what was reached. Variables: goals (each goal, label, judged), editing (a goal, or null), metrics (name, label, level), kinds (total, level), achievements, csrfField, urls. --}}
@php
$states = ['met' => ['pf-tone-ok', t('Met')], 'on_track' => ['pf-tone-info', t('On track')], 'behind' => ['pf-tone-bad', t('Behind')], 'stalled' => ['pf-tone-warn', t('Stalled')], 'unknown' => ['pf-tone-muted', t('Not known yet')]];
$kindNames = ['count' => t('A total to reach'), 'growth' => t('Growth, in %'), 'reach' => t('A level to reach'), 'sustain' => t('A level to keep'), 'pace' => t('A change since the start')];
$repeats = ['' => t('Once, by a date'), 'day' => t('Every day'), 'week' => t('Every week'), 'month' => t('Every month'), 'quarter' => t('Every quarter')];
$goal = $this->editing;
@endphp
<div class="pf-ui">
    <div class="pf-page-head"><h1 class="pf-page-title">{{ t('Goals') }}</h1></div>
    @if (empty($this->goals))
    <p class="pf-muted">{{ t('No goals yet.') }}</p>
    @else
    <table class="pf-table">
        <thead><tr><th>{{ t('Metric') }}</th><th>{{ t('State') }}</th><th>{{ t('How it stands') }}</th><th>{{ t('Before') }}</th><th></th></tr></thead>
        <tbody>
        @foreach ($this->goals as $row)
        @php $state = $states[$row['judged']['state'] ?? 'unknown'] ?? $states['unknown']; @endphp
        <tr data-pf-goal="{{ $row['goal']['id'] }}">
            <td>{{ t($row['label']) }}@if ($row['goal']['note'] !== '')<br><span class="pf-muted">{{ $row['goal']['note'] }}</span>@endif</td>
            <td><span class="pf-badge {{ $state[0] }}">{{ $state[1] }}</span></td>
            <td>{{ $row['judged']['says'] ?? '' }}</td>
            <td>
                @foreach ($row['judged']['history'] ?? [] as $past)
                <span title="{{ $past['from'] }}" class="{{ $past['met'] === true ? 'pf-tone-ok' : ($past['met'] === false ? 'pf-tone-bad' : 'pf-tone-muted') }}">{{ $past['met'] === null ? '·' : '●' }}</span>
                @endforeach
                @if (($row['judged']['streak'] ?? 0) > 1)<span class="pf-muted">{{ t('%d in a row', $row['judged']['streak']) }}</span>@endif
            </td>
            <td class="pf-num">
                <a class="pf-btn pf-btn-ghost pf-btn-sm" href="{{ $this->urls['list'] . '?goal=' . $row['goal']['id'] }}">{{ t('Edit') }}</a>
                <form method="post" action="{{ $this->urls['delete'] }}" style="display:inline" data-confirm="{{ t('Delete this goal?') }}">{!! $this->csrfField !!}
                    <input type="hidden" name="goalid" value="{{ $row['goal']['id'] }}">
                    <button type="submit" class="pf-btn pf-btn-danger pf-btn-sm">{{ t('Delete') }}</button>
                </form>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <form method="post" action="{{ $this->urls['save'] }}" class="pf-card" data-pf-goal-form data-preview="{{ $this->urls['preview'] }}"><div class="pf-card-body pf-stack">{!! $this->csrfField !!}
        <h2 class="pf-section-title">{{ $goal === null ? t('Add a goal') : t('Change a goal') }}</h2>
        <input type="hidden" name="goalid" value="{{ $goal['id'] ?? 0 }}">
        <div class="pf-grid-2">
            <div class="pf-field"><label class="pf-label" for="pf-goal-metric">{{ t('Metric') }}</label>
                <select id="pf-goal-metric" name="metric" class="pf-select"{{ $goal !== null ? ' disabled' : '' }}>@foreach ($this->metrics as $m)<option value="{{ $m['name'] }}" data-level="{{ $m['level'] ? '1' : '0' }}"{{ ($goal['metric'] ?? '') === $m['name'] ? ' selected' : '' }}>{{ t($m['label']) }}</option>@endforeach</select>
                @if ($goal !== null)<input type="hidden" name="metric" value="{{ $goal['metric'] }}">@endif</div>
            <div class="pf-field"><label class="pf-label" for="pf-goal-kind">{{ t('Kind') }}</label>
                <select id="pf-goal-kind" name="kind" class="pf-select"><option value="">{{ t('The usual for the metric') }}</option>@foreach ($kindNames as $value => $label)<option value="{{ $value }}"{{ ($goal['kind'] ?? '') === $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-goal-target">{{ t('Target') }}</label>
                <input id="pf-goal-target" type="number" step="any" name="target" value="{{ $goal !== null ? $goal['target'] : '' }}" class="pf-input" required></div>
            <div class="pf-field"><label class="pf-label" for="pf-goal-repeats">{{ t('When') }}</label>
                <select id="pf-goal-repeats" name="repeats" class="pf-select">@foreach ($repeats as $value => $label)<option value="{{ $value }}"{{ ($goal === null ? 'month' : ($goal['grain'] === 'once' ? '' : $goal['grain'])) === $value ? ' selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-goal-starts">{{ t('From') }}</label>
                <input id="pf-goal-starts" type="date" name="starts" value="{{ isset($goal['starts_at']) ? date('Y-m-d', $goal['starts_at']) : '' }}" class="pf-input"></div>
            <div class="pf-field"><label class="pf-label" for="pf-goal-due">{{ t('By') }}</label>
                <input id="pf-goal-due" type="date" name="due" value="{{ isset($goal['due_at']) ? date('Y-m-d', $goal['due_at']) : '' }}" class="pf-input"></div>
        </div>
        <div class="pf-field"><label class="pf-label" for="pf-goal-note">{{ t('Note') }}</label>
            <input id="pf-goal-note" type="text" name="note" maxlength="255" value="{{ $goal['note'] ?? '' }}" class="pf-input"></div>
        <p class="pf-hint" data-pf-goal-preview aria-live="polite"></p>
        <div><button type="submit" class="pf-btn pf-btn-primary">{{ t('Save') }}</button>@if ($goal !== null) <a class="pf-btn pf-btn-ghost" href="{{ $this->urls['list'] }}">{{ t('Cancel') }}</a>@endif</div>
    </div></form>

    @if (!empty($this->achievements))
    <div class="pf-card"><div class="pf-card-body">
        <h2 class="pf-section-title">{{ t('Reached') }}</h2>
        <ul class="pf-stack" style="list-style:none;padding:0;margin:0">
        @foreach ($this->achievements as $done)
            <li><span class="pf-muted">{{ $done['achieved_at'] }}</span> · {{ $done['says'] }}</li>
        @endforeach
        </ul>
    </div></div>
    @endif
</div>
<script nonce="{{ defined('PAGE_NONCE') ? PAGE_NONCE : '' }}">
(() => {
    // The sentence the goal would have, as it is typed, and what to try: the last whole period's figure.
    const form = document.querySelector('[data-pf-goal-form]');
    const line = form && form.querySelector('[data-pf-goal-preview]');
    if (!line) return;
    const words = {!! json_encode(['last' => t('Last period: %s.'), 'try' => t('Try %s'), 'once' => t('Now: %s.')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
    let timer = null;
    const ask = async () => {
        const body = new FormData(form);
        body.delete('goalid');
        try {
            const answer = await (await fetch(form.dataset.preview, { method: 'POST', body, credentials: 'same-origin' })).json();
            line.textContent = answer.error || answer.says || '';
            if (answer.last !== null && answer.last !== undefined) {
                line.append(' ' + (answer.history ? words.last : words.once).replace('%s', answer.last));
            }
            if (answer.try) {
                const fill = document.createElement('button');
                fill.type = 'button';
                fill.className = 'pf-btn pf-btn-ghost pf-btn-sm';
                fill.textContent = words.try.replace('%s', answer.try);
                fill.addEventListener('click', () => { form.elements.target.value = answer.try; ask(); });
                line.append(' ', fill);
            }
        } catch (e) {
            line.textContent = '';
        }
    };
    form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(ask, 300); });
    ask();
})();
</script>
