<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;
use Pramnos\Database\Expression;
use Pramnos\Event\Event;

/**
 * Named audiences kept up to date: a {@see Filter} with a name, and who was in it, from when to when.
 *
 * ```php
 * $id = Segments::create('Dormant professionals', $filter, owner: $adminId);
 *
 * Segments::recompute($id);          // in full — the scheduler does every segment, hourly
 * Segments::recomputePerson($userid); // one person, after something about them changed
 * Segments::refreshOn('user.saved', 'profile.updated'); // ...in the queue, folded per person
 * ```
 *
 * Every change of membership is a row in `segment_members` and an event —
 * {@see SegmentEntered} (`segment.entered`) or {@see SegmentLeft} (`segment.left`) — except on a
 * segment's first recomputation, which records who is in it without announcing them: they did not
 * just arrive, and a rule that greets new members must not greet the whole base the moment a
 * segment is made.
 *
 * A segment another segment's filter names, or one an application reports as in use
 * ({@see usage()}), cannot be deleted.
 */
final class Segments
{
    /** @var array<string, callable(int): list<string>> Where segments are used, by who reports it */
    private static array $usage = [];

    /**
     * Make a segment. Its first recomputation is the caller's — or the scheduler's within the hour.
     *
     * @param  array<string, mixed>|string $filter
     * @throws \InvalidArgumentException  A filter that does not check
     */
    public static function create(string $name, array|string $filter, ?int $owner = null, string $description = ''): int
    {
        $checked = Filter::fromArray($filter, self::db())->toArray();
        $now     = time();
        self::db()->queryBuilder()->table('#PREFIX#segments')->insert([
            'name'        => self::name($name),
            'description' => $description,
            'filter'      => self::json($checked),
            'owner'       => $owner,
            'members'     => 0,
            'createdat'   => $now,
            'updatedat'   => $now,
        ]);

        return (int) self::db()->getInsertId();
    }

    /**
     * Change a segment's name, description or filter. A new filter applies at the next
     * recomputation; the members stay who they were until then.
     *
     * @param  array{name?: string, description?: string, filter?: array<string, mixed>|string} $changes
     * @throws \InvalidArgumentException A filter that does not check, or that would name itself
     */
    public static function update(int $id, array $changes): void
    {
        $row = ['updatedat' => time()];
        if (isset($changes['name'])) {
            $row['name'] = self::name($changes['name']);
        }
        if (isset($changes['description'])) {
            $row['description'] = (string) $changes['description'];
        }
        if (isset($changes['filter'])) {
            $checked = Filter::fromArray($changes['filter'], self::db())->toArray();
            if (in_array($id, self::reaches(Filter::segmentsIn($checked)), true)) {
                throw new \InvalidArgumentException('A segment cannot be defined by its own members.');
            }
            $row['filter'] = self::json($checked);
        }
        self::db()->queryBuilder()->table('#PREFIX#segments')->where('segmentid', $id)->update($row);
    }

    /**
     * Delete a segment and its history — refused while anything uses it.
     *
     * @throws \RuntimeException Naming what uses it
     */
    public static function delete(int $id): void
    {
        $uses = self::usedBy($id);
        if ($uses !== []) {
            throw new \RuntimeException(t('This segment is in use: %s', implode(', ', $uses)));
        }
        self::db()->queryBuilder()->table('#PREFIX#segment_members')->where('segmentid', $id)->delete();
        self::db()->queryBuilder()->table('#PREFIX#segments')->where('segmentid', $id)->delete();
    }

    /**
     * Tell the library where segments are used, so one in use is not deleted.
     *
     * ```php
     * Segments::usage('rules', fn (int $id) => Rules::namesUsingSegment($id));
     * ```
     *
     * @param callable(int): list<string> $finder What uses the segment, as names a person reads
     */
    public static function usage(string $source, callable $finder): void
    {
        self::$usage[$source] = $finder;
    }

    /**
     * What uses a segment: other segments' filters, and what {@see usage()} reports.
     *
     * @return list<string>
     */
    public static function usedBy(int $id): array
    {
        $uses = [];
        foreach (self::all() as $segment) {
            if ($segment['id'] !== $id && in_array($id, Filter::segmentsIn($segment['filter']), true)) {
                $uses[] = t('segment %s', $segment['name']);
            }
        }
        foreach (self::$usage as $finder) {
            array_push($uses, ...array_map('strval', $finder($id)));
        }

        return array_merge($uses, Rules\Rules::usingSegment($id));
    }

