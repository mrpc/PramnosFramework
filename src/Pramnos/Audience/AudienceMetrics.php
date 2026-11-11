<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;
use Pramnos\Metrics\Catalogue;
use Pramnos\Metrics\Dimension;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Window;

/**
 * The audience as metrics, with no declaration of the application's: each segment's size over time,
 * who entered and who left it, and each person event's occurrences and people. A segment's size
 * becomes a chart, a goal or an alert ("the at-risk segment grew 20% this week"), and an event
 * declared for the audience is a metric as it stands.
 *
 *   - `audience.segment.members`  people in a segment at the end of each period (a level, by `segment`)
 *   - `audience.segment.entered`  who entered, `audience.segment.left` who left (flows, by `segment`)
 *   - `event.<key>`               a person event's occurrences; `event.<key>.people` the people who did it
 *
 * The first working out of a segment puts everybody in at once, silently: it is its start, not
 * people entering ({@see Segments}), so it is not counted as entered. A segment's past is as long
 * as its stays are kept (`audience.segment_history_days`). An event's occurrences are read from
 * the change log, as long as it keeps them (two years).
 *
 * Loaded by the metrics where the tables are there; `'metrics' => ['audience' => false]` in app.php
 * leaves them out, and a metric the application registers under the same name wins.
 */
final class AudienceMetrics implements Catalogue
{
    /** The segments' stays. */
    private const STAYS = 'pramnos.segment_members';

    /** @return iterable<Metric|Dimension> */
    public function metrics(): iterable
    {
        $schema = \Pramnos\Framework\Factory::getDatabase()->schema();
        if ($schema->hasTable(self::STAYS)) {
            yield Dimension::of('segment', t('Segment'))->values(static fn (): array => array_column(array_map(
                static fn (array $s): array => ['id' => (string) $s['id'], 'name' => $s['name']], Segments::all()), 'name', 'id'));
            yield Metric::level('audience.segment.members', t('People in a segment'))->aggregate(Metric::DISTINCT)->dimensions(['segment'])
                ->keeps([['segment']])->knowsThePast()->describe(t('The people in each segment at the end of the period'))->source(self::members(...))
                ->rows(static fn (Window $w, array $filters, Database $db): array => self::people(null, $w, $filters, $db));
            yield Metric::flow('audience.segment.entered', t('Entered a segment'))->dimensions(['segment'])
                ->describe(t('The people who entered each segment in the period, its first working out not counted'))->source(static fn (Window $w, array $by, Database $db) => self::moved('entered_at', $w, $by, $db))
                ->rows(static fn (Window $w, array $filters, Database $db): array => self::people('entered_at', $w, $filters, $db));
            yield Metric::flow('audience.segment.left', t('Left a segment'))->dimensions(['segment'])
                ->describe(t('The people who left each segment in the period'))->source(static fn (Window $w, array $by, Database $db) => self::moved('left_at', $w, $by, $db))
                ->rows(static fn (Window $w, array $filters, Database $db): array => self::people('left_at', $w, $filters, $db));
        }
        if ($schema->hasTable(PersonEvents::LOG)) {
            foreach (PersonEvents::all() as $key => $event) {
                $name = 'event.' . $key;
                yield Metric::flow($name, $event['label'])->from(PersonEvents::LOG)->at('created_at')->count()
                    ->where('entity', 'user')->where('event', $key)->describe(sprintf(t('How many times: %s'), $event['label']))
                    ->rows(['userid' => 'itemid', 'at' => 'created_at']);
                yield Metric::flow($name . '.people', sprintf(t('People: %s'), $event['label']))->from(PersonEvents::LOG)->at('created_at')
                    ->countDistinct('itemid')->where('entity', 'user')->where('event', $key)->describe(sprintf(t('How many people: %s'), $event['label']))
                    ->rows(['userid' => 'itemid', 'at' => 'created_at']);
            }
        }
    }

    /**
     * The people in each segment as the period ends: stays begun before its end and not ended by it.
     *
     * @param list<string> $by
     * @return int|list<array<string, mixed>>
     */
    private static function members(Window $window, array $by, Database $db): int|array
    {
        $end   = $window->endsAt();
        $query = $db->queryBuilder()->table(self::STAYS);
        $query->where('entered_at', '<', $end)->where(static function ($q) use ($end): void {
            $q->whereNull('left_at')->orWhere('left_at', '>=', $end);
        });
        if ($by === []) {
            return (int) ($query->select([$query->raw('COUNT(DISTINCT userid) AS value')])->getAllOrFail()[0]['value'] ?? 0);
        }

        return $query->select([$query->raw('segmentid AS segment'), $query->raw('COUNT(DISTINCT userid) AS value')])->groupBy(['segmentid'])->getAllOrFail();
    }

    /**
     * The people behind a segment's number: its members as the period ends (`$column` null), or
     * who entered or left it in the period — each with the segment and when they came in and went.
     * A `segment` filter narrows it to one; the movements of a segment's first working out are
     * listed too, though not counted.
     *
     * @param array<string, string|list<string>> $filters
     * @return list<array<string, mixed>>
     */
    private static function people(?string $column, Window $window, array $filters, Database $db): array
    {
        $query = $db->queryBuilder()->table(self::STAYS)->select(['userid', 'segmentid', 'entered_at', 'left_at']);
        if ($column === null) {
            $end = $window->endsAt();
            $query->where('entered_at', '<', $end)->where(static function ($q) use ($end): void {
                $q->whereNull('left_at')->orWhere('left_at', '>=', $end);
            });
        } else {
            $query->where($column, '>=', $window->startsAt())->where($column, '<', $window->endsAt());
        }
        if (isset($filters['segment'])) {
            $query->whereIn('segmentid', array_map('intval', (array) $filters['segment']));
        }
        $names = array_column(Segments::all(), 'name', 'id');
        $out   = [];
        foreach ($query->orderBy($column ?? 'entered_at', 'desc')->limit(Metric::RECORDS)->getAllOrFail() as $row) {
            $out[] = ['userid' => (int) $row['userid'], 'segment' => (string) ($names[(int) $row['segmentid']] ?? $row['segmentid']),
                'entered' => $row['entered_at'] === null ? null : gmdate('Y-m-d H:i', (int) $row['entered_at']),
                'left' => $row['left_at'] === null ? null : gmdate('Y-m-d H:i', (int) $row['left_at'])];
        }

        return $out;
    }

    /**
     * Who entered (or left) each segment in the period, counted as {@see Segments::movements()}
     * counts them: stays begun (or ended) in it, after the segment's first working out, which put
     * everybody in at once.
     *
     * @param list<string> $by
     * @return int|list<array<string, mixed>>
     */
    private static function moved(string $column, Window $window, array $by, Database $db): int|array
    {
        $query = $db->queryBuilder()->table(self::STAYS . ' AS segment_members')
            ->where('segment_members.' . $column, '>=', $window->startsAt())->where('segment_members.' . $column, '<', $window->endsAt());
        if (Segments::knowsItsStart()) {
            // The column against the column: no builder call compares two, and nothing is bound.
            $query->join('#PREFIX#segments AS segments', 'segments.segmentid', '=', 'segment_members.segmentid')
                ->whereRaw('segment_members.' . $column . ' > COALESCE(segments.firstcomputedat, 0)');
        }
        if ($by === []) {
            return (int) ($query->select([$query->raw('COUNT(*) AS value')])->getAllOrFail()[0]['value'] ?? 0);
        }

        return $query->select([$query->raw('segment_members.segmentid AS segment'), $query->raw('COUNT(*) AS value')])->groupBy(['segment_members.segmentid'])->getAllOrFail();
    }
}
