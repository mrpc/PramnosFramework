<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;
use Pramnos\Database\Expression;
use Pramnos\Database\QueryBuilder;

/**
 * What each person did: the events filters and rules ask about.
 *
 * ```php
 * // declared — a label, and the properties worth naming
 * PersonEvents::define('payment.made', t('Paid'), ['amount' => t('Amount'), 'package' => t('Package')], t('Payments'));
 *
 * // recorded — by dispatching a PersonEvent class (nothing else to call), or directly
 * Event::dispatch(new PaymentMade($invoice));
 * PersonEvents::recordLater($userid, 'payment.made', ['amount' => 49]);
 *
 * // the past, once, from the application's own tables
 * PersonEvents::backfillFrom('payment.made', $db->queryBuilder()->table('invoices')->where('status', 1), 'userid', 'date');
 *
 * // named pages that count as events when a signed-in person opens them
 * PersonEvents::page('premium.viewed', t('Saw the Premium page'), '/el/premium*');
 * ```
 *
 * Two tables: `person_events` sums each person's events — how many, the first and the last —
 * for "ever", "never", "first before", "last within"; `person_event_log` keeps each occurrence
 * with its properties for "N times in the last M days", pruned after `audience.event_log_days`
 * (365 by default; 0 keeps it). The framework records email opens and clicks itself.
 */
final class PersonEvents
{
    /** The days the log keeps when the application says nothing. */
    public const DEFAULT_LOG_DAYS = 365;

    /** @var array<string, array{label: string, group: string, properties: array<string, string>}> */
    private static array $defined = [];

    /** @var array<string, array{label: string, pattern: string}> */
    private static array $pages = [];

    /**
     * Declare an event: its label, its group in the builder, and the properties it carries.
     *
     * @param array<string, string> $properties Name => label
     */
    public static function define(string $key, string $label, array $properties = [], string $group = ''): void
    {
        self::$defined[$key] = ['label' => $label, 'group' => $group, 'properties' => $properties];
    }

    /**
     * A named page: a signed-in person opening a path that matches the pattern (`*` for any rest
     * of it) is that event.
     */
    public static function page(string $key, string $label, string $pattern): void
    {
        self::$pages[$key] = ['label' => $label, 'pattern' => $pattern];
        self::define($key, $label, ['path' => t('Page')], t('Pages'));
    }

    /**
     * Every event the builder offers: the framework's, the declared, the named pages.
     *
     * @return array<string, array{label: string, group: string, properties: array<string, string>}>
     */
    public static function all(): array
    {
        $email = t('Email');

        return [
            'email.opened'  => ['label' => t('Opened an email'), 'group' => $email, 'properties' => ['mail' => t('Message'), 'category' => t('Category')]],
            'email.clicked' => ['label' => t('Clicked a link in an email'), 'group' => $email, 'properties' => ['mail' => t('Message'), 'url' => t('Link')]],
        ] + self::$defined;
    }

