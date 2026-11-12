{{-- The to-do list. Variables: mode (mine, everyone, team, calendar), me, groups (overdue, today, week, later, none, system, closed — each a list of items), people (id => name, who may be given items), names (id => name), team (id => open, overdue, done), writes, zone, csrfField, urls. --}}
@php
$titles = ['overdue' => t('Overdue'), 'today' => t('Today'), 'week' => t('Next 7 days'), 'later' => t('Later'), 'none' => t('No date'),
    'system' => t('Waiting in the system'), 'closed' => t('Closed this week')];
$statuses = ['open' => t('Open'), 'in_progress' => t('In progress'), 'done' => t('Done'), 'dismissed' => t('Hidden')];
$tabs = ['mine' => t('Mine'), 'everyone' => t('Everyone'), 'team' => t('Team'), 'calendar' => t('Calendar')];
$day = fn (?int $at): string => $at === null ? '' : (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->zone))->format('Y-m-d');
$moment = fn (?int $at): string => $at === null ? '' : (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->zone))->format('Y-m-d\TH:i');
@endphp
<div class="pf-ui" data-pf-todos data-change="{{ $this->urls['change'] }}" data-token="{{ \Pramnos\Http\Middleware\CsrfMiddleware::token() }}">
    <div class="pf-page-head"><h1 class="pf-page-title">{{ t('To-dos') }}</h1></div>
    <nav class="pf-tabs" aria-label="{{ t('To-dos') }}">
        @foreach ($tabs as $key => $label)
        <a class="pf-tab{{ $this->mode === $key ? ' pf-tab-active' : '' }}" href="{{ $this->urls['list'] . ($key === 'mine' ? '' : '?view=' . $key) }}"{!! $this->mode === $key ? ' aria-current="page"' : '' !!}>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($this->writes && $this->mode !== 'team')
    <form method="post" action="{{ $this->urls['add'] }}" class="pf-card"><div class="pf-card-body pf-stack">{!! $this->csrfField !!}
        <input type="hidden" name="view" value="{{ $this->mode }}">
        <div class="pf-field"><label class="pf-label" for="pf-todo-title">{{ t('What is to be done') }} <span class="pf-muted">(N)</span></label>
            <input id="pf-todo-title" type="text" name="title" maxlength="4000" class="pf-input" required placeholder="{{ t('A line; its web address becomes the link') }}" data-pf-todo-new></div>
        <div class="pf-grid-2">
            <div class="pf-field"><label class="pf-label" for="pf-todo-who">{{ t('For') }}</label>
                <select id="pf-todo-who" name="assigned_to" class="pf-select"><option value="{{ $this->me }}">{{ t('Me') }}</option><option value="0">{{ t('Nobody in particular') }}</option>
                @foreach ($this->people as $id => $name)@if ($id !== $this->me)<option value="{{ $id }}">{{ $name }}</option>@endif @endforeach</select></div>
            <div class="pf-field"><label class="pf-label" for="pf-todo-due">{{ t('Day') }}</label>
                <input id="pf-todo-due" type="date" name="due" class="pf-input"></div>
            <div class="pf-field"><label class="pf-label" for="pf-todo-remind">{{ t('Remind me') }}</label>
                <input id="pf-todo-remind" type="datetime-local" name="remind" class="pf-input"></div>
            <div class="pf-field"><label class="pf-label" for="pf-todo-repeat">{{ t('Repeat') }}</label>
                <select id="pf-todo-repeat" name="repeat" class="pf-input"><option value="">{{ t('Once') }}</option>@foreach (['daily' => t('Every day'), 'weekdays' => t('Every weekday'), 'weekly' => t('Every week'), 'monthly' => t('Every month'), 'yearly' => t('Every year')] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
            <div class="pf-field"><label class="pf-check"><input type="checkbox" name="private" value="1"> {{ t('Private: only I see it') }}</label></div>
        </div>
        <div><button type="submit" class="pf-btn pf-btn-primary">{{ t('Add') }}</button></div>
    </div></form>
    @endif

    @if ($this->mode === 'team')
    @php $most = max([1, ...array_map(static fn (array $c): int => $c['open'] + $c['done'], $this->team)]); @endphp
    @if (empty($this->team))
    <p class="pf-muted">{{ t('Nothing to do.') }}</p>
    @else
    <table class="pf-table">
        <thead><tr><th>{{ t('Person') }}</th><th class="pf-num">{{ t('Open') }}</th><th class="pf-num">{{ t('Overdue') }}</th><th class="pf-num">{{ t('Done this week') }}</th><th></th></tr></thead>
        <tbody>
        @foreach ($this->team as $who => $count)
        <tr>
            <td>{{ $who === 0 ? t('Nobody in particular') : ($this->names[$who] ?? '#' . $who) }}</td>
            <td class="pf-num">{{ $count['open'] }}</td>
            <td class="pf-num{{ $count['overdue'] > 0 ? ' pf-tone-bad' : '' }}">{{ $count['overdue'] }}</td>
            <td class="pf-num">{{ $count['done'] }}</td>
            <td style="width:40%" aria-hidden="true"><div class="pf-todo-bar"><span class="pf-todo-bar-done" style="width:{{ round(100 * $count['done'] / $most) }}%"></span><span class="pf-todo-bar-open" style="width:{{ round(100 * ($count['open'] - $count['overdue']) / $most) }}%"></span><span class="pf-todo-bar-overdue" style="width:{{ round(100 * $count['overdue'] / $most) }}%"></span></div></td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @endif
    @elseif ($this->mode === 'calendar')
    <script src="{{ assetUrl('assets/vendor/fullcalendar/6.1.15/index.global.min.js') }}" defer></script>
    <script src="{{ assetUrl('assets/vendor/fullcalendar/6.1.15/locales-all.global.min.js') }}" defer></script>
    <script src="{{ assetUrl('assets/js/pf-calendar.js') }}" defer></script>
    <div data-pf-calendar data-events="{{ $this->urls['events'] }}" data-update="{{ $this->urls['move'] }}" data-editable="{{ $this->writes ? '1' : '0' }}" data-view="dayGridMonth"></div>
    @else
    @php $any = false; @endphp
    @foreach ($titles as $group => $title)
    @if (!empty($this->groups[$group]))
    @php $any = true; @endphp
    <section class="pf-card pf-todo-group" data-group="{{ $group }}"><div class="pf-card-body">
        <h2 class="pf-section-title">{{ $title }} <span class="pf-muted">{{ count($this->groups[$group]) }}</span></h2>
        <ul class="pf-todo-list">
        @foreach ($this->groups[$group] as $item)
        @php $closed = in_array($item['status'], ['done', 'dismissed'], true); @endphp
        <li class="pf-todo{{ $closed ? ' pf-todo-closed' : '' }}{{ $group === 'overdue' ? ' pf-todo-overdue' : '' }}" data-id="{{ $item['id'] }}">
            <input type="checkbox" class="pf-todo-done" aria-label="{{ t('Done') }}"{{ $item['status'] === 'done' ? ' checked' : '' }}{{ $this->writes ? '' : ' disabled' }} data-field="status">
            <div class="pf-todo-main">
                @if ($this->writes && $item['kind'] === 'own' && $item['created_by'] === $this->me)
                <span class="pf-todo-title" contenteditable="plaintext-only" data-field="title" role="textbox" aria-label="{{ t('What is to be done') }}">{{ $item['title'] }}</span>
                @else
                <span class="pf-todo-title">{{ $item['title'] }}</span>
                @endif
                @if ($item['link'] !== null) <a class="pf-todo-link" href="{{ $item['link'] }}">{{ t('Open') }} ↗</a>@endif
                <div class="pf-todo-chips">
                    @if ($item['private'])<span class="pf-badge pf-tone-muted">{{ t('Private') }}</span>@endif
                    @if ($item['kind'] !== 'own')<span class="pf-badge pf-tone-info">{{ $item['kind'] }}</span>@endif
                    @if ($item['status'] === 'in_progress')<span class="pf-badge pf-tone-warn">{{ t('In progress') }}</span>@endif
                    @if ($this->writes && !$item['private'])
                    <select class="pf-select pf-select-sm pf-todo-chip" data-field="assigned_to" aria-label="{{ t('For') }}">
                        <option value="0">{{ t('Nobody in particular') }}</option>
                        @foreach ($this->people + ($item['assigned_to'] !== null ? [$item['assigned_to'] => $this->names[$item['assigned_to']] ?? '#' . $item['assigned_to']] : []) as $id => $name)
                        <option value="{{ $id }}"{{ $item['assigned_to'] === $id ? ' selected' : '' }}>{{ $id === $this->me ? t('Me') : $name }}</option>
                        @endforeach
                    </select>
                    <input type="date" class="pf-input pf-input-sm pf-todo-chip" data-field="due" value="{{ $day($item['due_at']) }}" aria-label="{{ t('Day') }}">
                    @else
                    @if ($item['assigned_to'] !== null)<span class="pf-muted">@if (isset($this->photos[$item['assigned_to']]))<img src="{{ $this->photos[$item['assigned_to']] }}" alt="" width="20" height="20" style="border-radius:50%;vertical-align:middle;object-fit:cover"> @endif{{ $this->names[$item['assigned_to']] ?? '#' . $item['assigned_to'] }}</span>@endif
                    @if ($item['due_at'] !== null)<span class="pf-muted">{{ $day($item['due_at']) }}</span>@endif
                    @endif
                    @if ($item['remind_at'] !== null)<span class="pf-muted" title="{{ t('Reminder') }}">⏰ {{ str_replace('T', ' ', $moment($item['remind_at'])) }}</span>@endif
                </div>
            </div>
            @if ($this->writes)
            <details class="pf-todo-menu">
                <summary aria-label="{{ t('More') }}">⋯</summary>
                <div class="pf-todo-menu-body">
                    @foreach ($statuses as $value => $label)@if ($value !== $item['status'])<button type="button" class="pf-btn pf-btn-ghost pf-btn-sm" data-set="status" data-value="{{ $value }}">{{ $label }}</button>@endif @endforeach
                    <label class="pf-label">{{ t('Remind me') }} <input type="datetime-local" class="pf-input pf-input-sm" data-field="remind" value="{{ $moment($item['remind_at']) }}"></label>
                    @if ($item['created_by'] === $this->me)<button type="button" class="pf-btn pf-btn-ghost pf-btn-sm" data-set="private" data-value="{{ $item['private'] ? '0' : '1' }}">{{ $item['private'] ? t('Make it public') : t('Make it private') }}</button>@endif
                    @if ($item['kind'] === 'own' && $item['created_by'] === $this->me)
                    <form method="post" action="{{ $this->urls['delete'] }}" data-confirm="{{ t('Delete this to-do?') }}">{!! $this->csrfField !!}<input type="hidden" name="id" value="{{ $item['id'] }}"><button type="submit" class="pf-btn pf-btn-danger pf-btn-sm">{{ t('Delete') }}</button></form>
                    @endif
                </div>
            </details>
            @endif
        </li>
        @endforeach
        </ul>
    </div></section>
    @endif
    @endforeach
    @if (!$any)<p class="pf-muted">{{ t('Nothing to do.') }}</p>@endif
    @endif
</div>
<script nonce="{{ defined('PAGE_NONCE') ? PAGE_NONCE : '' }}">
(() => {
    // Changes in place: each control posts its field, the row is drawn again by the next visit.
    const root = document.querySelector('[data-pf-todos]');
    if (!root) return;
    const send = async (row, field, value) => {
        const body = new FormData();
        body.append('id', row.dataset.id);
        body.append(field, value);
        try {
            const answer = await (await fetch(root.dataset.change, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-Token': root.dataset.token, Accept: 'application/json' } })).json();
            row.classList.toggle('pf-todo-error', !!answer.error);
            row.title = answer.error || '';
            if (!answer.error && (field === 'status' || field === 'private')) {
                row.classList.toggle('pf-todo-closed', ['done', 'dismissed'].includes(answer.todo.status));
            }
        } catch (e) {
            row.classList.add('pf-todo-error');
        }
    };
    root.addEventListener('change', (e) => {
        const row = e.target.closest('.pf-todo');
        const field = e.target.dataset.field;
        if (!row || !field) return;
        send(row, field, field === 'status' ? (e.target.checked ? 'done' : 'open') : e.target.value);
    });
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-set]');
        const row = button && button.closest('.pf-todo');
        if (!row) return;
        send(row, button.dataset.set, button.dataset.value);
        button.closest('details')?.removeAttribute('open');
    });
    // A title edited in place: Enter keeps it, Escape puts it back.
    root.addEventListener('keydown', (e) => {
        const title = e.target.closest('[data-field="title"]');
        if (title && e.key === 'Enter') { e.preventDefault(); title.blur(); }
        if (title && e.key === 'Escape') { title.textContent = title.dataset.was ?? title.textContent; title.blur(); }
    });
    root.addEventListener('focusin', (e) => { const t = e.target.closest('[data-field="title"]'); if (t) t.dataset.was = t.textContent; });
    root.addEventListener('focusout', (e) => {
        const title = e.target.closest('[data-field="title"]');
        if (title && title.textContent.trim() !== '' && title.textContent !== title.dataset.was) send(title.closest('.pf-todo'), 'title', title.textContent.trim());
    });
    // N: a new to-do, from anywhere not typing.
    document.addEventListener('keydown', (e) => {
        if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.altKey && !e.target.closest('input, textarea, select, [contenteditable]')) {
            const field = root.querySelector('[data-pf-todo-new]');
            if (field) { e.preventDefault(); field.focus(); }
        }
        if (e.key === 'Escape') root.querySelectorAll('details[open]').forEach((d) => d.removeAttribute('open'));
    });
})();
</script>
