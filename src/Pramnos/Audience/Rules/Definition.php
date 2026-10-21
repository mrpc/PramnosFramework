<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

use Pramnos\Audience\Filter;
use Pramnos\Database\Database;

/**
 * A rule's definition — when, who, what in which order, until when — checked and shaped.
 *
 * ```json
 * {
 *   "trigger": {"type": "segment_entered", "segment": 4},
 *   "who":     {"conditions": [{"attribute": "language", "op": "is", "value": "el"}]},
 *   "entry":   "first",
 *   "start":   "mail",
 *   "steps": {
 *     "mail":  {"type": "action", "action": "email", "params": {"template": "premium.expiring"}, "next": "wait"},
 *     "wait":  {"type": "wait_event", "event": "payment.made", "seconds": 259200, "next": null, "timeout": "check"},
 *     "check": {"type": "branch", "message": {"step": "mail", "status": "opened"}, "yes": "sms", "no": null},
 *     "sms":   {"type": "action", "action": "sms", "params": {"text": "…"}, "next": null}
 *   },
 *   "exit":    {"conditions": [{"event": "payment.made", "op": "last_within_days", "value": 1}]},
 *   "goal":    {"event": "payment.made", "days": 10},
 *   "consent": 3,
 *   "hours":   {"from": "09:00", "to": "21:00"},
 *   "caps":    {"email": {"max": 1, "days": 1}}
 * }
 * ```
 *
 * Triggers: `event` (a named event about a person), `segment_entered` / `segment_left` (starts or
 * stops matching), `schedule` (a cron expression: everybody who matches then), `once` (everybody
 * who matches when it is switched on). Steps: `action`, `wait` (seconds), `wait_event` (an event,
 * with a timeout and an exit for each), `branch` (on a filter, or on what happened to a message an
 * earlier step sent: opened, clicked, delivered), `split` (variants by share). Every step names
 * the next, or null to finish; a step cannot lead back to itself.
 *
 * Checked once, when saved: an unknown action, a step that names one that does not exist, a loop,
 * a filter that does not check — refused with where it is, so a rule that is saved can run.
 */
final class Definition
{
    public const TRIGGERS = ['event', 'segment_entered', 'segment_left', 'schedule', 'once'];

    public const STEPS = ['action', 'wait', 'wait_event', 'branch', 'split'];

    public const MESSAGE_STATUSES = ['opened', 'clicked', 'delivered'];

