<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Database\Instant;
use Pramnos\Mcp\McpToolInterface;
use Pramnos\Visits\Visits;
use Pramnos\Visits\VisitsMetrics;

/**
 * The site's own traffic for the assistant, read from `pramnos.page_hits`: the figures of a
 * period, against the one before, and what they are made of — sources, campaigns, referrers,
 * pages (by a part of their address), clicks out, countries, devices, browsers, the application's
 * own dimension, and the kinds of account signed in. Its name is `visits.tool` (`visits` by
 * default). Robots and pages without JavaScript are counted apart, never in the figures.
 */
final class VisitsTool implements McpToolInterface
{
    /** What a breakdown may be, and the column (or expression) it groups. */
    public const BREAKDOWNS = ['sources' => 'source', 'campaigns' => 'campaign', 'referrers' => 'referrer_host', 'countries' => 'country',
        'devices' => 'device', 'browsers' => 'browser', 'os' => 'os', 'dimension' => 'dimension', 'pages' => 'urlid', 'clicks' => 'target',
        'usertypes' => 'usertype'];

    /** The most rows a breakdown answers. */
    public const LIMIT = 50;

    /** @param int $adminId Who is asking; 0 is nobody signed in */
    public function __construct(private readonly int $adminId = 0)
    {
    }

