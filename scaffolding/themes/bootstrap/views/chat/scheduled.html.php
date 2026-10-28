<?php
/**
 * Scheduled tasks (Bootstrap theme) — `chat/scheduled`: this person's questions asked again, reminders
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
$c     = ['page' => 'container py-4 d-flex flex-column gap-3', 'card' => 'card card-body', 'muted' => 'text-muted small', 'bad' => 'text-danger small', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-outline-secondary', 'danger' => 'btn btn-sm btn-outline-danger', 'input' => 'form-control form-control-sm', 'select' => 'form-select form-select-sm', 'textarea' => 'form-control', 'badge' => 'badge text-bg-secondary', 'row' => 'd-flex flex-wrap align-items-center gap-2', 'field' => 'd-flex flex-column gap-1 small', 'answer' => '', 'h1' => 'h3', 'h2' => 'h5'];
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
$readers = static function (?array $audience, array $names) use ($c, $e, $t, $roles): string {
    $min  = $audience['min_usertype'] ?? null;
    $html = '<label class="' . $c['field'] . '">' . $e($t('Readers from')) . ' <select class="' . $c['select'] . '" name="audience_role">'
        . '<option value="">' . $e($t('My level and up')) . '</option>';
    foreach ($roles as $role => $level) {
        $html .= '<option value="' . $e($role) . '"' . ($min === $level ? ' selected' : '') . '>' . $e($t(ucfirst($role))) . ' (' . (int) $level . ')</option>';
    }

    return $html . '</select></label><label class="' . $c['field'] . '">' . $e($t('And these people')) . ' <input class="' . $c['input'] . '" name="audience_users" value="'
        . $e(implode(', ', $names)) . '" placeholder="' . $e($t('usernames or emails, comma-separated')) . '" data-pf-audience-users></label>';
};
?>
<div class="<?php echo $c['page']; ?>">
    <div class="<?php echo $c['row']; ?>">
        <h1 class="<?php echo $c['h1']; ?>"><?php echo $e($t('Scheduled')); ?></h1>
        <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($u['chat']); ?>"><?php echo $e($t('Conversations')); ?></a>
    </div>

    <?php if ($task !== null): ?>
    <section class="<?php echo $c['card']; ?>" data-pf-scheduled-runs>
        <div class="<?php echo $c['row']; ?>">
            <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($task['title']); ?></h2>
            <span class="<?php echo $c['muted']; ?>"><?php echo $e($when($task)); ?></span>
            <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($u['page']); ?>"><?php echo $e($t('Close')); ?></a>
        </div>
        <?php if (!empty($this->mayEdit) && ($task['owner'] ?? 'user') !== 'user'): ?>
        <form method="post" action="<?php echo $e($act('scheduledupdate')); ?>" class="<?php echo $c['row']; ?>" data-pf-scheduled-audience>
            <?php echo $token(); ?>
            <input type="hidden" name="routine_id" value="<?php echo (int) $task['routine_id']; ?>">
            <input type="hidden" name="audience" value="1">
            <?php echo $readers(is_array($task['audience'] ?? null) ? $task['audience'] : null, is_array($this->audienceUsers ?? null) ? array_values($this->audienceUsers) : []); ?>
            <button type="submit" class="<?php echo $c['btnGhost']; ?>"><?php echo $e($t('Save readers')); ?></button>
        </form>
        <?php endif; ?>
        <?php foreach ($runs as $run): ?>
        <article>
            <p class="<?php echo $c['muted']; ?>"><?php echo $e($run['finished_at'] ?? $run['started_at'] ?? ''); ?> · <?php echo $e($t($run['status'] === 'ok' ? 'Done' : 'Failed')); ?>
                <?php if ($run['status'] === 'ok' && ($task['kind'] ?? '') === 'report'): ?> · <a href="<?php echo $e($u['report'] . (int) $run['run_id']); ?>"><?php echo $e($t('Ask about it')); ?></a><?php endif; ?></p>
            <?php if ($run['error']): ?><p class="<?php echo $c['bad']; ?>"><?php echo $e($run['error']); ?></p><?php endif; ?>
            <?php if (($run['content'] ?? '') !== ''): ?><div class="<?php echo $c['answer']; ?>"><?php echo \Pramnos\Llm\Chat\Markdown::toHtml((string) $run['content']); ?></div><?php endif; ?>
        </article>
        <?php endforeach; ?>
        <?php if ($runs === []): ?><p class="<?php echo $c['muted']; ?>"><?php echo $e($t('It has not run yet.')); ?></p><?php endif; ?>
        <?php echo (string) ($this->extras['task'] ?? ''); /* the controller's own markup, escaped by it */ ?>
    </section>
    <?php endif; ?>

    <?php if (array_filter($tasks, static fn (array $one): bool => !empty($one['running'])) !== []): ?>
    <!-- A run is under way in the worker: look again until it has finished, as Ask's page waits for an answer. -->
    <meta http-equiv="refresh" content="15">
    <?php endif; ?>
    <section class="<?php echo $c['card']; ?>">
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Yours')); ?></h2>
        <?php foreach ($tasks as $one): ?>
        <div class="<?php echo $c['row']; ?>" data-pf-scheduled-task="<?php echo (int) $one['routine_id']; ?>">
            <div style="flex:1;min-width:12rem">
                <strong><?php echo $e($one['title']); ?></strong>
                <span class="<?php echo $c['badge']; ?>"><?php echo $e($t($kinds[$one['kind']] ?? $one['kind'])); ?></span>
                <?php if (!$one['active']): ?><span class="<?php echo $c['badge']; ?>"><?php echo $e($t('Paused')); ?></span><?php endif; ?>
                <?php if (!empty($one['running'])): ?><span class="<?php echo $c['badge']; ?>" data-pf-scheduled-running><?php echo $e($t('Running…')); ?></span><?php endif; ?>
                <?php if (($one['owner'] ?? 'user') !== 'user'): ?><span class="<?php echo $c['badge']; ?>"><?php echo $e($t($one['owner'] === 'global' ? 'Everyone' : 'Organisation')); ?></span><?php endif; ?>
                <div class="<?php echo $c['muted']; ?>"><?php echo $e($when($one)); ?><?php echo $one['active'] && $one['next_run_local'] ? ' · ' . $e($t('next: %s', (string) $one['next_run_local'])) : ''; ?></div>
                <?php if ($one['last_error']): ?><div class="<?php echo $c['bad']; ?>"><?php echo $e($one['last_error']); ?></div><?php endif; ?>
            </div>
            <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($u['page'] . '?task=' . (int) $one['routine_id']); ?>"><?php echo $e($t('See its runs')); ?></a>
            <?php if ($one['kind'] === 'report'): /* one's own report, by email too: the only way to hear of it where there is no bell */ ?>
            <?php echo $button('scheduledsubscribe', ['routine_id' => $one['routine_id'], 'on' => '1', 'email' => empty($one['email']) ? '1' : ''], $t(empty($one['email']) ? 'Email it to me' : 'Stop the email'), $c['btnGhost']); ?>
            <?php endif; ?>
            <?php if (empty($one['running'])): ?>
                <?php echo $button('scheduledrun', ['routine_id' => $one['routine_id']], $t('Run now'), $c['btnGhost']); ?>
            <?php endif; ?>
            <?php echo $button('scheduledupdate', ['routine_id' => $one['routine_id'], 'active' => $one['active'] ? '' : '1'], $t($one['active'] ? 'Pause' : 'Resume'), $c['btnGhost']); ?>
            <?php echo $button('scheduleddelete', ['routine_id' => $one['routine_id']], $t('Delete'), $c['danger'], $t('Delete this scheduled task?')); ?>
        </div>
        <?php endforeach; ?>
        <?php if ($tasks === []): ?><p class="<?php echo $c['muted']; ?>"><?php echo $e($t('Nothing scheduled yet. Ask in a conversation — "remind me tomorrow at nine", "every Monday give me the week\'s figures" — or schedule one below.')); ?></p><?php endif; ?>
    </section>

    <?php if ($reports !== []): ?>
    <section class="<?php echo $c['card']; ?>">
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Shared reports')); ?></h2>
        <?php foreach ($reports as $one): ?>
        <div class="<?php echo $c['row']; ?>" data-pf-scheduled-report="<?php echo (int) $one['routine_id']; ?>">
            <div style="flex:1;min-width:12rem">
                <strong><?php echo $e($one['title']); ?></strong>
                <div class="<?php echo $c['muted']; ?>"><?php echo $e($when($one)); ?></div>
            </div>
            <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($u['page'] . '?task=' . (int) $one['routine_id']); ?>"><?php echo $e($t('See its runs')); ?></a>
            <?php echo $button('scheduledsubscribe', ['routine_id' => $one['routine_id'], 'on' => $one['subscribed'] ? '' : '1'], $t($one['subscribed'] ? 'Stop receiving' : 'Receive it'), $one['subscribed'] ? $c['btnGhost'] : $c['btn']); ?>
            <?php if (empty($one['subscribed'])): ?><?php echo $button('scheduledsubscribe', ['routine_id' => $one['routine_id'], 'on' => '1', 'email' => '1'], $t('Receive it by email too'), $c['btnGhost']); ?><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <form class="<?php echo $c['card']; ?>" method="post" action="<?php echo $e($act('schedule')); ?>" data-pf-scheduled-form>
        <?php echo $token(); ?>
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Schedule')); ?></h2>
        <div class="<?php echo $c['row']; ?>">
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('What')); ?>
                <select class="<?php echo $c['select']; ?>" name="kind">
                    <?php foreach ((array) ($this->kinds ?? array_keys($kinds)) as $kind): ?><option value="<?php echo $e($kind); ?>"><?php echo $e($t($kinds[$kind] ?? $kind)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php if (count($owners) > 1): ?>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="kind:report"><?php echo $e($t('For')); ?>
                <select class="<?php echo $c['select']; ?>" name="owner">
                    <?php foreach ($owners as $owner): ?><option value="<?php echo $e($owner); ?>"><?php echo $e($t(['user' => 'Me', 'organization' => 'My organisation (a report)', 'global' => 'Everyone (a report)'][$owner] ?? $owner)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <span data-pf-show-when="owner:organization global"><?php echo $readers(null, []); /* read only for a shared report */ ?></span>
            <?php endif; ?>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="kind:report"><?php echo $e($t('Tell readers')); ?>
                <select class="<?php echo $c['select']; ?>" name="notify">
                    <option value="always"><?php echo $e($t('Every time')); ?></option>
                    <option value="relevant"><?php echo $e($t('Only when there is something new (a report)')); ?></option>
                </select>
            </label>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="kind:report"><input type="checkbox" name="email" value="1" checked> <?php echo $e($t('Email the report to me too')); ?></label>
            <?php if (!empty($this->templates)): ?>
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('From a template')); ?>
                <select class="<?php echo $c['select']; ?>" name="template">
                    <option value=""><?php echo $e($t('None')); ?></option>
                    <?php foreach ($this->templates as $key => $one): ?><option value="<?php echo $e($key); ?>"><?php echo $e($t((string) ($one['title'] ?? $key))); ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
        </div>
        <label class="<?php echo $c['field']; ?>"><?php echo $e($t('Title')); ?>
            <input class="<?php echo $c['input']; ?>" name="title" maxlength="120">
        </label>
        <label class="<?php echo $c['field']; ?>"><?php echo $e($t('What to ask, or what to remind you of')); ?>
            <textarea class="<?php echo $c['textarea']; ?>" name="prompt" rows="3"></textarea>
        </label>
        <div class="<?php echo $c['row']; ?>">
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('How often')); ?>
                <select class="<?php echo $c['select']; ?>" name="cadence">
                    <?php foreach ((array) ($this->cadences ?? []) as $cadence): ?><option value="<?php echo $e($cadence); ?>"<?php echo $cadence === 'daily' ? ' selected' : ''; ?>><?php echo $e($t(['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month', 'once' => 'Once', 'monthly_weekday' => 'A weekday of each month'][$cadence] ?? $cadence)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="cadence:daily weekly monthly monthly_weekday"><?php echo $e($t('At')); ?>
                <input class="<?php echo $c['input']; ?>" type="time" name="at" value="08:00">
            </label>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="cadence:weekly monthly_weekday"><?php echo $e($t('Weekday')); ?>
                <select class="<?php echo $c['select']; ?>" name="weekday">
                    <?php foreach ($days as $number => $day): ?><option value="<?php echo $number; ?>"><?php echo $e($t($day)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="cadence:monthly"><?php echo $e($t('Day of the month')); ?>
                <input class="<?php echo $c['input']; ?>" type="number" name="monthday" min="1" max="28" value="1">
            </label>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="cadence:monthly_weekday"><?php echo $e($t('Which')); ?>
                <select class="<?php echo $c['select']; ?>" name="nth">
                    <?php foreach ($nths as $number => $nth): ?><option value="<?php echo $number; ?>"><?php echo $e($t($nth)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="<?php echo $c['field']; ?>" data-pf-show-when="cadence:once"><?php echo $e($t('When, on your clock')); ?>
                <input class="<?php echo $c['input']; ?>" type="datetime-local" name="run_at">
            </label>
        </div>
        <p class="<?php echo $c['muted']; ?>"><?php echo $e($t('Times are on %s.', (string) ($this->timezone ?? 'UTC'))); ?></p>
        <?php echo (string) ($this->extras['form'] ?? ''); /* the controller's own fields, escaped by it */ ?>
        <div><button type="submit" class="<?php echo $c['btn']; ?>"><?php echo $e($t('Schedule')); ?></button></div>
    </form>
</div>
