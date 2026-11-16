<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;
use Pramnos\Database\Expression;
use Pramnos\Database\Instant;

/**
 * Steps over person events, in order: how many of the people who did the first also did each one
 * after it, and how long that usually took — a card and a query over the event log
 * ({@see PersonEvents::LOG}), not a stored metric.
 *
 * ```php
 * Funnels::run(['signup', 'profile.completed', 'payment.made']);
 * Funnels::run(['signup', 'payment.made'], ['from' => $since, 'to' => time(), 'by' => 'usertype']);
 * Funnels::run(['signup', 'payment.made'], ['by' => 'segment:12']);
 * ```
 *
 * A step counts a person once they have done it at all in the window — the log keeps only when
 * an event happened, not a session's order, so a step is not refused for coming "too early". The
 * time between two steps is only of the people who did both, the median of the gap from the
 * first's own first occurrence to the second's (a mean is pulled far by the slow few).
 *
 * `by` breaks the first step's own people down: `segment:<id>` a plain membership split, an
 * audience attribute's own key its values through {@see Filter::breakdown()} — the same count of
 * people every other breakdown in the audience module goes through, restricted to this cohort by
 * its own `userid` attribute ({@see Attributes}).
 */
final class Funnels
{
    /**
     * @param list<string>                                     $events  At least two declared event keys
     * @param array{from?: int, to?: int, by?: string, limit?: int} $options
     * @return array{steps: list<array{event: string, label: string, people: int, share: float, median_seconds: ?int}>, by: array<string, int>}
     * @throws \InvalidArgumentException For fewer than two distinct events
     */
    public static function run(array $events, array $options = []): array
    {
        $events = array_values(array_unique($events));
        if (count($events) < 2) {
            throw new \InvalidArgumentException(t('A funnel needs at least two steps.'));
        }
        $db   = Database::getInstance();
        $from = isset($options['from']) ? (int) $options['from'] : null;
        $to   = isset($options['to']) ? (int) $options['to'] : null;
        $first = self::occurrences($db, $events[0], $from, $to);
        $steps = [['event' => $events[0], 'label' => PersonEvents::label($events[0]), 'people' => count($first), 'share' => 1.0, 'median_seconds' => null]];
        $carried = $first;
        for ($i = 1; $i < count($events); $i++) {
            $next = self::occurrences($db, $events[$i], $from, $to);
            $both = array_intersect_key($carried, $next);
            $gaps = [];
            foreach ($both as $person => $at) {
                $gaps[] = abs($next[$person] - $at);
            }
            sort($gaps);
            $steps[] = [
                'event' => $events[$i], 'label' => PersonEvents::label($events[$i]), 'people' => count($both),
                'share' => $first === [] ? 0.0 : count($both) / count($first),
                'median_seconds' => $gaps === [] ? null : $gaps[(int) floor((count($gaps) - 1) / 2)],
            ];
            // The next step's own occurrence carries forward, so "time between" is always against
            // the step right before it, not the funnel's first.
            $carried = $next;
        }

        return ['steps' => $steps, 'by' => isset($options['by']) ? self::by($db, (string) $options['by'], array_keys($first), max(1, (int) ($options['limit'] ?? 20))) : []];
    }

    /**
     * Each value of a segment or an audience attribute among the people given, largest first.
     *
     * @param list<int> $ids
     * @return array<string, int>
     */
    private static function by(Database $db, string $key, array $ids, int $limit): array
    {
        if ($ids === []) {
            return [];
        }
        if (str_starts_with($key, 'segment:')) {
            $segment = (int) substr($key, 8);
            $members = (array) $db->queryBuilder()->table('pramnos.segment_members')->select(['userid'])
                ->where('segmentid', $segment)->whereNull('left_at')->whereIn('userid', $ids)->getAllOrFail();

            return ['Member' => count($members), 'Not a member' => count($ids) - count($members)];
        }
        $filter = Filter::fromArray(['conditions' => [['attribute' => Attributes::KEY, 'op' => 'one_of', 'value' => $ids]]], $db);

        return array_column($filter->breakdown($key, $limit), 'count', 'label');
    }

    /**
     * Every distinct person's first occurrence of an event in the window, personid => unix time.
     *
     * @return array<int, int>
     */
    private static function occurrences(Database $db, string $event, ?int $from, ?int $to): array
    {
        $epoch = $db->type === 'postgresql' ? 'EXTRACT(EPOCH FROM MIN(person_log.created_at))::bigint' : 'UNIX_TIMESTAMP(MIN(person_log.created_at))';
        $query = PersonEvents::occurrences($db->queryBuilder(), $event)
            ->select(['person_log.itemid', new Expression($epoch . ' AS at')])->groupBy('person_log.itemid');
        if ($from !== null) {
            $query->where('person_log.created_at', '>=', Instant::at($db, $from));
        }
        if ($to !== null) {
            $query->where('person_log.created_at', '<=', Instant::at($db, $to));
        }
        $out = [];
        foreach ($query->getAllOrFail() as $row) {
            $out[(int) $row['itemid']] = (int) $row['at'];
        }

        return $out;
    }
}
