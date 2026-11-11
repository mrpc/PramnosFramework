<?php

declare(strict_types=1);

namespace Pramnos\Visits;

use Pramnos\Database\Database;
use Pramnos\Database\Instant;
use Pramnos\Metrics\Catalogue;
use Pramnos\Metrics\Dimension;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Window;

/**
 * The site's traffic as metrics, with no declaration of the application's, wherever `visits.sites`
 * is declared and `pramnos.page_hits` is there:
 *
 *   - `visits.views`        page views by people (no bot, with JavaScript)
 *   - `visits.visits`       visits: their landing pages (the cookie model; a cookieless site has none)
 *   - `visits.visitors`     distinct visitors
 *   - `visits.signed_in`    visits in which the person was signed in at some page
 *   - `visits.duration`     the mean visit, in seconds: its first page to its last
 *   - `visits.single_page`  visits of one page
 *   - `visits.bots`, `visits.no_js`  the hits counted apart: robots, and pages without JavaScript
 *
 * Broken down by `visit_site`, `visit_source`, `visit_country`, `visit_device`, `visit_browser`,
 * `visit_os`, `signed_in`, and the application's own `visits.dimension` under the name
 * `visits.dimension_name` (`visit_area` by default — `market`, say, to share an application's
 * dimension of that name, whose values then win). A visit's breakdown is its landing page's.
 *
 * `'metrics' => ['visits' => false]` in app.php leaves them out; a metric the application
 * registers under the same name wins.
 */
final class VisitsMetrics implements Catalogue
{
    /** The hits. */
    public const TABLE = 'pramnos.page_hits';

    /** A visit longer than this, in seconds, is left out of the mean visit and counted on its own: 4 hours. */
    public const LONG = 14400;

    /** The flags of a person's page with JavaScript: none, or only prerendered or cached. */
    public const PEOPLE = [0, 4, 8, 12];

    /** @return array<string, string> The dimensions and the column of each */
    public static function columns(): array
    {
        return ['visit_site' => 'site', 'visit_source' => 'source', 'visit_country' => 'country', 'visit_device' => 'device',
            'visit_browser' => 'browser', 'visit_os' => 'os', 'signed_in' => "CASE WHEN userid IS NULL THEN 'no' ELSE 'yes' END",
            self::areaName() => 'dimension'];
    }

