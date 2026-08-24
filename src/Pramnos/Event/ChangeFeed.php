<?php

declare(strict_types=1);

namespace Pramnos\Event;

/**
 * Delivers {@see ModelChange} events, holding them back while a transaction is open.
 *
 * ```php
 * ChangeFeed::boot();
 * Event::listen(ChangeFeed::EVENT, function (ModelChange $change) {
 *     // one listener per thing you want done: broadcast, changelog, queue…
 * });
 * ```
 *
 * ## Why there is no deferral machinery beyond the transaction buffer
 *
 * The listener owns the decision of whether its work belongs in the request, and both
 * cheap options already exist: a local Redis `PUBLISH` is faster than the queue push that
 * would defer it, and {@see \Pramnos\Database\WriteSpool::append()} is a 0.003 ms file
 * append. There is nothing left for this class to optimise, so it does not try.
 *
 * ## Why the buffer exists at all
 *
 * Not for broadcasting. A broadcast carrying only identifiers costs, at worst, one wasted
 * refetch that returns the old data — it heals itself. It exists for the listeners that
 * write things down: a changelog row for a change that was rolled back is an audit trail
 * nobody can trust.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangeFeed
{
    /**
     * The one event name every model change is delivered under.
     *
     * One name rather than `model.<entity>.<op>`: two naming schemes means two
     * registrations to keep in step, and one of them gets forgotten. Listeners switch on
     * `$change->entity` and `$change->op`, which they have in hand.
     */
    public const EVENT = 'model.changed';

    /** Fired by {@see \Pramnos\Database\Database::commitTransaction()}. */
    public const EVENT_COMMITTED = 'database.transaction.committed';

    /** Fired by {@see \Pramnos\Database\Database::rollbackTransaction()}. */
    public const EVENT_ROLLED_BACK = 'database.transaction.rolledback';

    /**
     * Changes emitted since the open transaction began.
     *
     * @var list<ModelChange>
     */
    protected static array $buffer = [];

    /** Whether {@see boot()} has already registered the transaction listeners. */
    protected static bool $booted = false;

    /**
     * Register the transaction listeners. Safe to call repeatedly.
     */
    public static function boot(): void
    {
        if (static::$booted) {
            return;
        }
        static::$booted = true;

        Event::listen(self::EVENT_COMMITTED, static function (): void {
            static::flush();
        });

        Event::listen(self::EVENT_ROLLED_BACK, static function (): void {
            static::discard();
        });
    }

    /**
     * Deliver a change, or hold it until the open transaction commits.
     */
    public static function emit(ModelChange $change): void
    {
        if (static::inTransaction()) {
            static::$buffer[] = $change;

            return;
        }

        Event::fire(self::EVENT, $change);
    }

    /**
     * Deliver everything held, in the order it was emitted.
     *
     * The buffer is cleared **before** the first listener runs. A listener that saves a
     * model would otherwise emit into a buffer that is about to be cleared underneath it,
     * and that change would be delivered twice or not at all depending on where in the
     * loop it landed.
     */
    public static function flush(): void
    {
        if (static::$buffer === []) {
            return;
        }

        $pending        = static::$buffer;
        static::$buffer = [];

        foreach ($pending as $change) {
            Event::fire(self::EVENT, $change);
        }
    }

    /**
     * Drop everything held, delivering none of it.
     */
    public static function discard(): void
    {
        static::$buffer = [];
    }

    /**
     * How many changes are waiting for a commit.
     */
    public static function pending(): int
    {
        return count(static::$buffer);
    }

    /**
     * Forget the buffer and the boot flag. Tests only.
     */
    public static function reset(): void
    {
        static::$buffer = [];
        static::$booted = false;
    }

    /**
     * Is a transaction open on the default connection?
     *
     * A database that cannot be reached is not a transaction: the change is delivered
     * rather than held, because holding it would mean holding it for ever.
     *
     * ponytail: Database::inTransaction() is one flag, not a depth counter — a nested
     * startTransaction() followed by the inner commit flushes while the outer is still
     * open. Its own docblock also says a raw BEGIN through query() is untracked, so
     * changes inside one are delivered immediately. Upgrade path is a depth counter in
     * Database; both are documented in the guide rather than worked around here.
     */
    protected static function inTransaction(): bool
    {
        try {
            return \Pramnos\Database\Database::getInstance()->inTransaction();
        } catch (\Throwable) {
            return false;
        }
    }
}