    /**
     * The definition checked and shaped.
     *
     * @param  array<string, mixed>|string $definition Or its JSON
     * @return array<string, mixed>
     * @throws \InvalidArgumentException What is wrong, and where
     */
    public static function check(array|string $definition): array
    {
        if (is_string($definition)) {
            $definition = json_decode($definition, true);
            if (!is_array($definition)) {
                throw new \InvalidArgumentException('The rule is not valid JSON.');
            }
        }
        $steps = [];
        foreach ((array) ($definition['steps'] ?? []) as $id => $step) {
            $id = (string) $id;
            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $id) !== 1) {
                throw new \InvalidArgumentException("steps: a step is named with letters, digits, . _ - ({$id}).");
            }
            $steps[$id] = self::step($id, (array) $step);
        }
        $start = $definition['start'] ?? ($steps === [] ? null : array_key_first($steps));
        if ($start !== null && !isset($steps[(string) $start])) {
            throw new \InvalidArgumentException("start: no step {$start}.");
        }
        foreach ($steps as $id => $step) {
            foreach (self::exits($step) as $next) {
                if ($next !== null && !isset($steps[$next])) {
                    throw new \InvalidArgumentException("steps.{$id}: no step {$next}.");
                }
            }
            if ($step['type'] === 'branch' && isset($step['message'])) {
                $sent = $steps[$step['message']['step']] ?? null;
                if ($sent === null || $sent['type'] !== 'action') {
                    throw new \InvalidArgumentException("steps.{$id}: a branch on a message names an action step.");
                }
            }
        }
        self::refuseLoops($steps);

        $entry = (string) ($definition['entry'] ?? 'first');
        if ($entry !== 'first' && $entry !== 'every') {
            throw new \InvalidArgumentException('entry: first or every.');
        }

        return [
            'trigger' => self::trigger((array) ($definition['trigger'] ?? [])),
            'who'     => self::filter($definition['who'] ?? [], 'who'),
            'entry'   => $entry,
            'start'   => $start === null ? null : (string) $start,
            'steps'   => $steps,
            'exit'    => self::filter($definition['exit'] ?? [], 'exit'),
            'goal'    => self::goal($definition['goal'] ?? null),
            'consent' => empty($definition['consent']) ? null : (int) $definition['consent'],
            'hours'   => self::hours($definition['hours'] ?? null),
            'caps'    => self::caps((array) ($definition['caps'] ?? [])),
        ];
    }

    /**
     * Where a step can go next.
     *
     * @param  array<string, mixed> $step
     * @return list<string|null>
     */
    public static function exits(array $step): array
    {
        return match ($step['type']) {
            'wait_event' => [$step['next'], $step['timeout']],
            'branch'     => [$step['yes'], $step['no']],
            'split'      => array_column($step['variants'], 'next'),
            default      => [$step['next']],
        };
    }

    /**
     * The segments a definition reads — its trigger, and its filters.
     *
     * @param  array<string, mixed> $definition Checked
     * @return list<int>
     */
    public static function segments(array $definition): array
    {
        $ids = array_merge(Filter::segmentsIn($definition['who']), Filter::segmentsIn($definition['exit']));
        foreach ($definition['steps'] as $step) {
            if ($step['type'] === 'branch' && isset($step['filter'])) {
                array_push($ids, ...Filter::segmentsIn($step['filter']));
            }
        }
        if (isset($definition['trigger']['segment'])) {
            $ids[] = (int) $definition['trigger']['segment'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * The event names a definition listens for: its trigger, its waits, its goal.
     *
     * @param  array<string, mixed> $definition Checked
     * @return list<string>
     */
    public static function events(array $definition): array
    {
        $names = [];
        $trigger = $definition['trigger'];
        $names[] = match ($trigger['type']) {
            'event'           => $trigger['event'],
            'segment_entered' => \Pramnos\Audience\SegmentEntered::EVENT,
            'segment_left'    => \Pramnos\Audience\SegmentLeft::EVENT,
            default           => null,
        };
        foreach ($definition['steps'] as $step) {
            if ($step['type'] === 'wait_event') {
                $names[] = $step['event'];
            }
        }
        $names[] = $definition['goal']['event'] ?? null;

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * @param  array<string, mixed> $trigger
     * @return array<string, mixed>
     */
    private static function trigger(array $trigger): array
    {
        $type = (string) ($trigger['type'] ?? '');

        return match ($type) {
            'event'                           => ['type' => 'event', 'event' => self::eventName($trigger['event'] ?? '', 'trigger')],
            'segment_entered', 'segment_left' => ['type' => $type, 'segment' => self::id($trigger['segment'] ?? 0, 'trigger', 'a segment')],
            'schedule'                        => ['type' => 'schedule', 'cron' => self::cron((string) ($trigger['cron'] ?? ''))],
            'once'                            => ['type' => 'once'],
            default                           => throw new \InvalidArgumentException('trigger: one of ' . implode(', ', self::TRIGGERS) . '.'),
        };
    }

    /**
     * @param  array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function step(string $id, array $step): array
    {
        $path = "steps.{$id}";
        $type = (string) ($step['type'] ?? '');
        $next = static fn (mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;

        return match ($type) {
            'action' => [
                'type'   => 'action',
                'action' => self::action((string) ($step['action'] ?? ''), $path),
                'params' => (array) ($step['params'] ?? []),
                'next'   => $next($step['next'] ?? null),
            ],
            'wait' => [
                'type'    => 'wait',
                'seconds' => self::seconds($step['seconds'] ?? 0, $path),
                'next'    => $next($step['next'] ?? null),
            ],
            'wait_event' => [
                'type'    => 'wait_event',
                'event'   => self::eventName($step['event'] ?? '', $path),
                'seconds' => self::seconds($step['seconds'] ?? 0, $path),
                'next'    => $next($step['next'] ?? null),
                'timeout' => $next($step['timeout'] ?? null),
            ],
            'branch' => self::branch($step, $path) + ['yes' => $next($step['yes'] ?? null), 'no' => $next($step['no'] ?? null)],
            'split'  => ['type' => 'split', 'variants' => self::variants((array) ($step['variants'] ?? []), $path, $next)],
            default  => throw new \InvalidArgumentException("{$path}: a step is one of " . implode(', ', self::STEPS) . '.'),
        };
    }

    /**
     * A branch on a filter, or on what happened to a message.
     *
     * @param  array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function branch(array $step, string $path): array
    {
        if (isset($step['message'])) {
            $status = (string) ($step['message']['status'] ?? '');
            if (!in_array($status, self::MESSAGE_STATUSES, true)) {
                throw new \InvalidArgumentException("{$path}: a message was " . implode(', ', self::MESSAGE_STATUSES) . '.');
            }

            return ['type' => 'branch', 'message' => ['step' => (string) ($step['message']['step'] ?? ''), 'status' => $status]];
        }

        return ['type' => 'branch', 'filter' => self::filter($step['filter'] ?? [], $path)];
    }

    /**
     * @param  list<mixed> $variants
     * @return list<array{key: string, share: int, next: ?string}>
     */
    private static function variants(array $variants, string $path, callable $next): array
    {
        $checked = [];
        $total   = 0;
        foreach (array_values($variants) as $i => $variant) {
            $key   = trim((string) ($variant['key'] ?? chr(65 + $i)));
            $share = (int) ($variant['share'] ?? 0);
            if ($key === '' || mb_strlen($key) > 32 || $share < 1) {
                throw new \InvalidArgumentException("{$path}.variants.{$i}: a variant has a key and a share of at least 1.");
            }
            $total    += $share;
            $checked[] = ['key' => $key, 'share' => $share, 'next' => $next($variant['next'] ?? null)];
        }
        if (count($checked) < 2 || $total !== 100) {
            throw new \InvalidArgumentException("{$path}: a split has two variants or more, their shares adding up to 100.");
        }

        return $checked;
    }

    /** @return array<string, mixed> */
    private static function filter(mixed $filter, string $path): array
    {
        try {
            return Filter::fromArray(is_array($filter) || is_string($filter) ? $filter : [], new Database())->toArray();
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($path . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array{event: string, days: int}|null */
    private static function goal(mixed $goal): ?array
    {
        if (empty($goal)) {
            return null;
        }
        $days = (int) ($goal['days'] ?? 0);
        if ($days < 1) {
            throw new \InvalidArgumentException('goal: an event within a number of days.');
        }

        return ['event' => self::eventName($goal['event'] ?? '', 'goal'), 'days' => $days];
    }

    /** @return array{from: string, to: string}|null */
    private static function hours(mixed $hours): ?array
    {
        if (empty($hours)) {
            return null;
        }
        $from = (string) ($hours['from'] ?? '');
        $to   = (string) ($hours['to'] ?? '');
        foreach ([$from, $to] as $time) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw new \InvalidArgumentException('hours: from and to as HH:MM.');
            }
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array<string, mixed> $caps
     * @return array<string, array{max: int, days: int}>
     */
    private static function caps(array $caps): array
    {
        $checked = [];
        foreach ($caps as $channel => $cap) {
            $max  = (int) ($cap['max'] ?? 0);
            $days = (int) ($cap['days'] ?? 0);
            if ($max < 1 || $days < 1) {
                throw new \InvalidArgumentException("caps.{$channel}: at most a number of messages in a number of days.");
            }
            $checked[(string) $channel] = ['max' => $max, 'days' => $days];
        }

        return $checked;
    }

    /**
     * Refuse a step that can lead back to itself: a rule runs forward to its end.
     *
     * @param array<string, array<string, mixed>> $steps
     */
    private static function refuseLoops(array $steps): void
    {
        $state = [];
        $visit = static function (string $id) use (&$visit, &$state, $steps): void {
            if (($state[$id] ?? 0) === 2) {
                return;
            }
            if (($state[$id] ?? 0) === 1) {
                throw new \InvalidArgumentException("steps.{$id}: a step cannot lead back to itself.");
            }
            $state[$id] = 1;
            foreach (Definition::exits($steps[$id]) as $next) {
                if ($next !== null) {
                    $visit($next);
                }
            }
            $state[$id] = 2;
        };
        foreach (array_keys($steps) as $id) {
            $visit($id);
        }
    }

    private static function action(string $key, string $path): string
    {
        if (Actions::get($key) === null) {
            throw new \InvalidArgumentException("{$path}: no action {$key}.");
        }

        return $key;
    }

    private static function eventName(mixed $name, string $path): string
    {
        $name = trim((string) $name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException("{$path}: an event is named, in at most 100 characters.");
        }

        return $name;
    }

    private static function id(mixed $id, string $path, string $what): int
    {
        if (!is_numeric($id) || (int) $id < 1) {
            throw new \InvalidArgumentException("{$path}: {$what}, by id.");
        }

        return (int) $id;
    }

    private static function seconds(mixed $seconds, string $path): int
    {
        if (!is_numeric($seconds) || (int) $seconds < 60) {
            throw new \InvalidArgumentException("{$path}: a wait is a minute or more, in seconds.");
        }

        return (int) $seconds;
    }

    private static function cron(string $cron): string
    {
        try {
            new \Pramnos\Scheduling\CronExpression($cron);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('trigger: a schedule is a cron expression (' . $e->getMessage() . ').', 0, $e);
        }

        return $cron;
    }
}
