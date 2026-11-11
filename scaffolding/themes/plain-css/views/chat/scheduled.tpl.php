@php /**
 * Scheduled tasks (plain CSS theme) — `chat/scheduled`: this person's questions asked again, reminders
 * and reports; the reports shared with them to receive; a form to schedule one; and, with
 * `?task=`, one task's runs, each opened in Ask.
 *
 * Variables:
 *   $this->tasks     — this person's: routine_id, kind, title, prompt, cadence, at, day, nth, run_at, email (a report emailed to them),
 *                      timezone, active, next_run_local, last_error, owner
 *   $this->reports   — shared reports they may receive: the same, with subscribed and latest_run
 *   $this->task      — the task whose runs are open, or null
 *   $this->runs      — its runs, newest first: run_id, status, content, error, finished_at
 *   $this->templates — key => {title, prompt, cadence, at, day}
 *   $this->cadences, $this->kinds — what the form offers
 *   $this->owners    — whose a report may be, of user, organization, global, as the policy allows
 *   $this->timezone  — the clock times are read on
 *   $this->urls      — page, action (with %s for the action's name), report (a run's number
 *                      follows), chat — Ask AI's addresses when not given
 *   $this->audienceRoles — role => usertype, to choose a shared report's readers by
 *   $this->audienceUsers — the open task's named readers: userid => username
 *   $this->mayEdit   — whether the open task is this person's own, to choose its readers
 *   $this->extras    — the controller's own markup: `form`, inside the form before its button;
 *                      `task`, on the open task's card — {@see SchedulesTasks::scheduledExtras()}
 *
 * A run's content is the model's words, drawn by Markdown::toHtml(), which escapes everything first.
 */
