<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\Filter;
use Pramnos\Database\Database;
use Pramnos\Database\Expression;

/**
 * Trying a rule before it runs: why one person would or would not be taken in and what each step
 * would do for them; how many the last days would have brought in; a test send to oneself with a
 * real person's details; what a new version changes about who comes in.
 *
 * ```php
 * Tester::why($rule, $userid);                     // entry, who, exit, and each step for this person
 * Tester::simulate($rule, days: 7);                // ['entered' => 486, 'messages' => ['email' => 486, 'sms' => 486], …]
 * Tester::testSend($rule, 'mail', $userid, $me);   // the step's action, to me, filled for them
 * Tester::diff($rule, 3, 4);                       // ['before' => 812, 'after' => 790, 'in' => 12, 'out' => 34]
 * ```
 */
final class Tester
{
    /**
     * Why a person would or would not be taken in, and what each step would decide for them now.
     *
     * @return array{enters: bool, reasons: list<string>, who: array<string, mixed>, exit: array<string, mixed>|null, steps: array<string, array<string, mixed>>}
     */
    public static function why(int $rule, int $person): array
    {
        $found      = Rules::get($rule) ?? throw new \InvalidArgumentException("No rule {$rule}.");
        $definition = $found['definition'];
        $reasons    = [];
        $runs       = self::db()->queryBuilder()->table('#PREFIX#rule_runs')->where('ruleid', $rule)->where('personid', $person)->getAllOrFail();
        if ($definition['entry'] === 'first' && $runs !== []) {
            $reasons[] = 'entered before';
        }
        if ($definition['entry'] === 'every' && array_filter($runs, static fn (array $r): bool => in_array($r['status'], ['running', 'waiting'], true)) !== []) {
            $reasons[] = 'already on its way through';
        }
        $who = Filter::fromArray($definition['who'])->explain($person);
        if (!$who['ok']) {
            $reasons[] = 'who does not match';
        }
        $exit = $definition['exit']['conditions'] === [] ? null : Filter::fromArray($definition['exit'])->explain($person);
        if ($exit !== null && $exit['ok']) {
            $reasons[] = 'the exit matches';
        }
        $steps = [];
        foreach ($definition['steps'] as $name => $step) {
            $steps[$name] = match ($step['type']) {
                'action' => self::actionOutlook($definition, $step, $person),
                'branch' => isset($step['filter']) ? ['branch' => Filter::fromArray($step['filter'])->explain($person)] : ['branch' => 'on the message sent at ' . $step['message']['step']],
                default  => ['type' => $step['type']],
            };
        }

        return ['enters' => $reasons === [], 'reasons' => $reasons, 'who' => $who, 'exit' => $exit, 'steps' => $steps];
    }

    /**
     * How many the last days would have brought in, had the rule been on — counted from what those
     * days recorded, filtered by who matches now — and the messages each channel would have sent
     * at most.
     *
     * @return array{entered: int, messages: array<string, int>, basis: string}
     */
    public static function simulate(int|array $rule, int $days = 7): array
    {
        $definition = is_int($rule) ? ((Rules::get($rule) ?? throw new \InvalidArgumentException("No rule {$rule}."))['definition']) : Definition::check($rule);
        $since      = time() - $days * 86400;
        $who        = Filter::fromArray($definition['who']);
        $trigger    = $definition['trigger'];
        [$query, $column, $basis] = match ($trigger['type']) {
            'event' => [self::db()->queryBuilder()->table('#PREFIX#person_event_log')->select(['personid'])
                ->where('event', $trigger['event'])->where('at', '>=', $since), 'personid', 'the event log'],
            'segment_entered' => [self::db()->queryBuilder()->table('#PREFIX#segment_members')->select(['userid'])
                ->where('segmentid', $trigger['segment'])->where('entered_at', '>=', $since), 'userid', 'entries into the segment'],
            'segment_left' => [self::db()->queryBuilder()->table('#PREFIX#segment_members')->select(['userid'])
                ->where('segmentid', $trigger['segment'])->where('left_at', '>=', $since), 'userid', 'departures from the segment'],
            default => [null, '', 'who matches now'],
        };
        if ($query === null) {
            $entered = $who->count();
        } else {
            $people  = array_values(array_unique(array_map(static fn (array $r): int => (int) $r[$column], $query->getAllOrFail())));
            $entered = 0;
            foreach (array_chunk($people, 1000) as $chunk) {
                $entered += (int) ($who->query()->select([new Expression('COUNT(DISTINCT users.userid) AS n')])
                    ->whereIn('users.userid', $chunk)->getAllOrFail()[0]['n'] ?? 0);
            }
        }
        $messages = [];
        foreach ($definition['steps'] as $step) {
            $channel = $step['type'] === 'action' ? (Actions::get($step['action'])['channel'] ?? null) : null;
            if ($channel !== null) {
                $messages[$channel] = ($messages[$channel] ?? 0) + $entered;
            }
        }

        return ['entered' => $entered, 'messages' => $messages, 'basis' => $basis];
    }

    /**
     * A step's action, done now for a person's details but sent to another account — a test send
     * to oneself. Logged nowhere: it is not part of any run.
     *
     * @return array{status: string, detail?: array<string, mixed>}
     */
    public static function testSend(int $rule, string $step, int $person, int $to): array
    {
        $found  = Rules::get($rule) ?? throw new \InvalidArgumentException("No rule {$rule}.");
        $action = $found['definition']['steps'][$step] ?? null;
        if ($action === null || $action['type'] !== 'action' || Actions::get($action['action']) === null) {
            throw new \InvalidArgumentException("Rule {$rule} has no action step {$step}.");
        }
        $context = new Context($rule, $found['name'], $found['version'], $step, 0, $person, ['test' => true], null, false,
            $found['definition']['consent'], $to);

        return (array) (Actions::get($action['action'])['run'])($person, $action['params'], $context);
    }

    /**
     * Who a new version takes in that the old one did not, and who it leaves out — now.
     *
     * @return array{before: int, after: int, in: int, out: int}
     */
    public static function diff(int $rule, int $from, int $to): array
    {
        $old = Rules::version($rule, $from) ?? throw new \InvalidArgumentException("Rule {$rule} has no version {$from}.");
        $new = Rules::version($rule, $to) ?? throw new \InvalidArgumentException("Rule {$rule} has no version {$to}.");
        $before = Filter::fromArray($old['who'])->count();
        $after  = Filter::fromArray($new['who'])->count();
        $both   = Filter::fromArray(['match' => 'all', 'conditions' => [$old['who'], $new['who']]])->count();

        return ['before' => $before, 'after' => $after, 'in' => $after - $both, 'out' => $before - $both];
    }

    /**
     * What an action step would do for a person now: the consent and caps it would meet.
     *
     * @param  array<string, mixed> $definition
     * @param  array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function actionOutlook(array $definition, array $step, int $person): array
    {
        $channel = Actions::get($step['action'])['channel'] ?? null;
        $outlook = ['action' => $step['action'], 'channel' => $channel];
        if ($channel !== null && $definition['consent'] !== null) {
            $outlook['consent'] = ConsentLists::allows($definition['consent'], $person);
        }

        return $outlook;
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