    /** `visits.tool`, else `visits`. */
    public function name(): string
    {
        $name = (string) (Visits::config()['tool'] ?? 'visits');

        return preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) === 1 ? $name : 'visits';
    }

    /** What the model is told the tool does. */
    public function description(): string
    {
        return 'The website\'s own traffic (page views, visits, visitors) — the count of people: robots and pages without JavaScript are '
            . 'left out of the figures and counted apart (say so). from/to: YYYY-MM-DD, both days included (the last 7 days by default). '
            . 'Answers the period\'s figures — views, visits, visitors, signed_in visits, single_page visits, mean visit seconds, bots, no_js — '
            . 'and, with compare: true, the same for the period before it. breakdown: ' . implode(', ', array_keys(self::BREAKDOWNS))
            . ' — each row with its views and visits, largest first; sources/campaigns/referrers count visits by where they came from; '
            . 'clicks are links out; usertypes the kinds of account signed in. page: a part of the page\'s address, to keep only pages '
            . 'containing it (with breakdown pages, or for the figures). site: one site\'s name, when there are several.';
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'from'      => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'to'        => ['type' => 'string', 'description' => 'YYYY-MM-DD, included'],
            'breakdown' => ['type' => 'string', 'enum' => array_keys(self::BREAKDOWNS)],
            'page'      => ['type' => 'string', 'description' => 'A part of the page\'s address'],
            'compare'   => ['type' => 'boolean'],
            'site'      => ['type' => 'string'],
        ]];
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): mixed
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (Visits::sites() === [] || !$db->schema()->hasTable(VisitsMetrics::TABLE)) {
            return 'Error: the site\'s traffic is not counted here.';
        }
        $zone = \Pramnos\Metrics\Window::zone();
        try {
            $to   = isset($input['to']) ? new \DateTimeImmutable((string) $input['to'], $zone) : new \DateTimeImmutable('today', $zone);
            $from = isset($input['from']) ? new \DateTimeImmutable((string) $input['from'], $zone) : $to->modify('-6 days');
        } catch (\Exception) {
            return 'Error: dates are written YYYY-MM-DD.';
        }
        [$start, $end] = [$from->setTime(0, 0)->getTimestamp(), $to->setTime(0, 0)->modify('+1 day')->getTimestamp()];
        if ($end <= $start || $end - $start > 400 * 86400) {
            return 'Error: from is before to, and the period is at most 400 days.';
        }
        $site = null;
        if (isset($input['site']) && $input['site'] !== '') {
            $declared = Visits::sites()[(string) $input['site']] ?? null;
            $site     = $declared === null ? null : (Visits::siteFor($declared['hosts'][0] ?? '')['id'] ?? null);
            if ($site === null) {
                return 'Error: no such site; the sites are ' . implode(', ', array_keys(Visits::sites())) . '.';
            }
        }
        $page   = trim((string) ($input['page'] ?? ''));
        $answer = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'figures' => $this->figures($start, $end, $site, $page)];
        if (!empty($input['compare'])) {
            $length            = $end - $start;
            $answer['before']  = ['from' => date('Y-m-d', $start - $length), 'figures' => $this->figures($start - $length, $start, $site, $page)];
        }
        $breakdown = (string) ($input['breakdown'] ?? '');
        if ($breakdown !== '') {
            if (!isset(self::BREAKDOWNS[$breakdown])) {
                return 'Error: breakdown is one of ' . implode(', ', array_keys(self::BREAKDOWNS)) . '.';
            }
            $answer['breakdown'] = [$breakdown => $this->breakdown($breakdown, $start, $end, $site, $page)];
        }

        return $answer;
    }

    /** The period's figures. @return array<string, int|float> */
    private function figures(int $start, int $end, ?int $site, string $page): array
    {
        $db    = \Pramnos\Framework\Factory::getDatabase();
        $count = function (array $flags, ?string $extra = null, bool $distinct = false) use ($db, $start, $end, $site, $page): int {
            $q = $this->hits($start, $end, $site, $page)->whereIn('h.flags', $flags);
            if ($extra === 'landing') {
                $q->where('h.seq', 1);
            }
            if ($extra !== 'all') {
                $q->where('h.kind', 'view');
            }

            return (int) ($q->select([$q->raw($distinct ? 'COUNT(DISTINCT h.visitor) AS n' : 'COUNT(*) AS n')])->getAllOrFail()[0]['n'] ?? 0);
        };
        $duration = $page === '' ? (VisitsMetrics::perVisit($start, $end, [], $db, 'duration', $site)[0] ?? ['value' => 0]) : null;

        return array_filter([
            'views'    => $count(VisitsMetrics::PEOPLE),
            'visits'   => $count(VisitsMetrics::PEOPLE, 'landing'),
            'visitors' => $count(VisitsMetrics::PEOPLE, null, true),
            'signed_in'   => $page === '' ? VisitsMetrics::perVisit($start, $end, [], $db, 'signed', $site) : null,
            'single_page' => $page === '' ? VisitsMetrics::perVisit($start, $end, [], $db, 'single', $site) : null,
            'mean_visit_seconds' => $duration === null ? null : round((float) $duration['value'], 1),
            'bots'     => $count([1, 3, 5, 7, 9, 11, 13, 15], 'all'),
            'no_js'    => $count([2, 6, 10, 14]),
        ], static fn ($v): bool => $v !== null);
    }

    /** A breakdown's rows: views and visits each, largest first. @return list<array<string, mixed>> */
    private function breakdown(string $breakdown, int $start, int $end, ?int $site, string $page): array
    {
        $q      = $this->hits($start, $end, $site, $page)->whereIn('h.flags', VisitsMetrics::PEOPLE);
        $column = self::BREAKDOWNS[$breakdown];
        if ($breakdown === 'clicks') {
            $q->where('h.kind', 'click')->join('#PREFIX#urls AS t', 't.urlid', '=', 'h.target');
            $key = 't.url';
        } elseif ($breakdown === 'pages') {
            $q->where('h.kind', 'view');
            $key = 'u.url';
        } elseif ($breakdown === 'usertypes') {
            $q->where('h.kind', 'view')->join('#PREFIX#users AS p', 'p.userid', '=', 'h.userid');
            $key = 'p.usertype';
        } else {
            $q->where('h.kind', 'view');
            $key = 'h.' . $column;
        }
        $rows = $q->select([$q->raw($key . ' AS name'), $q->raw('COUNT(*) AS views'), $q->raw('SUM(CASE WHEN h.seq = 1 THEN 1 ELSE 0 END) AS visits'),
            $q->raw('COUNT(DISTINCT h.visit) AS visits_seen')])->groupBy([$q->raw($key)])->orderBy('views', 'desc')->limit(self::LIMIT)->getAllOrFail();

        return array_map(static fn (array $r): array => ['name' => $r['name'] ?? '(none)', 'views' => (int) $r['views'],
            in_array($breakdown, ['sources', 'campaigns', 'referrers'], true) ? 'visits' : 'visits_with_it' => in_array($breakdown, ['sources', 'campaigns', 'referrers'], true) ? (int) $r['visits'] : (int) $r['visits_seen']], $rows);
    }

    /** The hits of the period — of one site, of pages holding a part of an address — joined to their pages. */
    private function hits(int $start, int $end, ?int $site, string $page): \Pramnos\Database\QueryBuilder
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        $q  = $db->queryBuilder()->table(VisitsMetrics::TABLE . ' AS h')->join('#PREFIX#urls AS u', 'u.urlid', '=', 'h.urlid')
            ->where('h.at', '>=', Instant::utc($start, $db))->where('h.at', '<', Instant::utc($end, $db));
        if ($site !== null) {
            $q->where('h.site', $site);
        }
        if ($page !== '') {
            // `_` and `%` in the part are wildcards too: a broader match, never a missed one.
            $q->whereLike('u.url', '%' . $page . '%');
        }

        return $q;
    }
}
