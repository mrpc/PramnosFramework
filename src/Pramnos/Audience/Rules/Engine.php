<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\Filter;
use Pramnos\Audience\Installed;
use Pramnos\Database\Database;
use Pramnos\Event\Event;
use Pramnos\Queue\QueueManager;

/**
 * Rules running: people taken in, each step done in the queue, waits as delayed tasks.
 *
 * ```php
 * // app/app.php, the application's init — the request and the worker alike
 * \Pramnos\Audience\Rules\Engine::listen();
 * ```
 *
 * `listen()` puts a listener on each event the running rules ask about — their triggers, their
 * waits, their goals — so firing one writes a task and nothing more in the request. The worker
 * then takes people in, resumes the ones waiting for it, and marks goals reached. A schedule or a
 * `once` rule takes everybody who matches, page by page; `rules:run` asks every minute which
 * schedules are due.
 *
 * Each step is a task keyed by its run and its place in it (`seq`): a run moves forward only from
 * the place the task names, so a retried task, or a timeout arriving after the event it waited
 * for, does nothing. Before a message action: the rule's consent list, the frequency caps (the
 * rule's, then `audience.caps` in app.php — `['email' => ['max' => 1, 'days' => 1]]`), and its
 * sending hours. A rule in shadow mode does everything but the actions, and logs what they would
 * have been.
 */
final class Engine
{
    /** The queue task type. */
    public const TASK = 'audience.rule';

    /** How many people a "take everybody in" task enrolls before it hands on to the next. */
    public static int $page = 500;

    /** The queue the tasks go to; tests set their own. */
    public static ?QueueManager $queue = null;

    /** Listen for every event a running rule asks about. A no-op until rules are installed. */
    public static function listen(): void
    {
        foreach (Rules::listenedEvents() as $event) {
            Event::listen($event, static function (mixed ...$args) use ($event): void {
                Engine::heard($event, $args);
            });
        }
    }

    /**
     * An event a rule asks about was fired: a task for the worker, if it names a person.
     *
     * @param list<mixed> $args The event's arguments: a person's id first, or an object naming one
     */
    public static function heard(string $event, array $args): void
    {
        $subject = $args[0] ?? null;
        $person  = \Pramnos\Audience\RefreshPersonSegments::person($subject);
        if ($person === null) {
            return;
        }
        self::schedule([
            'kind'    => 'event',
            'event'   => $event,
            'person'  => $person,
            'segment' => is_object($subject) && isset($subject->segmentId) ? (int) $subject->segmentId : null,
            'meta'    => \Pramnos\Event\QueuedEvents::meta($event),
        ]);
    }