    /**
     * One segment, or null.
     *
     * @return array{id: int, name: string, description: string, filter: array<string, mixed>, owner: ?int, members: int, recomputedAt: ?int, createdAt: int, updatedAt: int}|null
     */
    public static function get(int $id): ?array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#segments')->where('segmentid', $id)->limit(1)->getAllOrFail();

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * Every segment, by name.
     *
     * @return list<array{id: int, name: string, description: string, filter: array<string, mixed>, owner: ?int, members: int, recomputedAt: ?int, createdAt: int, updatedAt: int}>
     */
    public static function all(): array
    {
        return array_map(self::row(...), self::db()->queryBuilder()->table('#PREFIX#segments')->orderBy('name')->getAllOrFail());
    }

    /** A segment's filter. */
    public static function filter(int $id): Filter
    {
        $segment = self::get($id) ?? throw new \InvalidArgumentException("No segment {$id}.");

        return Filter::fromArray($segment['filter'], self::db());
    }

    /**
     * Work a segment out again in full: who entered, who left, the new count.
     *
     * @return array{entered: int, left: int, members: int}
     */
    public static function recompute(int $id): array
    {
        $segment = self::get($id) ?? throw new \InvalidArgumentException("No segment {$id}.");
        $filter  = Filter::fromArray($segment['filter'], self::db());
        $now     = time();

        $nowIn = [];
        for ($page = $filter->ids(0, 5000); $page !== []; $page = $filter->ids(end($page), 5000)) {
            foreach ($page as $userid) {
                $nowIn[$userid] = true;
            }
        }
        $wasIn = array_fill_keys(self::memberIds($id), true);
        $entered = array_keys(array_diff_key($nowIn, $wasIn));
        $left    = array_keys(array_diff_key($wasIn, $nowIn));

        foreach (array_chunk($entered, 500) as $chunk) {
            self::db()->queryBuilder()->table('#PREFIX#segment_members')->insertMany(array_map(
                static fn (int $userid): array => ['segmentid' => $id, 'userid' => $userid, 'entered_at' => $now, 'left_at' => null],
                $chunk
            ));
        }
        foreach (array_chunk($left, 500) as $chunk) {
            self::db()->queryBuilder()->table('#PREFIX#segment_members')->where('segmentid', $id)
                ->whereNull('left_at')->whereIn('userid', $chunk)->update(['left_at' => $now]);
        }
        self::db()->queryBuilder()->table('#PREFIX#segments')->where('segmentid', $id)
            ->update(['members' => count($nowIn), 'recomputedat' => $now]);

        if ($segment['recomputedAt'] !== null) {
            foreach ($entered as $userid) {
                Event::dispatch(new SegmentEntered($id, $userid, $now));
            }
            foreach ($left as $userid) {
                Event::dispatch(new SegmentLeft($id, $userid, $now));
            }
        }

        return ['entered' => count($entered), 'left' => count($left), 'members' => count($nowIn)];
    }

    /**
     * Every segment, each after the segments its filter names, so a segment of a segment reads
     * its members as they are now.
     *
     * @return array<int, array{entered: int, left: int, members: int}> By segment id
     */
    public static function recomputeAll(): array
    {
        $results = [];
        foreach (self::inDependencyOrder() as $id) {
            $results[$id] = self::recompute($id);
        }

        return $results;
    }

