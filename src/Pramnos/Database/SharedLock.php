<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * A mutual exclusion that holds **across servers**, because it lives in the database.
 *
 * ## Why {@see \Pramnos\Console\WorkerLock} is not this
 *
 * `WorkerLock` is a file whose creation is the atomic step, and it is the right primitive
 * for what it was built for: one long-running worker per machine, with a heartbeat a
 * dashboard can read. It records the holder's `host` and checks the holder's pid *on the
 * same host*, which says plainly what it can and cannot do.
 *
 * On two web servers it does not exclude anything. Each has its own `var/` and its own
 * `sys_get_temp_dir()`, so each takes the lock, and every scheduled task with
 * `withoutOverlapping()` runs once per node. Nothing fails; the work simply happens twice —
 * two copies of a nightly email, two of a billing run.
 *
 * ## Why the database rather than the cache
 *
 * Every application on this framework has a database. A shared cache is the *usual* answer
 * to this and it is optional here — the default adapter is local files, which is a lock that
 * excludes nothing in exactly the same way, and quietly. A lock that works only when
 * somebody remembered to configure Redis is worse than no lock, because it looks like one.
 *
 * The atomicity is the primary key. `INSERT` of a name that is already there is refused by
 * the engine, not by a check this class makes, so there is no window between looking and
 * taking. Takeover of an expired lock is a **guarded** `UPDATE` — `WHERE name = ? AND
 * expiresat <= now` — which is the same trick: two nodes may both issue it, and the engine
 * lets exactly one match.
 *
 * ## The lease
 *
 * A holder that dies never releases. Every lock therefore carries an expiry, and a holder
 * that outlives it loses the lock to whoever asks next — so the `$ttl` must be comfortably
 * longer than the work. It is a lease, not a promise: two nodes can overlap if one of them
 * stalls for longer than the lease, which is the same trade every distributed lock makes and
 * the reason `$ttl` is a required argument rather than a default somebody inherits.
 *
 * ```php
 * $lock = new SharedLock('reports:nightly', 3600);
 *
 * if (!$lock->acquire()) {
 *     return;   // another server has it
 * }
 *
 * try {
 *     // …
 * } finally {
 *     $lock->release();
 * }
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class SharedLock
{
    /** The table, in the framework's own schema. */
    public const TABLE = 'pramnos.locks';

    /**
     * Who holds it: `host:pid:token`.
     *
     * The host and pid are for a person reading `pramnos.locks` and wondering which
     * machine is busy. The **token** is what makes the value an identity rather than a
     * description: `release()` is scoped to the owner so an evicted holder cannot delete
     * the lock its successor is working under, and `host:pid` alone does not separate two
     * locks in one process — including a process that took the lock, lost the lease, and
     * took it again.
     */
    private string $owner;

    /** Whether this instance is currently holding it. */
    private bool $held = false;

    /**
     * @param string $name Anything unique to the work being protected
     * @param int    $ttl  Seconds before an unreleased lock may be taken over. Longer than
     *                     the work can take, by a margin.
     */
    public function __construct(
        private string $name,
        private int $ttl = 3600,
        private ?Database $db = null
    ) {
        $this->owner = (gethostname() ?: 'unknown') . ':' . getmypid()
            . ':' . bin2hex(random_bytes(4));
    }

    /** The connection, resolved late so constructing a lock connects to nothing. */
    private function db(): Database
    {
        return $this->db ??= \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * Take the lock, or report that somebody else has it.
     *
     * @return bool True when this process now holds it
     */
    public function acquire(): bool
    {
        $now = time();

        try {
            // The insert is the whole of the exclusion. A name already present is refused
            // by the primary key, so there is no moment between asking and taking.
            $this->db()->queryBuilder()->table(self::TABLE)->insert([
                'name'       => $this->name,
                'owner'      => $this->owner,
                'acquiredat' => $now,
                'expiresat'  => $now + $this->ttl,
            ]);

            return $this->held = true;
        } catch (\Throwable) {
            // Taken — or the table is not there, which the takeover below also answers by
            // failing. A lock that cannot be read must not be assumed free.
        }

        return $this->held = $this->takeOverIfExpired($now);
    }

    /**
     * Give it back.
     *
     * Scoped to this owner, so a process whose lease expired and was taken over cannot
     * delete the lock the new holder is working under — which is the failure that turns a
     * lease into two concurrent runs *and* an unlocked third.
     *
     * @return void
     */
    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        try {
            $this->db()->queryBuilder()->table(self::TABLE)
                ->where('name', $this->name)
                ->where('owner', $this->owner)
                ->delete();
        } catch (\Throwable) {
            // The lease expires on its own; a failed delete costs a wait, not correctness.
        }

        $this->held = false;
    }

    /** Is this instance holding it? */
    public function isHeld(): bool
    {
        return $this->held;
    }

    /** Who holds it now, or null when nobody does. */
    public function holder(): ?string
    {
        try {
            $row = $this->db()->queryBuilder()->table(self::TABLE)
                ->where('name', $this->name)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (!$row || ($row->numRows ?? 0) === 0) {
            return null;
        }

        return (int) ($row->fields['expiresat'] ?? 0) > time()
            ? (string) ($row->fields['owner'] ?? '')
            : null;
    }

    /**
     * Clear a lease that has passed, so the insert above can be tried again.
     *
     * ## Delete then insert, rather than update
     *
     * The obvious takeover is a guarded `UPDATE … WHERE name = ? AND expiresat <= now`,
     * and it is atomic — but knowing whether it matched means reading the affected-row
     * count, and `QueryBuilder::update()` hands back a `Result` whose `numRows` is not
     * populated on every path. Reading it as a boolean would make every takeover
     * "succeed", including the ones that matched nothing: two holders of a lock that
     * exists to prevent exactly that. Measured, not assumed — `numRows` came back `null`
     * for an `UPDATE` that had plainly worked.
     *
     * So the delete is unguarded-by-count and the **insert stays the atomic step**. Two
     * nodes may both delete the same expired row; both then race one `INSERT` at a primary
     * key, and the engine lets one through. The answer to "did I get it" is the same thing
     * it was on a free lock, which means there is one mechanism here rather than two.
     *
     * The delete carries the expiry, so a live lease is never removed.
     */
    private function takeOverIfExpired(int $now): bool
    {
        try {
            // `where($column, $operator, $value)` — the operator is the *second* argument.
            // Two arguments mean `=`; three do not reorder themselves.
            $this->db()->queryBuilder()->table(self::TABLE)
                ->where('name', $this->name)
                ->where('expiresat', '<=', $now)
                ->delete();

            $this->db()->queryBuilder()->table(self::TABLE)->insert([
                'name'       => $this->name,
                'owner'      => $this->owner,
                'acquiredat' => $now,
                'expiresat'  => $now + $this->ttl,
            ]);

            return true;
        } catch (\Throwable) {
            // Either the lease is live, or another node won the same race. Both are
            // "somebody else has it", which is the honest answer to give the caller.
            return false;
        }
    }
}