    /** An event's label, or its name. */
    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    /**
     * Record an occurrence now: the summary moves in one statement, the log gains a row.
     *
     * @param array<string, scalar|null> $properties
     */
    public static function record(int $person, string $event, array $properties = [], ?int $at = null): void
    {
        if ($person < 1 || $event === '') {
            return;
        }
        $at ??= time();
        self::merge([$person => ['n' => 1, 'first' => $at, 'last' => $at]], $event);
        self::db()->queryBuilder()->table('#PREFIX#person_event_log')->insert([
            'personid'   => $person,
            'event'      => mb_substr($event, 0, 100),
            'at'         => $at,
            'properties' => $properties === [] ? null : json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Record an occurrence in the queue — what a dispatched {@see PersonEvent} does. Where nothing
     * could be queued, it is recorded now rather than lost.
     *
     * @param array<string, scalar|null> $properties
     */
    public static function recordLater(int $person, string $event, array $properties = [], ?int $at = null): void
    {
        if ($person < 1 || $event === '') {
            return;
        }
        $at ??= time();
        try {
            $queued = \Pramnos\Event\QueuedEvents::enqueue(new RecordPersonEvent(), $event, [$person, $event, $properties, $at], \Pramnos\Event\QueuedEvents::meta($event));
        } catch (\Throwable) {
            $queued = null;
        }
        if ($queued === null) {
            self::record($person, $event, $properties, $at);
        }
    }

    /** What `Event::dispatch()` calls for a {@see PersonEvent}. */
    public static function dispatched(string $name, PersonEvent $event): void
    {
        $person = $event->personId();
        if ($person !== null && $person > 0) {
            self::recordLater($person, $name, $event->properties());
        }
    }

    /**
     * The past, from occurrences the application already has: `[person, at, properties?]` each.
     * Meant to run once, before live recording starts — run it again only after
     * {@see forget()}, or every occurrence counts twice.
     *
     * @param  iterable<array{0: int, 1: int, 2?: array<string, scalar|null>}> $occurrences
     * @return int How many were recorded
     */
    public static function backfill(string $event, iterable $occurrences, int $chunk = 2000): int
    {
        $total = 0;
        $batch = [];
        foreach ($occurrences as $occurrence) {
            $batch[] = $occurrence;
            if (count($batch) >= $chunk) {
                $total += self::backfillBatch($event, $batch);
                $batch = [];
            }
        }

        return $total + ($batch === [] ? 0 : self::backfillBatch($event, $batch));
    }

    /**
     * The past, from a query over the application's own table: who, and when — Unix seconds or a
     * datetime column — and optionally the properties of each row.
     *
     * ```php
     * PersonEvents::backfillFrom('request.sent', $db->queryBuilder()->table('requests'), 'parentid', 'date');
     * PersonEvents::backfillFrom('payment.made', $invoices, 'userid', 'paid_at', 'datetime',
     *     fn (array $row) => ['amount' => (float) $row['total']]);
     * ```
     *
     * @param (callable(array<string, mixed>): array<string, scalar|null>)|null $properties
     */
    public static function backfillFrom(string $event, QueryBuilder $query, string $personColumn, string $timeColumn, string $timeFormat = 'unix', ?callable $properties = null, int $chunk = 5000): int
    {
        $total = 0;
        for ($offset = 0; ; $offset += $chunk) {
            $rows = (clone $query)->orderBy($personColumn)->orderBy($timeColumn)->limit($chunk)->offset($offset)->getAllOrFail();
            if ($rows === []) {
                break;
            }
            $total += self::backfill($event, array_map(static function (array $row) use ($personColumn, $timeColumn, $timeFormat, $properties): array {
                $time = $row[$timeColumn] ?? null;
                $at   = $timeFormat === 'unix' ? (int) $time : (int) strtotime((string) $time);

                return [(int) ($row[$personColumn] ?? 0), $at, $properties === null ? [] : $properties($row)];
            }, $rows), $chunk);
            if (count($rows) < $chunk) {
                break;
            }
        }

        return $total;
    }

    /** Delete everything recorded of an event — before backfilling it again. */
    public static function forget(string $event): void
    {
        self::db()->queryBuilder()->table('#PREFIX#person_event_log')->where('event', $event)->delete();
        self::db()->queryBuilder()->table('#PREFIX#person_events')->where('event', $event)->delete();
    }

    /**
     * What a person did, newest first — the person card.
     *
     * @return list<array{event: string, label: string, count: int, first: int, last: int}>
     */
    public static function of(int $person): array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#person_events')->where('personid', $person)
            ->orderBy('last_at', 'desc')->getAllOrFail();

        return array_map(static fn (array $row): array => [
            'event' => (string) $row['event'],
            'label' => self::label((string) $row['event']),
            'count' => (int) $row['occurrences'],
            'first' => (int) $row['first_at'],
            'last'  => (int) $row['last_at'],
        ], $rows);
    }

    /**
     * Delete log rows older than the retention; the summary stays.
     *
     * @return int|null Rows deleted, or null when the retention keeps everything
     */
    public static function prune(?int $days = null): ?int
    {
        $days ??= self::logDays();
        if ($days <= 0) {
            return null;
        }
        $old = self::db()->queryBuilder()->table('#PREFIX#person_event_log')->where('at', '<', time() - $days * 86400);
        $count = (int) ((clone $old)->select([new Expression('COUNT(*) AS n')])->getAllOrFail()[0]['n'] ?? 0);
        if ($count > 0) {
            $old->delete();
        }

        return $count;
    }

    /** The days the log keeps: `audience.event_log_days`, or the default. */
    public static function logDays(): int
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return (int) (is_object($app) ? ($app->applicationInfo['audience']['event_log_days'] ?? self::DEFAULT_LOG_DAYS) : self::DEFAULT_LOG_DAYS);
    }

    /**
     * This request, if it is a GET of a named page by somebody signed in — what every page's
     * render asks. Nothing at all is looked at where no page is named, and a failure is logged,
     * never the page's.
     *
     * @return string|null The page event recorded
     */
    public static function visitedNow(): ?string
    {
        if (self::$pages === [] || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return null;
        }
        try {
            $user = \Pramnos\User\User::current();

            return self::visited((string) ($_SERVER['REQUEST_URI'] ?? ''), $user ? (int) $user->userid : null);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('A page visit was not recorded: ' . $e->getMessage(), 'audience');

            return null;
        }
    }

    /** Whether any page is named — what the request asks before it looks at who is signed in. */
    public static function hasPages(): bool
    {
        return self::$pages !== [];
    }

    /**
     * A signed-in person opened a path: record the named page it matches, if any.
     *
     * @return string|null The page event recorded
     */
    public static function visited(string $path, ?int $person): ?string
    {
        if ($person === null || $person < 1 || self::$pages === []) {
            return null;
        }
        $path = '/' . ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        foreach (self::$pages as $key => $page) {
            if (fnmatch($page['pattern'], $path, FNM_CASEFOLD)) {
                self::recordLater($person, $key, ['path' => $path]);

                return $key;
            }
        }

        return null;
    }

    /** Forget what was declared: tests. */
    public static function reset(): void
    {
        self::$defined = [];
        self::$pages   = [];
    }

    /**
     * Fold a batch into the summary and the log.
     *
     * @param list<array{0: int, 1: int, 2?: array<string, scalar|null>}> $batch
     */
    private static function backfillBatch(string $event, array $batch): int
    {
        $sums = [];
        $log  = [];
        foreach ($batch as $occurrence) {
            [$person, $at] = [(int) $occurrence[0], (int) $occurrence[1]];
            if ($person < 1 || $at < 1) {
                continue;
            }
            $sum = $sums[$person] ?? ['n' => 0, 'first' => $at, 'last' => $at];
            $sums[$person] = ['n' => $sum['n'] + 1, 'first' => min($sum['first'], $at), 'last' => max($sum['last'], $at)];
            $properties = (array) ($occurrence[2] ?? []);
            $log[] = ['personid' => $person, 'event' => $event, 'at' => $at,
                'properties' => $properties === [] ? null : json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        self::merge($sums, $event);
        foreach (array_chunk($log, 500) as $rows) {
            self::db()->queryBuilder()->table('#PREFIX#person_event_log')->insertMany($rows);
        }

        return count($log);
    }

    /**
     * Add occurrences to the summary, one statement per person: more of them, the earlier first,
     * the later last.
     *
     * @param array<int, array{n: int, first: int, last: int}> $sums By person
     */
    private static function merge(array $sums, string $event): void
    {
        $db   = self::db();
        $own  = $db->type === 'postgresql' ? '#PREFIX#person_events.' : '';
        $new  = static fn (string $c): string => $db->type === 'postgresql' ? 'EXCLUDED.' . $c : 'VALUES(' . $c . ')';
        // An upsert: the statement that adds to a row another worker may be adding to as well.
        $update = [
            'occurrences' => new Expression($own . 'occurrences + ' . $new('occurrences')),
            'first_at'    => new Expression('LEAST(' . $own . 'first_at, ' . $new('first_at') . ')'),
            'last_at'     => new Expression('GREATEST(' . $own . 'last_at, ' . $new('last_at') . ')'),
        ];
        foreach ($sums as $person => $sum) {
            $db->queryBuilder()->table('#PREFIX#person_events')->upsert([
                'personid'    => $person,
                'event'       => mb_substr($event, 0, 100),
                'occurrences' => $sum['n'],
                'first_at'    => $sum['first'],
                'last_at'     => $sum['last'],
            ], ['personid', 'event'], $update);
        }
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