    /**
     * Write a task; null where it could not be written, which is logged.
     *
     * @param array<string, mixed> $job
     */
    public static function schedule(array $job, int $delay = 0, ?string $key = null): ?int
    {
        if (!Installed::has('rules', 'rule_runs', 'rule_log')) {
            return null;
        }
        try {
            $queue = self::$queue ?? new QueueManager(QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue'));

            return $queue->addKeyed(self::TASK, $job, $key, $delay);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('A rule task was not queued (' . ($job['kind'] ?? '?') . '): ' . $e->getMessage(), 'audience');

            return null;
        }
    }

    /**
     * What a task does.
     *
     * @param array<string, mixed> $job
     */
    public static function handle(array $job): string
    {
        return match ($job['kind'] ?? '') {
            'event'     => self::onEvent((string) $job['event'], (int) $job['person'], $job['segment'] ?? null, (array) ($job['meta'] ?? [])),
            'step'      => self::step((int) $job['run'], (int) $job['seq'], !empty($job['timeout'])),
            'everybody' => self::everybody((int) $job['rule'], (int) ($job['after'] ?? 0)),
            default     => 'Nothing to do: ' . json_encode($job),
        };
    }

    /**
     * An event about a person: take them into the rules it triggers, move on the runs waiting for
     * it, mark the goals it reaches.
     *
     * @param array<string, mixed> $meta What fired it, and its causation ids
     */
    public static function onEvent(string $event, int $person, ?int $segment = null, array $meta = []): string
    {
        $entered = 0;
        $trigger = ['event' => $event, 'segment' => $segment, 'eventId' => $meta['eventId'] ?? null,
            'causedBy' => $meta['causedBy'] ?? null, 'rootId' => $meta['rootId'] ?? null, 'at' => time()];
        foreach (Rules::all(Rules::RUNNING) as $rule) {
            if (self::triggeredBy($rule['definition']['trigger'], $event, $segment) && self::enroll($rule, $person, $trigger) !== null) {
                $entered++;
            }
        }
        $resumed = 0;
        foreach (self::runs()->where('personid', $person)->where('status', 'waiting')->where('waitingfor', $event)->getAllOrFail() as $run) {
            $definition = self::definition($run);
            $step       = $definition['steps'][(string) $run['step']] ?? null;
            if ($step !== null && self::advance($run, $step['next'], 0, 'resumed', ['event' => $event])) {
                $resumed++;
            }
        }
        $goals = 0;
        foreach (self::runs()->where('personid', $person)->whereIn('status', ['running', 'waiting'])->getAllOrFail() as $run) {
            $goal = self::definition($run)['goal'] ?? null;
            if ($goal !== null && $goal['event'] === $event && (int) $run['enteredat'] >= time() - $goal['days'] * 86400) {
                self::finish($run, 'goal', ['event' => $event]);
                $goals++;
            }
        }

        return "{$event} for {$person}: {$entered} entered, {$resumed} resumed, {$goals} reached a goal";
    }

    /**
     * Take a person into a rule, if its entry, its who and its exit allow.
     *
     * @param  array{id: int, name: string, status: string, version: int, definition: array<string, mixed>} $rule
     * @param  array<string, mixed> $trigger
     * @return int|null The run, or null when the person was not taken in
     */
    public static function enroll(array $rule, int $person, array $trigger): ?int
    {
        $definition = $rule['definition'];
        $before     = self::runs()->where('ruleid', $rule['id'])->where('personid', $person);
        if ($definition['entry'] === 'every') {
            $before->whereIn('status', ['running', 'waiting']);
        }
        // ponytail: no lock — two triggers in the same instant can enter a person twice; a unique
        // key per (rule, person) for "first" if that ever shows in the log.
        if ($before->limit(1)->getAllOrFail() !== []) {
            return null;
        }
        if (!Filter::fromArray($definition['who'])->matches($person)) {
            return null;
        }
        if ($definition['exit']['conditions'] !== [] && Filter::fromArray($definition['exit'])->matches($person)) {
            return null;
        }
        $now = time();
        self::db()->queryBuilder()->table('#PREFIX#rule_runs')->insert([
            'ruleid' => $rule['id'], 'version' => $rule['version'], 'personid' => $person,
            'status' => $definition['start'] === null ? 'done' : 'running', 'step' => $definition['start'], 'seq' => 0,
            'shadow' => $rule['status'] === 'shadow' ? 1 : 0, 'trigger' => json_encode($trigger, JSON_UNESCAPED_UNICODE),
            'enteredat' => $now, 'updatedat' => $now,
        ]);
        $run = (int) self::db()->getInsertId();
        self::log(['runid' => $run, 'ruleid' => $rule['id'], 'version' => $rule['version'], 'personid' => $person], null, 'entered', $trigger);
        if ($definition['start'] !== null) {
            self::schedule(['kind' => 'step', 'run' => $run, 'seq' => 0], 0, 'rule-run:' . $run . ':0');
        }

        return $run;
    }

    /**
     * Take everybody who matches a rule now into it — a page at a time, each page handing on to
     * the next.
     */
    public static function everybody(int $id, int $after = 0): string
    {
        $rule = Rules::get($id);
        if ($rule === null || !in_array($rule['status'], Rules::RUNNING, true)) {
            return "Rule {$id} is not running.";
        }
        $page    = Filter::fromArray($rule['definition']['who'])->ids($after, self::$page);
        $entered = 0;
        $trigger = ['event' => $rule['definition']['trigger']['type'], 'at' => time()];
        foreach ($page as $person) {
            if (self::enroll($rule, $person, $trigger) !== null) {
                $entered++;
            }
        }
        if (count($page) === self::$page) {
            self::schedule(['kind' => 'everybody', 'rule' => $id, 'after' => end($page)]);
        }

        return "Rule {$id}: {$entered} of " . count($page) . ' entered';
    }

    /**
     * The schedules due this minute: each takes everybody who matches it.
     *
     * @return int How many rules were due
     */
    public static function due(?\DateTimeInterface $at = null): int
    {
        if (!Installed::has('rules')) {
            return 0;
        }
        $at  ??= new \DateTimeImmutable();
        $due   = 0;
        foreach (Rules::all(Rules::RUNNING) as $rule) {
            $trigger = $rule['definition']['trigger'];
            if ($trigger['type'] === 'schedule' && (new \Pramnos\Scheduling\CronExpression($trigger['cron']))->isDue($at)) {
                self::schedule(['kind' => 'everybody', 'rule' => $rule['id']], 0, 'rule-due:' . $rule['id'] . ':' . $at->format('YmdHi'));
                $due++;
            }
        }

        return $due;
    }

    /**
     * Take one step of a run — only if the run is still where the task says it is.
     */
    public static function step(int $runId, int $seq, bool $timedOut = false): string
    {
        $run = self::runs()->where('runid', $runId)->limit(1)->getAllOrFail()[0] ?? null;
        if ($run === null || (int) $run['seq'] !== $seq || !in_array($run['status'], ['running', 'waiting'], true)
            || ($timedOut && $run['status'] !== 'waiting') || (!$timedOut && $run['status'] === 'waiting')) {
            return "Run {$runId} has moved on.";
        }
        $rule = Rules::get((int) $run['ruleid']);
        if ($rule === null) {
            return "Run {$runId}: its rule is gone.";
        }
        if (!in_array($rule['status'], Rules::RUNNING, true)) {
            // Paused: the run waits where it is, and looks again in an hour.
            self::schedule(['kind' => 'step', 'run' => $runId, 'seq' => $seq] + ($timedOut ? ['timeout' => 1] : []), 3600,
                'rule-run:' . $runId . ':' . $seq . ($timedOut ? ':t' : ''));

            return "Run {$runId}: the rule is {$rule['status']}.";
        }
        $definition = self::definition($run);
        $person     = (int) $run['personid'];
        if ($definition['exit']['conditions'] !== [] && Filter::fromArray($definition['exit'])->matches($person)) {
            self::finish($run, 'exited', Filter::fromArray($definition['exit'])->explain($person));

            return "Run {$runId}: exited.";
        }
        $name = (string) $run['step'];
        $step = $definition['steps'][$name] ?? null;
        if ($step === null) {
            self::finish($run, 'failed', ['reason' => 'no step ' . $name]);

            return "Run {$runId}: no step {$name}.";
        }

        return match ($step['type']) {
            'action'     => self::act($run, $rule, $definition, $name, $step),
            'wait'       => self::advance($run, $step['next'], $step['seconds'], 'waiting', ['seconds' => $step['seconds']]) ? "Run {$runId}: waiting." : "Run {$runId}: moved on.",
            'wait_event' => self::waitFor($run, $name, $step, $timedOut),
            'branch'     => self::branch($run, $name, $step),
            'split'      => self::split($run, $name, $step),
        };
    }

    /**
     * An action: its hours, its consent, its caps — then done, or logged in shadow mode.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $step
     */
    private static function act(array $run, array $rule, array $definition, string $name, array $step): string
    {
        $runId   = (int) $run['runid'];
        $person  = (int) $run['personid'];
        $action  = Actions::get($step['action']);
        $channel = $action['channel'] ?? null;
        if ($action === null) {
            self::log($run, $name, 'error', ['reason' => 'no action ' . $step['action']]);

            return self::advance($run, $step['next'], 0, null) ? "Run {$runId}: no action." : '';
        }
        if ($channel !== null && $definition['hours'] !== null) {
            $wait = self::untilOpen($definition['hours'], time());
            if ($wait > 0) {
                self::log($run, $name, 'waiting', ['reason' => 'hours', 'seconds' => $wait]);
                self::schedule(['kind' => 'step', 'run' => $runId, 'seq' => (int) $run['seq']], $wait, 'rule-run:' . $runId . ':' . $run['seq'] . ':h');

                return "Run {$runId}: waiting for its hours.";
            }
        }
        if ($channel !== null && $definition['consent'] !== null && !ConsentLists::allows($definition['consent'], $person)) {
            self::log($run, $name, 'skipped', ['reason' => 'consent', 'list' => $definition['consent']], $channel);
            self::advance($run, $step['next'], 0, null);

            return "Run {$runId}: no consent.";
        }
        if ($channel !== null && ($cap = self::capReached($person, $channel, $definition['caps'])) !== null) {
            self::log($run, $name, 'skipped', ['reason' => 'cap', 'cap' => $cap], $channel);
            self::advance($run, $step['next'], 0, null);

            return "Run {$runId}: frequency cap.";
        }
        $trigger = (array) json_decode((string) ($run['trigger'] ?? ''), true);
        $context = new Context((int) $rule['id'], $rule['name'], (int) $run['version'], $name, $runId, $person, $trigger,
            $run['variant'] ?? null, (bool) (int) $run['shadow'], $definition['consent']);
        if ($context->shadow) {
            self::log($run, $name, 'shadow', ['action' => $step['action'], 'params' => $step['params']], $channel);
        } else {
            try {
                $result = (array) ($action['run'])($person, $step['params'], $context);
            } catch (\Throwable $e) {
                $result = ['status' => 'error', 'detail' => ['reason' => $e->getMessage()]];
            }
            $status = in_array($result['status'] ?? '', ['sent', 'done', 'skipped', 'error'], true) ? $result['status'] : 'done';
            self::log($run, $name, $status, (array) ($result['detail'] ?? []), $status === 'sent' ? $channel : null);
        }
        self::advance($run, $step['next'], 0, null);

        return "Run {$runId}: {$step['action']}.";
    }

    /**
     * Wait for an event, with a timeout — or, when the timeout comes first, go its other way.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $step
     */
    private static function waitFor(array $run, string $name, array $step, bool $timedOut): string
    {
        $runId = (int) $run['runid'];
        if ($timedOut) {
            self::advance($run, $step['timeout'], 0, 'timeout', ['event' => $step['event']]);

            return "Run {$runId}: timed out.";
        }
        $moved = self::db()->queryBuilder()->table('#PREFIX#rule_runs')->where('runid', $runId)->where('seq', (int) $run['seq'])
            ->update(['status' => 'waiting', 'waitingfor' => $step['event'], 'updatedat' => time()]);
        if (self::affected($moved)) {
            self::log($run, $name, 'waiting', ['event' => $step['event'], 'seconds' => $step['seconds']]);
            self::schedule(['kind' => 'step', 'run' => $runId, 'seq' => (int) $run['seq'], 'timeout' => 1], $step['seconds'],
                'rule-run:' . $runId . ':' . $run['seq'] . ':t');
        }

        return "Run {$runId}: waiting for {$step['event']}.";
    }

    /**
     * A branch: on a filter, or on what happened to a message an earlier step sent.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $step
     */
    private static function branch(array $run, string $name, array $step): string
    {
        $person = (int) $run['personid'];
        if (isset($step['filter'])) {
            $why = Filter::fromArray($step['filter'])->explain($person);
            $yes = $why['ok'];
        } else {
            $why = self::messageStatus((int) $run['runid'], $step['message']['step']);
            $yes = !empty($why[$step['message']['status']]);
        }
        self::log($run, $name, 'branch', ['yes' => $yes, 'why' => $why]);
        self::advance($run, $yes ? $step['yes'] : $step['no'], 0, null);

        return 'Run ' . $run['runid'] . ': ' . ($yes ? 'yes' : 'no') . '.';
    }

    /**
     * A split: the same person always takes the same variant of the same rule.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $step
     */
    private static function split(array $run, string $name, array $step): string
    {
        $bucket = crc32($run['ruleid'] . ':' . $name . ':' . $run['personid']) % 100;
        $chosen = end($step['variants']);
        $edge   = 0;
        foreach ($step['variants'] as $variant) {
            $edge += $variant['share'];
            if ($bucket < $edge) {
                $chosen = $variant;
                break;
            }
        }
        self::db()->queryBuilder()->table('#PREFIX#rule_runs')->where('runid', (int) $run['runid'])->update(['variant' => $chosen['key']]);
        self::log($run, $name, 'branch', ['variant' => $chosen['key']]);
        self::advance($run, $chosen['next'], 0, null);

        return 'Run ' . $run['runid'] . ': variant ' . $chosen['key'] . '.';
    }

    /**
     * What happened to the message a step of this run sent: delivered, opened, clicked.
     *
     * @return array{mail: ?int, delivered: bool, opened: bool, clicked: bool}
     */
    public static function messageStatus(int $runId, string $step): array
    {
        $sent = self::db()->queryBuilder()->table('#PREFIX#rule_log')->where('runid', $runId)->where('step', $step)
            ->where('outcome', 'sent')->orderBy('id', 'desc')->limit(1)->getAllOrFail()[0] ?? null;
        $detail = (array) json_decode((string) ($sent['detail'] ?? ''), true);
        $mail   = (int) ($detail['mail'] ?? 0);
        $answer = ['mail' => $mail > 0 ? $mail : null, 'delivered' => $sent !== null, 'opened' => false, 'clicked' => false];
        if ($mail > 0) {
            try {
                $row = self::db()->queryBuilder()->table('pramnos.emailtracking')->select(['opens', 'clicks'])
                    ->where('mailid', $mail)->limit(1)->getAllOrFail()[0] ?? null;
                $answer['opened']  = (int) ($row['opens'] ?? 0) > 0 || (int) ($row['clicks'] ?? 0) > 0;
                $answer['clicked'] = (int) ($row['clicks'] ?? 0) > 0;
            } catch (\Throwable) {
                // no tracking here: delivered is all that is known
            }
        }

        return $answer;
    }

    /**
     * The first frequency cap a message on this channel would break, or null.
     *
     * @param  array<string, array{max: int, days: int}> $ruleCaps
     * @return array{max: int, days: int, sent: int}|null
     */
    private static function capReached(int $person, string $channel, array $ruleCaps): ?array
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $caps = array_filter([(array) ($ruleCaps[$channel] ?? []),
            (array) (is_object($app) ? ($app->applicationInfo['audience']['caps'][$channel] ?? []) : [])]);
        foreach ($caps as $cap) {
            $sent = count(self::db()->queryBuilder()->table('#PREFIX#rule_log')->select(['id'])->where('personid', $person)
                ->where('outcome', 'sent')->where('channel', $channel)->where('at', '>=', time() - (int) $cap['days'] * 86400)
                ->limit((int) $cap['max'])->getAllOrFail());
            if ($sent >= (int) $cap['max']) {
                return ['max' => (int) $cap['max'], 'days' => (int) $cap['days'], 'sent' => $sent];
            }
        }

        return null;
    }

