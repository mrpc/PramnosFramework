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
     * Record that something happened to a record — without a model in hand.
     *
     * ```php
     * ChangelogWriter::event('user', $userId, 'newsletter.opened', ['list' => 'news']);
     * ChangelogWriter::event('device', 42, 'device.replaced', ['by' => 43], by: $operatorId);
     * ChangelogWriter::event('user', $row['userid'], 'user.login', at: (int) $row['date'], by: 0);
     * ```
     *
     * What {@see \Pramnos\Application\Model::logEvent()} writes, for any `(entity, itemid)`:
     * a user, which is not a model, an organisation with no model of its own, a row of an
     * application's own log brought over. The same row in `changelog_events`, through the same
     * spool.
     *
     * - `$by` is who did it. Null asks who is signed in; `0` is nobody — a system event, or an
     *   import that does not know. A console command acting for somebody says who.
     * - `$at` is when, as a Unix time: now unless an import says otherwise.
     * - `$scope` is the tenant, as {@see \Pramnos\Application\Model::changeScope()} gives it.
     * - `$source` is `web`, `api` or `cli`, worked out unless given.
     *
     * Requires the `changelog` feature; without it the row has nowhere to go and the call
     * does nothing. A failure is logged, never thrown: recording that something happened must
     * not be the reason it fails to.
     *
     * @param array<string, mixed> $details Whatever the event carries
     */
    public static function event(
        string $entity,
        string|int $itemid,
        string $event,
        array $details = [],
        int $logtype = 0,
        ?string $description = null,
        ?int $by = null,
        ?string $scope = null,
        ?int $at = null,
        ?string $source = null
    ): void {
        try {
            static::registerEncoders();
            WriteSpool::append(self::EVENTS_TABLE, [
                'entity'      => $entity,
                'itemid'      => (string) $itemid,
                'event'       => $event,
                'logtype'     => $logtype,
                // Encoded here, not by the drain's transformer: a drain in a process where the
                // changelog provider did not boot has none, and the column refuses an array.
                'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'description' => $description,
                'userid'      => $by === null ? static::currentUserId() : ($by > 0 ? $by : null),
                'source'      => $source ?? static::currentSource(),
                // Not date('c'): MariaDB drops an offset, and strict mode refuses the row.
                'created_at'  => \Pramnos\Database\Instant::local($at ?? time()),
            ] + ($scope !== null ? ['scope' => $scope] : []));
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError(
                'Changelog event "' . $event . '" failed for ' . $entity . ' ' . $itemid . ': ' . $ex->getMessage(),
                $ex
            );
        }
    }

    /**
     * Who is signed in, when that is knowable; null anywhere it is not — a worker, a console
     * command, an anonymous request. Identity is context the log carries when it has it, never
     * a precondition for writing.
     */
    public static function currentUserId(): ?int
    {
        try {
            $user = \Pramnos\User\User::getCurrentUser();
            if (is_object($user) && isset($user->userid) && (int) $user->userid > 0) {
                return (int) $user->userid;
            }
        } catch (\Throwable) {
            // Asking who is signed in must never be the reason a write reports a problem.
        }

        return null;
    }

    /**
     * Where this is happening: a browser, an API client, or neither — the first question when
     * something is writing rows nobody expected.
     */
    public static function currentSource(): string
    {
        if (static::console()) {
            return ModelChange::SOURCE_CLI;
        }

        $uri    = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $script = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (str_contains($uri, '/api/') || str_contains($script, 'api.php')) {
            return ModelChange::SOURCE_API;
        }

        return ModelChange::SOURCE_WEB;
    }

    /** Whether this is a console process — a seam, so the web and API answers can be tested. */
    protected static function console(): bool
    {
        return PHP_SAPI === 'cli' || defined('STDIN');
    }

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
     * Encode the JSON columns of all three tables on their way through the spool.
     *
     * The spool round-trips each row through JSON, so a nested array has to be re-encoded
     * before it reaches `queryBuilder()->insert()` — without it the drain writes the string
     * "Array" into a jsonb column, once per row, with no error anywhere. And when the spool
     * cannot take a row it writes it synchronously, straight away, with the array still in
     * it. These lived in the service provider's boot(), so a writer used without that
     * provider — or after `WriteSpool::reset()` — wrote the raw array. Registered here, before
     * every append, they are always in place. Idempotent.
     */
    public static function registerEncoders(): void
    {
        WriteSpool::transform(self::TABLE, static fn (array $row): array => self::encodeJson($row, ['changes']));
        WriteSpool::transform(
            self::EVENTS_TABLE,
            static fn (array $row): array => self::dropScopeWithoutColumn(self::encodeJson($row, ['details']))
        );
        WriteSpool::transform(self::TRACE_TABLE, static fn (array $row): array => self::encodeJson($row, ['context']));
    }

    /** @var bool Whether changelog_events.scope has been seen to exist */
    protected static bool $scopeColumnExists = false;

    /**
     * Drop the scope from an event when the column is not there yet.
     *
     * An application can ship a model that overrides `changeScope()` before
     * `migrate` adds the column. Without this, every event that model writes would fail at
     * the drain until the migration runs; with it, the event is kept and only its scope is
     * lost. Runs in the drain, so the check costs the request nothing.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function dropScopeWithoutColumn(array $row): array
    {
        if (!array_key_exists('scope', $row) || static::$scopeColumnExists) {
            return $row;
        }

        // ponytail: only a yes is cached, so a pre-migration drain re-asks per scoped row;
        // that lasts until `migrate`, after which it is one query per process.
        static::$scopeColumnExists = \Pramnos\Database\Database::getInstance()
            ->schema()->hasColumn(self::EVENTS_TABLE, 'scope');
        if (!static::$scopeColumnExists) {
            unset($row['scope']);
        }

        return $row;
    }

    /**
     * JSON-encode the named columns, leaving a null null.
     *
     * `null` matters: these columns are nullable, and `json_encode(null)` is the string
     * `"null"` — which a database stores as a JSON null rather than as SQL NULL, so
     * `WHERE details IS NULL` stops matching rows that have no details.
     *
     * @param  array<string, mixed> $row
     * @param  list<string>         $columns
     * @return array<string, mixed>
     */
    protected static function encodeJson(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (isset($row[$column]) && is_array($row[$column])) {
                $row[$column] = json_encode($row[$column], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }

        return $row;
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

        static::registerEncoders();

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