    /**
     * One person, against every segment that has been worked out once: they enter the ones that
     * match now and leave the ones that stopped.
     *
     * @return array{entered: list<int>, left: list<int>} Segment ids
     */
    public static function recomputePerson(int $userid): array
    {
        $changes = ['entered' => [], 'left' => []];
        if (!Installed::segments()) {
            return $changes;
        }
        $open    = array_fill_keys(self::segmentsOf($userid), true);
        $now     = time();
        foreach (self::inDependencyOrder() as $id) {
            $segment = self::get($id);
            if ($segment === null || $segment['recomputedAt'] === null) {
                continue;   // not worked out yet: its first recomputation takes everybody in
            }
            $matches = Filter::fromArray($segment['filter'], self::db())->matches($userid);
            if ($matches && !isset($open[$id])) {
                self::db()->queryBuilder()->table('#PREFIX#segment_members')
                    ->insert(['segmentid' => $id, 'userid' => $userid, 'entered_at' => $now, 'left_at' => null]);
                self::recount($id);
                $changes['entered'][] = $id;
                Event::dispatch(new SegmentEntered($id, $userid, $now));
            } elseif (!$matches && isset($open[$id])) {
                self::db()->queryBuilder()->table('#PREFIX#segment_members')->where('segmentid', $id)
                    ->where('userid', $userid)->whereNull('left_at')->update(['left_at' => $now]);
                self::recount($id);
                $changes['left'][] = $id;
                Event::dispatch(new SegmentLeft($id, $userid, $now));
            }
        }

        return $changes;
    }

    /**
     * Recompute a person's segments in the queue whenever one of these events fires — folded, so a
     * burst of saves is one recomputation. The event's first argument is the person: an id, or an
     * object with `personId()`.
     */
    public static function refreshOn(string ...$events): void
    {
        foreach ($events as $event) {
            // A listener that asks whether segments are installed when the event fires — not at
            // every boot — and queues nothing until they are.
            Event::listen($event, static function (mixed ...$args) use ($event): void {
                $person = RefreshPersonSegments::person($args[0] ?? null);
                if ($person === null || !Installed::segments()) {
                    return;
                }
                try {
                    \Pramnos\Event\QueuedEvents::enqueue(new RefreshPersonSegments(), $event, [$person], \Pramnos\Event\QueuedEvents::meta($event));
                } catch (\Throwable $e) {
                    // The hourly recomputation finds it; the event that fired must not fail for it.
                    \Pramnos\Logs\Logger::log('Segments not refreshed after ' . $event . ' for ' . $person . ': ' . $e->getMessage(), 'audience');
                }
            });
        }
    }

    /**
     * The segments a person is in now, with since when — the person card's panel.
     *
     * @return list<array{id: int, name: string, since: int}>
     */
    public static function of(int $userid): array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#segment_members AS segment_members')
            ->join('#PREFIX#segments AS segments', 'segments.segmentid', '=', 'segment_members.segmentid')
            ->select(['segments.segmentid', 'segments.name', 'segment_members.entered_at'])
            ->where('segment_members.userid', $userid)->whereNull('segment_members.left_at')
            ->orderBy('segments.name')->getAllOrFail();