    /**
     * Seconds until the sending hours open — 0 when they are open. A window may cross midnight.
     *
     * @param array{from: string, to: string} $hours
     */
    public static function untilOpen(array $hours, int $now): int
    {
        $day  = new \DateTimeImmutable('@' . $now);
        $day  = $day->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $from = (int) substr($hours['from'], 0, 2) * 60 + (int) substr($hours['from'], 3, 2);
        $to   = (int) substr($hours['to'], 0, 2) * 60 + (int) substr($hours['to'], 3, 2);
        $at   = (int) $day->format('G') * 60 + (int) $day->format('i');
        $open = $from <= $to ? ($at >= $from && $at < $to) : ($at >= $from || $at < $to);
        if ($open) {
            return 0;
        }
        $opens = $day->setTime(intdiv($from, 60), $from % 60);
        if ($opens->getTimestamp() <= $now) {
            $opens = $opens->modify('+1 day');
        }

        return $opens->getTimestamp() - $now;
    }

    /**
     * Move a run on from where it is — only if it still is — and queue its next step.
     *
     * @param array<string, mixed>       $run
     * @param array<string, mixed>|null  $detail
     */
    private static function advance(array $run, ?string $next, int $delay, ?string $outcome, ?array $detail = null): bool
    {
        $seq   = (int) $run['seq'];
        $moved = self::db()->queryBuilder()->table('#PREFIX#rule_runs')->where('runid', (int) $run['runid'])->where('seq', $seq)
            ->update(['step' => $next, 'seq' => $seq + 1, 'status' => $next === null ? 'done' : 'running', 'waitingfor' => null, 'updatedat' => time()]);
        if (!self::affected($moved)) {
            return false;
        }
        if ($outcome !== null) {
            self::log($run, (string) $run['step'], $outcome, $detail);
        }
        if ($next === null) {
            self::log($run, null, 'finished', null);
        } else {
            self::schedule(['kind' => 'step', 'run' => (int) $run['runid'], 'seq' => $seq + 1], $delay, 'rule-run:' . $run['runid'] . ':' . ($seq + 1));
        }

        return true;
    }

