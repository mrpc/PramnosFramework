<?php

declare(strict_types=1);

namespace Pramnos\Changelog;

use Pramnos\Database\WriteSpool;
use Pramnos\Event\ListenerInterface;
use Pramnos\Event\ModelChange;

/**
 * Writes model changes down, without the request paying for it.
 *
 * Registered on the change feed when the `changelog` feature is enabled. A model that
 * sets `$emitChanges` then has an audit trail, with no further wiring.
 *
 * ## Through the spool, not through an insert
 *
 * The numbers are measured and in this repository: **2.807 ms** for an insert into a
 * hypertable with indexes, **0.003 ms** for the file append {@see WriteSpool} does
 * instead — a factor of roughly nine hundred. `spool:drain` writes the rows in batches a
 * minute later, and `FrameworkSchedule` already runs it.
 *
 * That is the whole performance story, and none of it was written here.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangelogWriter implements ListenerInterface
{
    /** The automatic feed. */
    public const TABLE = 'pramnos.changelog';

    /** What the application writes deliberately. */
    public const EVENTS_TABLE = 'pramnos.changelog_events';

    /** Request context and stack traces, kept for days. */
    public const TRACE_TABLE = 'pramnos.changelog_trace';

    /**
     * Register on the change feed. Safe to call repeatedly.
     */
    public static function listen(): void
    {
        foreach (\Pramnos\Event\Event::getListeners(\Pramnos\Event\ChangeFeed::EVENT) as $listener) {
            if ($listener instanceof self || $listener === self::class) {
                return;
            }
        }

        \Pramnos\Event\Event::listen(\Pramnos\Event\ChangeFeed::EVENT, self::class);
    }

    /**
     * Append one change to the spool.
     *
     * Failure is swallowed and logged. The write it describes has already committed, and
     * an audit row that cannot be queued is not a reason to fail the thing the user asked
     * for — nor is there anything the caller could do about it.
     */
    public function handle(mixed ...$args): mixed
    {
        $change = $args[0] ?? null;
        if (!$change instanceof ModelChange) {
            return null;
        }

        try {
            WriteSpool::append(self::TABLE, [
                'entity'     => $change->entity,
                'itemid'     => (string) $change->key,
                'op'         => $change->op,
                // Already stripped of the model's ignore list when the change was emitted.
                'changes'    => $change->changes,
                'userid'     => $change->userid,
                'source'     => $change->source,
                'created_at' => date('c', $change->at),
            ]);

            $this->appendTrace($change);
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError(
                'Changelog write failed for ' . $change->entity . ' '
                . (string) $change->key . ': ' . $ex->getMessage(),
                $ex
            );
        }

        return null;
    }

    /**
     * Write the request context, when the model asked for it.
     *
     * Opt-in per model through `$captureTrace`, because it is not free: the reference
     * application calls `getTraceAsString()` on every device save, and that cost is why
     * this is off unless somebody is chasing something.
     *
     * Carries the feed row's natural key rather than its id. A surrogate would have to be
     * generated before the row exists — the spool does not insert until the drain — which
     * means a database round trip per change, in the request, undoing the append this is
     * built on.
     */
    protected function appendTrace(ModelChange $change): void
    {
        if (!$change->captureTrace) {
            return;
        }

        WriteSpool::append(self::TRACE_TABLE, [
            'entity'      => $change->entity,
            'itemid'      => (string) $change->key,
            'created_at'  => date('c', $change->at),
            'trace'       => $change->trace,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip_address'  => \Pramnos\Http\Request::clientIp(),
            'context'     => null,
        ]);
    }

}