$c     = ['page' => 'container', 'card' => 'card card-body', 'muted' => 'pf-muted', 'bad' => 'pf-bad', 'btn' => 'btn btn-primary btn-sm', 'btnGhost' => 'btn btn-outline-secondary btn-sm', 'danger' => 'btn btn-outline-secondary btn-sm', 'input' => 'form-control pf-ui-input-sm', 'select' => 'form-control pf-ui-input-sm', 'textarea' => 'form-control', 'badge' => 'badge', 'row' => 'pf-ui-row', 'field' => 'small', 'answer' => '', 'h1' => '', 'h2' => ''];
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t     = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$url   = static fn (string $path): string => sURL . $path;
// Where the page and its actions are: Ask AI's by default, the analyst's under the area.
$u     = (is_array($this->urls ?? null) ? $this->urls : []) + [
    'page' => $url('chat/scheduled'), 'action' => $url('chat/%s'), 'report' => $url('chat/report/'), 'chat' => $url('chat'),
];
$act   = static fn (string $action): string => str_replace('%s', $action, (string) $u['action']);
$days  = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$nths  = [1 => 'first', 'second', 'third', 'fourth', 5 => 'last'];
$kinds = ['ask' => 'Question', 'reminder' => 'Reminder', 'report' => 'Report'];
/** When a task runs, as a person reads it. */
$when  = static function (array $task) use ($t, $days, $nths): string {
    $at = (string) ($task['at'] ?? '');
    return match ((string) ($task['cadence'] ?? '')) {
        'once'            => $t('Once, %s', (string) ($task['next_run_local'] ?? $task['run_at'] ?? '')),
        'weekdays'        => $t('Monday to Friday at %s', $at),
        'weekly'          => $t('Every %s at %s', $t($days[(int) ($task['day'] ?? 1)] ?? ''), $at),
        'monthly'         => $t('Monthly on day %s at %s', (string) ($task['day'] ?? ''), $at),
        'monthly_weekday' => $t('The %s %s of each month at %s', $t($nths[(int) ($task['nth'] ?? 1)] ?? ''), $t($days[(int) ($task['day'] ?? 1)] ?? ''), $at),
        default           => $t('Every day at %s', $at),
    };
};
/** A small form posting to `$action` with hidden fields, as one button. */
$button = static function (string $action, array $fields, string $label, string $class, string $confirm = '') use ($e, $token, $act): string {
    $html = '<form method="post" action="' . $e($act($action)) . '" style="display:inline;margin:0">' . $token();
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
    }
    return $html . '<button type="submit" class="' . $e($class) . '"' . ($confirm !== '' ? ' data-confirm="' . $e($confirm) . '"' : '') . '>' . $e($label) . '</button></form>';
};
$tasks   = is_array($this->tasks ?? null) ? $this->tasks : [];
$reports = is_array($this->reports ?? null) ? $this->reports : [];
$task    = is_array($this->task ?? null) ? $this->task : null;
$runs    = is_array($this->runs ?? null) ? $this->runs : [];
$owners  = is_array($this->owners ?? null) ? $this->owners : ['user'];
$roles   = is_array($this->audienceRoles ?? null) ? $this->audienceRoles : [];
// Who may receive a shared report: everyone from a role up, and people by name — blank for the maker's standing and up.
// Where the readers' field asks who matches what is typed (only where somebody is offered).
$people  = (string) ($this->peopleUrl ?? '');
$readers = static function (?array $audience, array $names) use ($c, $e, $t, $roles, $people): string {
    $min  = $audience['min_usertype'] ?? null;
    $html = '<label class="' . $c['field'] . '">' . $e($t('Readers from')) . ' <select class="' . $c['select'] . '" name="audience_role">'
        . '<option value="">' . $e($t('My level and up')) . '</option>';
    foreach ($roles as $role => $level) {
        $html .= '<option value="' . $e($role) . '"' . ($min === $level ? ' selected' : '') . '>' . $e($t(ucfirst($role))) . ' (' . (int) $level . ')</option>';
    }

    return $html . '</select></label><label class="' . $c['field'] . '">' . $e($t('And these people')) . ' <input class="' . $c['input'] . '" name="audience_users" value="'
        . $e(implode(', ', $names)) . '" placeholder="' . $e($t('usernames or emails, comma-separated')) . '" data-pf-audience-users autocomplete="off" data-pf-people="' . $e($people) . '"></label>';
}; @endphp
<div class="{!! $c['page'] !!}">
    <div class="{!! $c['row'] !!}">
        <h1 class="{!! $c['h1'] !!}">{{ $t('Scheduled') }}</h1>
        <a class="{!! $c['btnGhost'] !!}" href="{{ $u['chat'] }}">{{ $t('Conversations') }}</a>
    </div>

    @if ($task !== null)
    <section class="{!! $c['card'] !!}" data-pf-scheduled-runs>
        <div class="{!! $c['row'] !!}">
            <h2 class="{!! $c['h2'] !!}">{{ $task['title'] }}</h2>
            <span class="{!! $c['muted'] !!}">{{ $when($task) }}</span>
            <a class="{!! $c['btnGhost'] !!}" href="{{ $u['page'] }}">{{ $t('Close') }}</a>
        </div>
        @if (!empty($this->mayEdit) && ($task['owner'] ?? 'user') !== 'user')
        <form method="post" action="{{ $act('scheduledupdate') }}" class="{!! $c['row'] !!}" data-pf-scheduled-audience>
            {!! $token() !!}
            <input type="hidden" name="routine_id" value="{!! (int) $task['routine_id'] !!}">
            <input type="hidden" name="audience" value="1">
            {!! $readers(is_array($task['audience'] ?? null) ? $task['audience'] : null, is_array($this->audienceUsers ?? null) ? array_values($this->audienceUsers) : []) !!}
            <button type="submit" class="{!! $c['btnGhost'] !!}">{{ $t('Save readers') }}</button>
        </form>
        @endif
        @foreach ($runs as $run)
        <article>
            <p class="{!! $c['muted'] !!}">{{ $run['finished_at'] ?? $run['started_at'] ?? '' }} · {{ $t($run['status'] === 'ok' ? 'Done' : 'Failed') }}
                @if ($run['status'] === 'ok' && ($task['kind'] ?? '') === 'report') · <a href="{{ $u['report'] . (int) $run['run_id'] }}">{{ $t('Ask about it') }}</a>@endif</p>
            @if ($run['error'])<p class="{!! $c['bad'] !!}">{{ $run['error'] }}</p>@endif
            @if (($run['content'] ?? '') !== '')<div class="{!! $c['answer'] !!}">{!! \Pramnos\Llm\Chat\Markdown::toHtml((string) $run['content']) !!}</div>@endif
        </article>
        @endforeach
        @if ($runs === [])<p class="{!! $c['muted'] !!}">{{ $t('It has not run yet.') }}</p>@endif
        @php echo (string) ($this->extras['task'] ?? ''); /* the controller's own markup, escaped by it */ @endphp
    </section>
    @endif

    @if (array_filter($tasks, static fn (array $one): bool => !empty($one['running'])) !== [])
    <!-- A run is under way in the worker: look again until it has finished, as Ask's page waits for an answer. -->
    <meta http-equiv="refresh" content="15">
    @endif
    <section class="{!! $c['card'] !!}">
        <h2 class="{!! $c['h2'] !!}">{{ $t('Yours') }}</h2>
        @foreach ($tasks as $one)
        <div class="{!! $c['row'] !!}" data-pf-scheduled-task="{!! (int) $one['routine_id'] !!}">
            <div style="flex:1;min-width:12rem">
                <strong>{{ $one['title'] }}</strong>
                <span class="{!! $c['badge'] !!}">{{ $t($kinds[$one['kind']] ?? $one['kind']) }}</span>
                @if (!$one['active'])<span class="{!! $c['badge'] !!}">{{ $t('Paused') }}</span>@endif
                @if (!empty($one['running']))<span class="{!! $c['badge'] !!}" data-pf-scheduled-running>{{ $t('Running…') }}</span>@endif
                @if (($one['owner'] ?? 'user') !== 'user')<span class="{!! $c['badge'] !!}">{{ $t($one['owner'] === 'global' ? 'Everyone' : 'Organisation') }}</span>@endif
                <div class="{!! $c['muted'] !!}">{{ $when($one) }}{!! $one['active'] && $one['next_run_local'] ? ' · ' . $e($t('next: %s', (string) $one['next_run_local'])) : '' !!}</div>
                @if ($one['last_error'])<div class="{!! $c['bad'] !!}">{{ $one['last_error'] }}</div>@endif
            </div>
            <a class="{!! $c['btnGhost'] !!}" href="{{ $u['page'] . '?task=' . (int) $one['routine_id'] }}">{{ $t('See its runs') }}</a>
            @php if ($one['kind'] === 'report'): /* one's own report, by email too: the only way to hear of it where there is no bell */; @endphp
            {!! $button('scheduledsubscribe', ['routine_id' => $one['routine_id'], 'on' => '1', 'email' => empty($one['email']) ? '1' : ''], $t(empty($one['email']) ? 'Email it to me' : 'Stop the email'), $c['btnGhost']) !!}
            @endif
            @if (empty($one['running']))
                {!! $button('scheduledrun', ['routine_id' => $one['routine_id']], $t('Run now'), $c['btnGhost']) !!}
            @endif
            {!! $button('scheduledupdate', ['routine_id' => $one['routine_id'], 'active' => $one['active'] ? '' : '1'], $t($one['active'] ? 'Pause' : 'Resume'), $c['btnGhost']) !!}
            {!! $button('scheduleddelete', ['routine_id' => $one['routine_id']], $t('Delete'), $c['danger'], $t('Delete this scheduled task?')) !!}
        </div>
        @endforeach
        @if ($tasks === [])<p class="{!! $c['muted'] !!}">{{ $t('Nothing scheduled yet. Ask in a conversation — "remind me tomorrow at nine", "every Monday give me the week\'s figures" — or schedule one below.') }}</p>@endif
    </section>

    @if ($reports !== [])
    <section class="{!! $c['card'] !!}">
        <h2 class="{!! $c['h2'] !!}">{{ $t('Shared reports') }}</h2>
        @foreach ($reports as $one)
        <div class="{!! $c['row'] !!}" data-pf-scheduled-report="{!! (int) $one['routine_id'] !!}">
            <div style="flex:1;min-width:12rem">
                <strong>{{ $one['title'] }}</strong>
                <div class="{!! $c['muted'] !!}">{{ $when($one) }}</div>
            </div>
            <a class="{!! $c['btnGhost'] !!}" href="{{ $u['page'] . '?task=' . (int) $one['routine_id'] }}">{{ $t('See its runs') }}</a>
            {!! $button('scheduledsubscribe', ['routine_id' => $one['routine_id'], 'on' => $one['subscribed'] ? '' : '1'], $t($one['subscribed'] ? 'Stop receiving' : 'Receive it'), $one['subscribed'] ? $c['btnGhost'] : $c['btn']) !!}
            @if (empty($one['subscribed'])){!! $button('scheduledsubscribe', ['routine_id' => $one['routine_id'], 'on' => '1', 'email' => '1'], $t('Receive it by email too'), $c['btnGhost']) !!}@endif
        </div>
        @endforeach
    </section>
    @endif

    <form class="{!! $c['card'] !!}" method="post" action="{{ $act('schedule') }}" data-pf-scheduled-form>
        {!! $token() !!}
        <h2 class="{!! $c['h2'] !!}">{{ $t('Schedule') }}</h2>
        <div class="{!! $c['row'] !!}">
            <label class="{!! $c['field'] !!}">{{ $t('What') }}
                <select class="{!! $c['select'] !!}" name="kind">
                    @foreach ((array) ($this->kinds ?? array_keys($kinds)) as $kind)<option value="{{ $kind }}">{{ $t($kinds[$kind] ?? $kind) }}</option>@endforeach
                </select>
            </label>
            @if (count($owners) > 1)
            <label class="{!! $c['field'] !!}" data-pf-show-when="kind:report">{{ $t('For') }}
                <select class="{!! $c['select'] !!}" name="owner">
                    @foreach ($owners as $owner)<option value="{{ $owner }}">{{ $t(['user' => 'Me', 'organization' => 'My organisation (a report)', 'global' => 'Everyone (a report)'][$owner] ?? $owner) }}</option>@endforeach
                </select>
            </label>
            <span data-pf-show-when="owner:organization global">@php echo $readers(null, []); /* read only for a shared report */ @endphp</span>
            @endif
            <label class="{!! $c['field'] !!}" data-pf-show-when="kind:report">{{ $t('Tell readers') }}
                <select class="{!! $c['select'] !!}" name="notify">
                    <option value="always">{{ $t('Every time') }}</option>
                    <option value="relevant">{{ $t('Only when there is something new (a report)') }}</option>
                </select>
            </label>
            <label class="{!! $c['field'] !!}" data-pf-show-when="kind:report"><input type="checkbox" name="email" value="1" checked> {{ $t('Email the report to me too') }}</label>
            @if (!empty($this->templates))
            <label class="{!! $c['field'] !!}">{{ $t('From a template') }}
                <select class="{!! $c['select'] !!}" name="template">
                    <option value="">{{ $t('None') }}</option>
                    @foreach ($this->templates as $key => $one)<option value="{{ $key }}">{{ $t((string) ($one['title'] ?? $key)) }}</option>@endforeach
                </select>
            </label>
            @endif
        </div>
        <label class="{!! $c['field'] !!}">{{ $t('Title') }}
            <input class="{!! $c['input'] !!}" name="title" maxlength="120">
        </label>
        <label class="{!! $c['field'] !!}">{{ $t('What to ask, or what to remind you of') }}
            <textarea class="{!! $c['textarea'] !!}" name="prompt" rows="3"></textarea>
        </label>
        <div class="{!! $c['row'] !!}">
            <label class="{!! $c['field'] !!}">{{ $t('How often') }}
                <select class="{!! $c['select'] !!}" name="cadence">
                    @foreach ((array) ($this->cadences ?? []) as $cadence)<option value="{{ $cadence }}"{!! $cadence === 'daily' ? ' selected' : '' !!}>{{ $t(['daily' => 'Every day', 'weekdays' => 'Monday to Friday', 'weekly' => 'Every week', 'monthly' => 'Every month', 'once' => 'Once', 'monthly_weekday' => 'A weekday of each month'][$cadence] ?? $cadence) }}</option>@endforeach
                </select>
            </label>
            <label class="{!! $c['field'] !!}" data-pf-show-when="cadence:daily weekdays weekly monthly monthly_weekday">{{ $t('At') }}
                <input class="{!! $c['input'] !!}" type="time" name="at" value="08:00">
            </label>
            <label class="{!! $c['field'] !!}" data-pf-show-when="cadence:weekly monthly_weekday">{{ $t('Weekday') }}
                <select class="{!! $c['select'] !!}" name="weekday">
                    @foreach ($days as $number => $day)<option value="{!! $number !!}">{{ $t($day) }}</option>@endforeach
                </select>
            </label>
            <label class="{!! $c['field'] !!}" data-pf-show-when="cadence:monthly">{{ $t('Day of the month') }}
                <input class="{!! $c['input'] !!}" type="number" name="monthday" min="1" max="28" value="1">
            </label>
            <label class="{!! $c['field'] !!}" data-pf-show-when="cadence:monthly_weekday">{{ $t('Which') }}
                <select class="{!! $c['select'] !!}" name="nth">
                    @foreach ($nths as $number => $nth)<option value="{!! $number !!}">{{ $t($nth) }}</option>@endforeach
                </select>
            </label>
            <label class="{!! $c['field'] !!}" data-pf-show-when="cadence:once">{{ $t('When, on your clock') }}
                <input class="{!! $c['input'] !!}" type="datetime-local" name="run_at">
            </label>
        </div>
        <p class="{!! $c['muted'] !!}">{{ $t('Times are on %s.', (string) ($this->timezone ?? 'UTC')) }}</p>
        @php echo (string) ($this->extras['form'] ?? ''); /* the controller's own fields, escaped by it */ @endphp
        <div><button type="submit" class="{!! $c['btn'] !!}">{{ $t('Schedule') }}</button></div>
    </form>
</div>