    /** The name the application's own dimension goes by: `visits.dimension_name`, `visit_area` by default. */
    public static function areaName(): string
    {
        $name = (string) (Visits::config()['dimension_name'] ?? 'visit_area');

        return preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) === 1 ? $name : 'visit_area';
    }

    /** @return iterable<Metric|Dimension> */
    public function metrics(): iterable
    {
        if (Visits::sites() === [] || !\Pramnos\Framework\Factory::getDatabase()->schema()->hasTable(self::TABLE)) {
            return;
        }
        yield Dimension::of('visit_site', t('Site'))->values(static fn (): array => array_column(
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('pramnos.sites')->select(['siteid', 'name'])->getAllOrFail(), 'name', 'siteid'));
        yield Dimension::of('visit_source', t('Source'))->values(array_combine(Source::KINDS, array_map('t', array_map('ucfirst', Source::KINDS))));
        yield Dimension::of('visit_country', t('Country'));
        yield Dimension::of('visit_device', t('Device'))->values(['mobile' => t('Mobile'), 'tablet' => t('Tablet'), 'desktop' => t('Desktop')]);
        yield Dimension::of('visit_browser', t('Browser'));
        yield Dimension::of('visit_os', t('Operating system'));
        yield Dimension::of('signed_in', t('Signed in'))->values(['yes' => t('Yes'), 'no' => t('No')]);
        yield Dimension::of(self::areaName(), (string) (Visits::config()['dimension_label'] ?? t('Area')));

        // A month or a year of raw hits is costly to read: their open period is computed again
        // hourly and daily, the day at every run. `metrics.refresh` or a metric's own changes it.
        $every = (array) (Visits::config()['refresh'] ?? ['week' => '1 hour', 'month' => '1 hour', 'quarter' => '6 hours', 'year' => '1 day']);
        $hits = static function (string $name, string $label) use ($every) {
            $metric = Metric::flow($name, $label)->from(self::TABLE)->at('at')->refresh($every);
            foreach (self::columns() as $dimension => $column) {
                $metric->dimension($dimension, $column);
            }

            // The hits behind the number: when, who where known, and from where.
            return $metric->rows(['at' => 'at', 'userid' => 'userid', 'visitor' => 'visitor', 'visit' => 'visit', 'kind' => 'kind', 'source' => 'source',
                'country' => 'country', 'device' => 'device']);
        };
        $dims = array_keys(self::columns());

        yield $hits('visits.views', t('Page views'))->count()->where('kind', 'view')->where('flags', self::PEOPLE)
            ->describe(t('Pages people saw: robots and pages without JavaScript left out'));
        yield $hits('visits.visits', t('Visits'))->count()->where('seq', 1)->where('flags', self::PEOPLE)
            ->describe(t('Visits, by their landing page: a new one after the site\'s idle time or another campaign'));
        yield $hits('visits.visitors', t('Visitors'))->countDistinct('visitor')->where('flags', self::PEOPLE)
            ->describe(t('Distinct visitors: a cookie, or a day\'s hash on a cookieless site'));
        yield $hits('visits.bots', t('Robot hits'))->count()->where('flags', [1, 3, 5, 7, 9, 11, 13, 15])
            ->describe(t('Hits from robots, counted apart from people'));
        yield $hits('visits.no_js', t('Pages without JavaScript'))->count()->where('flags', [2, 6, 10, 14])
            ->describe(t('Pages seen without JavaScript, robots left out, counted apart'));
        yield Metric::flow('visits.signed_in', t('Signed-in visits'))->refresh($every)
            ->sourceSets(static fn (Window $w, array $sets, Database $db): ?array => self::perVisitSets($w->startsAt(), $w->endsAt(), $sets, $db, 'signed'))->dimensions(array_values(array_diff($dims, ['signed_in'])))
            ->describe(t('Visits in which the person was signed in at some page'))
            ->source(static fn (Window $w, array $by, Database $db): int|array => self::perVisit($w->startsAt(), $w->endsAt(), $by, $db, 'signed'))
            ->rows(static fn (Window $w, array $filters, Database $db): array => self::visitRows($w->startsAt(), $w->endsAt(), $filters, $db, 'signed'));
        yield Metric::flow('visits.single_page', t('Single-page visits'))->refresh($every)
            ->sourceSets(static fn (Window $w, array $sets, Database $db): ?array => self::perVisitSets($w->startsAt(), $w->endsAt(), $sets, $db, 'single'))->dimensions($dims)
            ->describe(t('Visits of one page'))
            ->source(static fn (Window $w, array $by, Database $db): int|array => self::perVisit($w->startsAt(), $w->endsAt(), $by, $db, 'single'))
            ->rows(static fn (Window $w, array $filters, Database $db): array => self::visitRows($w->startsAt(), $w->endsAt(), $filters, $db, 'single'));
        yield Metric::flow('visits.duration', t('Visit length'))->refresh($every)
            ->sourceSets(static fn (Window $w, array $sets, Database $db): ?array => self::perVisitSets($w->startsAt(), $w->endsAt(), $sets, $db, 'duration'))->aggregate(Metric::AVG)->dimensions($dims)->unit('s')->format('duration')
            ->describe(t('The mean visit, in seconds, from its first page to its last; visits longer than 4 hours (a tab left open) are left out'))
            ->source(static fn (Window $w, array $by, Database $db): int|array => self::perVisit($w->startsAt(), $w->endsAt(), $by, $db, 'duration'))
            ->rows(static fn (Window $w, array $filters, Database $db): array => self::visitRows($w->startsAt(), $w->endsAt(), $filters, $db, 'duration'));
    }

    /**
     * Counted per visit: each visit of `[$from, $to)` once — its hits grouped — with its landing
     * page's breakdown, then counted (`single`: of one page, `signed`: signed in, `long`: longer
     * than {@see LONG}, `all`), or its length averaged (`duration`, of the visits up to LONG, with
     * how many it is of).
     *
     * @param list<string> $by
     * @return int|list<array<string, mixed>>
     */
    public static function perVisit(int $from, int $to, array $by, Database $db, string $what, ?int $site = null): int|array
    {
        $inner = self::visits($from, $to, $by, $db, $site);
        $outer  = $db->queryBuilder()->fromSub($inner, 'v');
        $value  = match ($what) {
            'duration' => 'AVG(seconds) AS value, COUNT(*) AS count',
            default    => 'COUNT(*) AS value',
        };
        $outer->select(array_merge(array_map(static fn (string $n) => $outer->raw(preg_replace('/[^a-z0-9_]/', '', $n)), $by), [$outer->raw($value)]));
        self::which($outer, $what);
        if ($by !== []) {
            return $outer->groupBy(array_map(static fn (string $n): string => preg_replace('/[^a-z0-9_]/', '', $n), $by))->getAllOrFail();
        }
        $row = $outer->getAllOrFail()[0] ?? [];

        return $what === 'duration' ? [['value' => (float) ($row['value'] ?? 0), 'count' => (int) ($row['count'] ?? 0)]] : (int) ($row['value'] ?? 0);
    }

    /**
     * The visits behind a per-visit number: each with its first page's time, the person where
     * known, its pages and length, and its landing page's breakdown — filtered by any dimension.
     *
     * @param array<string, string|list<string>> $filters
     * @return list<array<string, mixed>>
     */
    public static function visitRows(int $from, int $to, array $filters, Database $db, string $what): array
    {
        $names = array_keys(self::columns());
        $inner = self::visits($from, $to, $names, $db, null);
        $outer = $db->queryBuilder()->fromSub($inner, 'v');
        $outer->select(array_map(static fn (string $c) => $outer->raw($c), ['started', 'userid', 'visit', 'pages', 'seconds',
            ...array_map(static fn (string $n): string => (string) preg_replace('/[^a-z0-9_]/', '', $n), $names)]));
        self::which($outer, $what);
        foreach ($filters as $name => $value) {
            if (!in_array($name, $names, true)) {
                throw new \DomainException(sprintf(t('Visits cannot be listed by %s.'), $name));
            }
            $outer->whereIn((string) preg_replace('/[^a-z0-9_]/', '', $name), array_values(array_map('strval', (array) $value)));
        }

        return $outer->orderBy('started', 'desc')->limit(Metric::RECORDS)->getAllOrFail();
    }

    /**
     * {@see perVisit()} for every breakdown of a period at once — one read, `GROUPING SETS` — on
     * PostgreSQL; null elsewhere, where each breakdown is read on its own.
     *
     * @param list<list<string>> $sets
     * @return array<string, list<array<string, mixed>>>|null By the breakdown's dimensions joined with commas
     */
    public static function perVisitSets(int $from, int $to, array $sets, Database $db, string $what): ?array
    {
        if ($db->type !== 'postgresql') {
            return null;
        }
        $names  = array_values(array_unique(array_merge(...$sets)));
        $clean  = static fn (string $n): string => (string) preg_replace('/[^a-z0-9_]/', '', $n);
        $outer  = $db->queryBuilder()->fromSub(self::visits($from, $to, $names, $db, null), 'v');
        $select = [];
        foreach ($names as $name) {
            $select[] = $outer->raw($clean($name));
            // Raw: which dimensions a row is grouped by, PostgreSQL's GROUPING().
            $select[] = $outer->raw('GROUPING(' . $clean($name) . ') AS pf_g_' . $clean($name));
        }
        $select[] = $outer->raw($what === 'duration' ? 'AVG(seconds) AS value, COUNT(*) AS count' : 'COUNT(*) AS value');
        $outer->select($select);
        self::which($outer, $what);
        $outer->groupByRaw('GROUPING SETS (' . implode(', ', array_map(static fn (array $set): string => '(' . implode(', ', array_map($clean, $set)) . ')', $sets)) . ')');
        $answer = [];
        foreach ($sets as $set) {
            $answer[implode(',', $set)] = [];
        }
        $bySignature = [];
        foreach ($sets as $set) {
            $sorted = $set;
            sort($sorted);
            $bySignature[implode(',', $sorted)] = implode(',', $set);
        }
        foreach ($outer->getAllOrFail() as $row) {
            $grouped = [];
            foreach ($names as $name) {
                if ((int) $row['pf_g_' . $clean($name)] === 0) {
                    $grouped[] = $name;
                }
                unset($row['pf_g_' . $clean($name)]);
            }
            sort($grouped);
            $key = $bySignature[implode(',', $grouped)] ?? null;
            if ($key !== null) {
                $answer[$key][] = array_intersect_key($row, array_flip([...($key === '' ? [] : explode(',', $key)), 'value', 'count']));
            }
        }

        return $answer;
    }

    /**
     * Each visit of `[$from, $to)` once, its hits grouped: its pages, its length, whether the person
     * was signed in, and the landing page's breakdown by `$names`.
     *
     * @param list<string> $names
     */
    private static function visits(int $from, int $to, array $names, Database $db, ?int $site): \Pramnos\Database\QueryBuilder
    {
        $inner  = $db->queryBuilder()->table(self::TABLE);
        $select = [$inner->raw('visit'), $inner->raw('COUNT(*) AS pages'),
            $inner->raw($db->type === 'postgresql' ? 'CAST(EXTRACT(EPOCH FROM MAX(at) - MIN(at)) AS BIGINT) AS seconds' : 'TIMESTAMPDIFF(SECOND, MIN(at), MAX(at)) AS seconds'),
            $inner->raw('MAX(CASE WHEN userid IS NULL THEN 0 ELSE 1 END) AS signed'), $inner->raw('MIN(at) AS started'), $inner->raw('MAX(userid) AS userid')];
        $columns = self::columns();
        foreach ($names as $name) {
            // The landing page's: where it came from is only on it, the rest is the visit's own.
            $column   = $name === 'signed_in' ? "CASE WHEN MAX(CASE WHEN userid IS NULL THEN 0 ELSE 1 END) = 1 THEN 'yes' ELSE 'no' END" : 'MAX(' . ($columns[$name] ?? 'NULL') . ')';
            $select[] = $inner->raw($column . ' AS ' . preg_replace('/[^a-z0-9_]/', '', $name));
        }
        $inner->select($select)->whereNotNull('visit')->where('kind', 'view')->whereIn('flags', self::PEOPLE)
            ->where('at', '>=', Instant::utc($from, $db))->where('at', '<', Instant::utc($to, $db))
            ->groupBy(['visit']);
        if ($site !== null) {
            $inner->where('site', $site);
        }

        return $inner;
    }

    /** The visits a count is of: of one page, signed in, up to LONG (for the mean), or beyond it. */
    private static function which(\Pramnos\Database\QueryBuilder $outer, string $what): void
    {
        match ($what) {
            'single'   => $outer->where('pages', 1),
            'signed'   => $outer->where('signed', 1),
            // A tab left open for hours is not a visit of hours: the mean is of the visits up to
            // LONG; those beyond it are counted on their own (`long`).
            'duration' => $outer->where('seconds', '<=', self::LONG),
            'long'     => $outer->where('seconds', '>', self::LONG),
            default    => null,
        };
    }
}
