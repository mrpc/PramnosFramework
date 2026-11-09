<?php

declare(strict_types=1);

namespace Pramnos\Visits;

use Pramnos\Database\Database;
use Pramnos\Database\Instant;

/**
 * `stats.visit_pages_daily`: people's page views and clicks out per UTC day — by site, page, link
 * out, referrer, source and campaign — for reading long periods of pages, referrers, clicks and
 * campaigns without the raw hits. The counts by source, country, device and the rest are the
 * metrics' (`visits.*`), kept in their own store.
 *
 * On TimescaleDB, with `page_hits` a hypertable, it is a continuous aggregate refreshed by its own
 * policy, real-time (`materialized_only = false`). Elsewhere it is a table that `visits:rollup`
 * fills, every 30 minutes, for yesterday and today; `--from` brings older days.
 *
 * | Column | |
 * | --- | --- |
 * | `day` | the UTC day |
 * | `site`, `kind`, `urlid`, `target` | the site, `view` or `click`, the page, a click's link out |
 * | `referrer_host`, `source`, `campaign` | where a visit came from: on its landing page only |
 * | `views` | page views (for a click, the clicks) by people: robots and pages without JavaScript left out |
 * | `visits` | visits that began there: landing pages |
 */
final class Rollup
{
    /** The aggregate, or the table. */
    public const NAME = 'stats.visit_pages_daily';

    /** What it groups by. */
    private const KEYS = ['site', 'kind', 'urlid', 'target', 'referrer_host', 'source', 'campaign'];

    /**
     * Make it, where it is not there: the aggregate where it can be one, else the table.
     *
     * @return string `aggregate` or `table`
     */
    public static function ensure(Database $db): string
    {
        $schema = $db->schema();
        if ($db->type === 'postgresql' && $db->capabilities()->hasTimescaleDB() && $schema->hasHypertable(VisitsMetrics::TABLE)) {
            if (!$schema->hasView(self::NAME)) {
                $schema->ensureSchema('stats');
                $sql = "SELECT time_bucket(INTERVAL '1 day', at) AS day, " . implode(', ', self::KEYS)
                    . ', COUNT(*) AS views, SUM(CASE WHEN seq = 1 THEN 1 ELSE 0 END) AS visits'
                    . ' FROM ' . $schema->quoteTable(VisitsMetrics::TABLE)
                    . " WHERE kind IN ('view', 'click') AND flags IN (" . implode(', ', VisitsMetrics::PEOPLE) . ')'
                    . ' GROUP BY day, ' . implode(', ', self::KEYS);
                $schema->createContinuousAggregate(self::NAME, $sql, ['timescaledb.materialized_only' => false]);
                $schema->addContinuousAggregatePolicy(self::NAME, '3 days', '1 hour', '30 minutes');
            }

            return 'aggregate';
        }
        if (!$schema->hasTable(self::NAME)) {
            $schema->ensureSchema('stats');
            $schema->createTable(self::NAME, static function ($table): void {
                $table->comment('People\'s page views and clicks out per UTC day, filled by visits:rollup');
                $table->timestampTz('day');
                $table->integer('site');
                $table->string('kind', 8);
                $table->integer('urlid');
                $table->integer('target')->nullable();
                $table->string('referrer_host', 255)->nullable();
                $table->string('source', 12)->nullable();
                $table->string('campaign', 128)->nullable();
                $table->integer('views')->default(0);
                $table->integer('visits')->default(0);
                $table->index(['day', 'site'], 'idx_visit_pages_daily_day');
            });
        }

        return 'table';
    }

    /**
     * Fill the table for the UTC days from `$from` to `$to` (yesterday and today by default), each
     * day replaced whole. Nothing to do for the aggregate, which its policy keeps.
     *
     * @return int The days filled
     */
    public static function run(Database $db, ?int $from = null, ?int $to = null): int
    {
        if (self::ensure($db) === 'aggregate') {
            return 0;
        }
        $now  = time();
        $day  = intdiv($from ?? $now - 86400, 86400) * 86400;
        $last = intdiv($to ?? $now, 86400) * 86400;
        $done = 0;
        for (; $day <= $last; $day += 86400) {
            $q    = $db->queryBuilder()->table(VisitsMetrics::TABLE);
            $rows = $q->select([...self::KEYS, $q->raw('COUNT(*) AS views'), $q->raw('SUM(CASE WHEN seq = 1 THEN 1 ELSE 0 END) AS visits')])
                ->whereIn('kind', ['view', 'click'])->whereIn('flags', VisitsMetrics::PEOPLE)
                ->where('at', '>=', Instant::utc($day, $db))->where('at', '<', Instant::utc($day + 86400, $db))
                ->groupBy(self::KEYS)->getAllOrFail();
            $db->queryBuilder()->table(self::NAME)->where('day', Instant::utc($day, $db))->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->queryBuilder()->table(self::NAME)->insertMany(array_map(static fn (array $r): array => ['day' => Instant::utc($day, $db)]
                    + array_intersect_key($r, array_flip(self::KEYS)) + ['views' => (int) $r['views'], 'visits' => (int) $r['visits']], $chunk));
            }
            $done++;
        }

        return $done;
    }

    /** Whether it is there to be read. */
    public static function readable(Database $db): bool
    {
        $schema = $db->schema();

        return $schema->hasTable(self::NAME) || $schema->hasView(self::NAME);
    }
}
