<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * Notes on the figures — a deploy, a price change, a campaign — a moment or a span, drawn on the
 * charts where they fall, and read by the assistant as context.
 *
 * ```php
 * Annotations::add(time(), 'Spring campaign', 'campaign', until: strtotime('+14 days'), link: '/admin/Campaigns/3');
 * Annotations::between($from, $to);                  // every note in a range, oldest first
 * Annotations::forPeriods(Window::range('day', …));  // for a chart: each note at the period it falls in
 * ```
 *
 * The daemon orchestrator writes one at each deploy it notices (`deploy`). Kept in
 * `stats.annotations`; an organisation's notes, and those of every organisation (tenant 0).
 */
final class Annotations
{
    public const TABLE = 'stats.annotations';

    /** A note; its id. */
    public static function add(int $at, string $words, string $kind = 'note', ?int $until = null, ?string $link = null, int $tenant = 0, ?int $by = null): int
    {
        $words = trim($words);
        if ($words === '' || ($until !== null && $until < $at)) {
            throw new \InvalidArgumentException(t('A note needs words, and an end after its start.'));
        }
        $db = Metrics::connection();
        $db->queryBuilder()->table(self::TABLE)->insert([
            'at' => $at, 'until' => $until, 'tenant' => $tenant, 'kind' => mb_substr(preg_replace('/[^a-z0-9_-]/', '', strtolower($kind)) ?: 'note', 0, 20),
            'words' => mb_substr($words, 0, 250), 'link' => $link === null ? null : mb_substr($link, 0, 500), 'createdby' => $by,
        ]);

        return (int) $db->getInsertId();
    }

    /** Take a note away. */
    public static function delete(int $id): void
    {
        Metrics::connection()->queryBuilder()->table(self::TABLE)->where('annotationid', $id)->delete();
    }

    /**
     * The notes that touch a range — begun before its end, not ended before its start — oldest
     * first; an organisation's and every organisation's, or all of them with `$tenant` null.
     *
     * @return list<array{id: int, at: int, until: ?int, tenant: int, kind: string, words: string, link: ?string}>
     */
    public static function between(int $from, int $to, ?int $tenant = null): array
    {
        $query = Metrics::connection()->queryBuilder()->table(self::TABLE)->where('at', '<', $to)->orderBy('at');
        if ($tenant !== null) {
            $query->whereIn('tenant', array_values(array_unique([0, $tenant])));
        }
        $out = [];
        foreach ($query->getAllOrFail() as $row) {
            if ((int) ($row['until'] ?? $row['at']) < $from) {
                continue;
            }
            $out[] = ['id' => (int) $row['annotationid'], 'at' => (int) $row['at'], 'until' => $row['until'] === null ? null : (int) $row['until'],
                'tenant' => (int) $row['tenant'], 'kind' => (string) $row['kind'], 'words' => (string) $row['words'], 'link' => $row['link'] === null ? null : (string) $row['link']];
        }

        return $out;
    }

    /**
     * The notes of a chart's periods, each at the index of the period it begins in (a span begun
     * before the first, at the first): what {@see \Pramnos\Html\Chart::render()} draws.
     *
     * @param list<Window> $periods
     * @return list<array{at: int, text: string, kind: string}>
     */
    public static function forPeriods(array $periods, ?int $tenant = null): array
    {
        if ($periods === []) {
            return [];
        }
        try {
            $notes = self::between($periods[0]->startsAt(), end($periods)->endsAt(), $tenant);
        } catch (\Throwable) {
            return [];   // no table yet: nothing to draw
        }
        $out = [];
        foreach ($notes as $note) {
            foreach ($periods as $i => $window) {
                if ($note['at'] < $window->endsAt()) {
                    $out[] = ['at' => $i, 'text' => $note['words'], 'kind' => $note['kind']];
                    break;
                }
            }
        }

        return $out;
    }
}
