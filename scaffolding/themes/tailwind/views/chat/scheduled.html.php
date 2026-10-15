<?php
/**
 * Scheduled tasks (Tailwind theme) — `chat/scheduled`: this person's questions asked again, reminders
 * and reports; the reports shared with them to receive; a form to schedule one; and, with
 * `?task=`, one task's runs, each opened in Ask.
 *
 * Variables:
 *   $this->tasks     — this person's: routine_id, kind, title, prompt, cadence, at, day, nth, run_at,
 *                      timezone, active, next_run_local, last_error, owner
 *   $this->reports   — shared reports they may receive: the same, with subscribed and latest_run
 *   $this->task      — the task whose runs are open, or null
 *   $this->runs      — its runs, newest first: run_id, status, content, error, finished_at
 *   $this->templates — key => {title, prompt, cadence, at, day}
 *   $this->cadences, $this->kinds — what the form offers
 *   $this->owners    — whose a report may be, of user, organization, global, as the policy allows
 *   $this->timezone  — the clock times are read on
 *   $this->pageUrl, $this->chatUrl — this page, and the conversations
 *
 * A run's content is the model's words, drawn by Markdown::toHtml(), which escapes everything first.
 */
$c     = ['page' => 'max-w-4xl mx-auto px-4 py-6 flex flex-col gap-4', 'card' => 'card bg-base-100 border border-base-300 p-4', 'muted' => 'text-sm opacity-70', 'bad' => 'text-sm text-error', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'danger' => 'btn btn-sm btn-ghost text-error', 'input' => 'input input-sm input-bordered', 'select' => 'select select-sm select-bordered', 'textarea' => 'textarea textarea-bordered w-full', 'badge' => 'badge badge-ghost badge-sm', 'row' => 'flex flex-wrap items-center gap-2', 'field' => 'flex flex-col gap-1 text-sm', 'answer' => 'prose max-w-none', 'h1' => 'text-2xl font-semibold', 'h2' => 'text-lg font-semibold'];
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t     = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$url   = static fn (string $path): string => sURL . $path;
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
$button = static function (string $action, array $fields, string $label, string $class, string $confirm = '') use ($e, $token, $url): string {
    $html = '<form method="post" action="' . $e($url('chat/' . $action)) . '" style="display:inline;margin:0">' . $token();
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
?>
<div class="<?php echo $c['page']; ?>">
    <div class="<?php echo $c['row']; ?>">
        <h1 class="<?php echo $c['h1']; ?>"><?php echo $e($t('Scheduled')); ?></h1>
        <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($this->chatUrl ?? $url('chat')); ?>"><?php echo $e($t('Conversations')); ?></a>
    </div>

    <?php if ($task !== null): ?>
    <section class="<?php echo $c['card']; ?>" data-pf-scheduled-runs>
        <div class="<?php echo $c['row']; ?>">
            <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($task['title']); ?></h2>
            <span class="<?php echo $c['muted']; ?>"><?php echo $e($when($task)); ?></span>
            <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($this->pageUrl ?? $url('chat/scheduled')); ?>"><?php echo $e($t('Close')); ?></a>
        </div>
        <?php foreach ($runs as $run): ?>
        <article>
            <p class="<?php echo $c['muted']; ?>"><?php echo $e($run['finished_at'] ?? $run['started_at'] ?? ''); ?> · <?php echo $e($t($run['status'] === 'ok' ? 'Done' : 'Failed')); ?>
                <?php if ($run['status'] === 'ok' && ($task['kind'] ?? '') === 'report'): ?> · <a href="<?php echo $e($url('chat/report/' . (int) $run['run_id'])); ?>"><?php echo $e($t('Ask about it')); ?></a><?php endif; ?></p>
            <?php if ($run['error']): ?><p class="<?php echo $c['bad']; ?>"><?php echo $e($run['error']); ?></p><?php endif; ?>
            <?php if (($run['content'] ?? '') !== ''): ?><div class="<?php echo $c['answer']; ?>"><?php echo \Pramnos\Llm\Chat\Markdown::toHtml((string) $run['content']); ?></div><?php endif; ?>
        </article>
        <?php endforeach; ?>
        <?php if ($runs === []): ?><p class="<?php echo $c['muted']; ?>"><?php echo $e($t('It has not run yet.')); ?></p><?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="<?php echo $c['card']; ?>">
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Yours')); ?></h2>
        <?php foreach ($tasks as $one): ?>
        <div class="<?php echo $c['row']; ?>" data-pf-scheduled-task="<?php echo (int) $one['routine_id']; ?>">
            <div style="flex:1;min-width:12rem">
                <strong><?php echo $e($one['title']); ?></strong>
                <span class="<?php echo $c['badge']; ?>"><?php echo $e($t($kinds[$one['kind']] ?? $one['kind'])); ?></span>
                <?php if (!$one['active']): ?><span class="<?php echo $c['badge']; ?>"><?php echo $e($t('Paused')); ?></span><?php endif; ?>
                <?php if (($one['owner'] ?? 'user') !== 'user'): ?><span class="<?php echo $c['badge']; ?>"><?php echo $e($t($one['owner'] === 'global' ? 'Everyone' : 'Organisation')); ?></span><?php endif; ?>
                <div class="<?php echo $c['muted']; ?>"><?php echo $e($when($one)); ?><?php echo $one['active'] && $one['next_run_local'] ? ' · ' . $e($t('next: %s', (string) $one['next_run_local'])) : ''; ?></div>
                <?php if ($one['last_error']): ?><div class="<?php echo $c['bad']; ?>"><?php echo $e($one['last_error']); ?></div><?php endif; ?>
            </div>
            <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($url('chat/scheduled?task=' . (int) $one['routine_id'])); ?>"><?php echo $e($t('Runs')); ?></a>
            <?php echo $button('scheduledrun', ['routine_id' => $one['routine_id']], $t('Run now'), $c['btnGhost']); ?>
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
            <a class="<?php echo $c['btnGhost']; ?>" href="<?php echo $e($url('chat/scheduled?task=' . (int) $one['routine_id'])); ?>"><?php echo $e($t('Runs')); ?></a>
            <?php echo $button('scheduledsubscribe', ['routine_id' => $one['routine_id'], 'on' => $one['subscribed'] ? '' : '1'], $t($one['subscribed'] ? 'Stop receiving' : 'Receive it'), $one['subscribed'] ? $c['btnGhost'] : $c['btn']); ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <form class="<?php echo $c['card']; ?>" method="post" action="<?php echo $e($url('chat/schedule')); ?>" data-pf-scheduled-form>
        <?php echo $token(); ?>
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($t('Schedule')); ?></h2>
        <div class="<?php echo $c['row']; ?>">
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('What')); ?>
                <select class="<?php echo $c['select']; ?>" name="kind">
                    <?php foreach ((array) ($this->kinds ?? array_keys($kinds)) as $kind): ?><option value="<?php echo $e($kind); ?>"><?php echo $e($t($kinds[$kind] ?? $kind)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php if (count($owners) > 1): ?>
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('For')); ?>
                <select class="<?php echo $c['select']; ?>" name="owner">
                    <?php foreach ($owners as $owner): ?><option value="<?php echo $e($owner); ?>"><?php echo $e($t(['user' => 'Me', 'organization' => 'My organisation (a report)', 'global' => 'Everyone (a report)'][$owner] ?? $owner)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('Tell readers')); ?>
                <select class="<?php echo $c['select']; ?>" name="notify">
                    <option value="always"><?php echo $e($t('Every time')); ?></option>
                    <option value="relevant"><?php echo $e($t('Only when there is something new (a report)')); ?></option>
                </select>
            </label>
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
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('At')); ?>
                <input class="<?php echo $c['input']; ?>" type="time" name="at" value="08:00">
            </label>
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('Weekday (weekly, a weekday of the month)')); ?>
                <select class="<?php echo $c['select']; ?>" name="weekday">
                    <?php foreach ($days as $number => $day): ?><option value="<?php echo $number; ?>"><?php echo $e($t($day)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('Day of the month (monthly)')); ?>
                <input class="<?php echo $c['input']; ?>" type="number" name="monthday" min="1" max="28" value="1">
            </label>
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('Which (a weekday of the month)')); ?>
                <select class="<?php echo $c['select']; ?>" name="nth">
                    <?php foreach ($nths as $number => $nth): ?><option value="<?php echo $number; ?>"><?php echo $e($t($nth)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="<?php echo $c['field']; ?>"><?php echo $e($t('When (once), on your clock')); ?>
                <input class="<?php echo $c['input']; ?>" type="datetime-local" name="run_at">
            </label>
        </div>
        <p class="<?php echo $c['muted']; ?>"><?php echo $e($t('Times are on %s.', (string) ($this->timezone ?? 'UTC'))); ?></p>
        <div><button type="submit" class="<?php echo $c['btn']; ?>"><?php echo $e($t('Schedule')); ?></button></div>
    </form>
</div>