    /**
     * End a run: exited, reached its goal, or failed.
     *
     * @param array<string, mixed>      $run
     * @param array<string, mixed>|null $detail
     */
    private static function finish(array $run, string $status, ?array $detail): void
    {
        $moved = self::db()->queryBuilder()->table('#PREFIX#rule_runs')->where('runid', (int) $run['runid'])->where('seq', (int) $run['seq'])
            ->update(['status' => $status, 'step' => null, 'seq' => (int) $run['seq'] + 1, 'waitingfor' => null, 'updatedat' => time()]);
        if (self::affected($moved)) {
            self::log($run, null, $status, $detail);
        }
    }

    /**
     * A row of the run log.
     *
     * @param array<string, mixed>      $run
     * @param array<string, mixed>|null $detail
     */
    private static function log(array $run, ?string $step, string $outcome, ?array $detail, ?string $channel = null): void
    {
        self::db()->queryBuilder()->table('#PREFIX#rule_log')->insert([
            'runid' => (int) $run['runid'], 'ruleid' => (int) $run['ruleid'], 'version' => (int) $run['version'],
            'personid' => (int) $run['personid'], 'step' => $step, 'outcome' => $outcome, 'channel' => $channel,
            'detail' => $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'at' => time(),
        ]);
    }

    /**
     * Whether a trigger is this event.
     *
     * @param array<string, mixed> $trigger
     */
    private static function triggeredBy(array $trigger, string $event, ?int $segment): bool
    {
        return match ($trigger['type']) {
            'event'           => $trigger['event'] === $event,
            'segment_entered' => $event === \Pramnos\Audience\SegmentEntered::EVENT && $segment === $trigger['segment'],
            'segment_left'    => $event === \Pramnos\Audience\SegmentLeft::EVENT && $segment === $trigger['segment'],
            default           => false,
        };
    }