        return array_map(static fn (array $row): array => [
            'id'    => (int) $row['segmentid'],
            'name'  => (string) $row['name'],
            'since' => (int) $row['entered_at'],
        ], $rows);
    }

    /**
     * How many entered and left a segment in the last days — the library's "+in / −out".
     *
     * @return array{entered: int, left: int}
     */
    public static function movement(int $id, int $days = 7): array
    {
        $since = time() - $days * 86400;
        $count = static fn (string $column): int => (int) (self::db()->queryBuilder()->table('#PREFIX#segment_members')
            ->select([new Expression('COUNT(*) AS n')])->where('segmentid', $id)
            ->where($column, '>=', $since)->getAllOrFail()[0]['n'] ?? 0);

        return ['entered' => $count('entered_at'), 'left' => $count('left_at')];
    }

    /** Whether a person is in a segment now. */
    public static function isMember(int $id, int $userid): bool
    {
        return self::db()->queryBuilder()->table('#PREFIX#segment_members')->select(['userid'])
            ->where('segmentid', $id)->where('userid', $userid)->whereNull('left_at')->limit(1)->getAllOrFail() !== [];
    }

    /** Forget what {@see usage()} was told: tests. */
    public static function reset(): void
    {
        self::$usage = [];
    }

    /**
     * Segment ids, each after the ones its filter names. A cycle cannot be saved
     * ({@see update()}), so the walk ends.
     *
     * @return list<int>
     */
    private static function inDependencyOrder(): array
    {
        $needs = [];
        foreach (self::all() as $segment) {
            $needs[$segment['id']] = Filter::segmentsIn($segment['filter']);
        }
        $order = [];
        $visit = static function (int $id) use (&$visit, &$order, $needs): void {
            if (isset($order[$id]) || !isset($needs[$id])) {
                return;
            }
            $order[$id] = false;   // on the path: a cycle stops here rather than recursing
            foreach ($needs[$id] as $need) {
                $visit($need);
            }
            unset($order[$id]);
            $order[$id] = true;
        };
        foreach (array_keys($needs) as $id) {
            $visit($id);
        }

        return array_keys($order);
    }

    /**
     * Every segment reachable from these through filters, these included.
     *
     * @param  list<int> $ids
     * @return list<int>
     */
    private static function reaches(array $ids): array
    {
        $seen = [];
        while ($ids !== []) {
            $id = array_pop($ids);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $segment   = self::get($id);
            if ($segment !== null) {
                array_push($ids, ...Filter::segmentsIn($segment['filter']));
            }
        }

        return array_keys($seen);
    }

    /** @return list<int> Who is in a segment now */
    private static function memberIds(int $id): array
    {
        return array_map(static fn (array $row): int => (int) $row['userid'], self::db()->queryBuilder()
            ->table('#PREFIX#segment_members')->select(['userid'])->where('segmentid', $id)->whereNull('left_at')->getAllOrFail());
    }

    /** @return list<int> The segments a person is in now */
    private static function segmentsOf(int $userid): array
    {
        return array_map(static fn (array $row): int => (int) $row['segmentid'], self::db()->queryBuilder()
            ->table('#PREFIX#segment_members')->select(['segmentid'])->where('userid', $userid)->whereNull('left_at')->getAllOrFail());
    }

    /** Store a segment's count as its open stays say. */
    private static function recount(int $id): void
    {
        $open = self::db()->queryBuilder()->table('#PREFIX#segment_members')->select([new Expression('COUNT(*) AS n')])
            ->where('segmentid', $id)->whereNull('left_at')->getAllOrFail();
        self::db()->queryBuilder()->table('#PREFIX#segments')->where('segmentid', $id)->update(['members' => (int) ($open[0]['n'] ?? 0)]);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{id: int, name: string, description: string, filter: array<string, mixed>, owner: ?int, members: int, recomputedAt: ?int, createdAt: int, updatedAt: int}
     */
    private static function row(array $row): array
    {
        return [
            'id'           => (int) $row['segmentid'],
            'name'         => (string) $row['name'],
            'description'  => (string) ($row['description'] ?? ''),
            'filter'       => (array) json_decode((string) $row['filter'], true),
            'owner'        => $row['owner'] === null ? null : (int) $row['owner'],
            'members'      => (int) $row['members'],
            'recomputedAt' => $row['recomputedat'] === null ? null : (int) $row['recomputedat'],
            'createdAt'    => (int) $row['createdat'],
            'updatedAt'    => (int) $row['updatedat'],
        ];
    }

    /** A name as kept: trimmed, at most 150 characters, never empty. */
    private static function name(string $name): string
    {
        $name = trim(strip_tags($name));
        if ($name === '') {
            throw new \InvalidArgumentException(t('A segment needs a name.'));
        }

        return mb_substr($name, 0, 150);
    }

    /** @param array<string, mixed> $filter */
    private static function json(array $filter): string
    {
        return (string) json_encode($filter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * Delete stays that ended more than `audience.segment_history_days` ago (365; 0 keeps them):
     * who is in a segment now, and who entered or left within the year, stay answerable.
     *
     * @return int|null Rows deleted, or null when the history is kept whole or not installed
     */
    public static function pruneHistory(?int $days = null): ?int
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $days ??= (int) (is_object($app) ? ($app->applicationInfo['audience']['segment_history_days'] ?? 365) : 365);
        if ($days <= 0 || !Installed::segments()) {
            return null;
        }
        $old   = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#segment_members')
            ->whereNotNull('left_at')->where('left_at', '<', time() - $days * 86400);
        $count = (int) ((clone $old)->select([new \Pramnos\Database\Expression('COUNT(*) AS n')])->getAllOrFail()[0]['n'] ?? 0);
        if ($count > 0) {
            $old->delete();
        }

        return $count;
    }
}