    /**
     * The definition a run follows: its version's.
     *
     * @param  array<string, mixed> $run
     * @return array<string, mixed>
     */
    private static function definition(array $run): array
    {
        return Rules::version((int) $run['ruleid'], (int) $run['version']) ?? ['steps' => [], 'exit' => ['conditions' => []], 'goal' => null];
    }

    /** The days the run log keeps when the application says nothing. */
    public const DEFAULT_LOG_DAYS = 90;

    /**
     * Delete run-log rows past `audience.rule_log_days` (90; 0 keeps them). Runs are kept.
     *
     * @return int|null Rows deleted, or null when the log is kept whole or not installed
     */
    public static function prune(?int $days = null): ?int
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $days ??= (int) (is_object($app) ? ($app->applicationInfo['audience']['rule_log_days'] ?? self::DEFAULT_LOG_DAYS) : self::DEFAULT_LOG_DAYS);
        if ($days <= 0 || !Installed::has('rule_log')) {
            return null;
        }
        $old   = self::db()->queryBuilder()->table('#PREFIX#rule_log')->where('at', '<', time() - $days * 86400);
        $count = count((clone $old)->select(['id'])->getAllOrFail());
        if ($count > 0) {
            $old->delete();
        }

        return $count;
    }

    /** Whether an update changed a row — the run was still where it was read. */
    private static function affected(mixed $result): bool
    {
        return is_object($result) && method_exists($result, 'getAffectedRows') ? (int) $result->getAffectedRows() > 0 : (bool) $result;
    }

    private static function runs(): \Pramnos\Database\QueryBuilder
    {
        return self::db()->queryBuilder()->table('#PREFIX#rule_runs');
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
