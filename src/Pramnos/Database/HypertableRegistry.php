<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * The framework's declaration of which tables are meant to be hypertables.
 *
 * These parameters — the time column, the chunk interval, when to compress,
 * how long to keep — belong to the framework, because the framework decides
 * what these tables are for. They used to be written out inside each migration
 * and nowhere else, which made them unreachable to anything but a fresh
 * install.
 *
 * That matters because `ifCapable(TIMESCALEDB, …)` is the right tool for
 * *creating* a hypertable and the wrong one for its lifecycle. An installation
 * that ran these migrations before TimescaleDB was present keeps plain tables
 * for ever: the migration is recorded as applied, so it never runs again. The
 * tables are correct in every other respect — the composite primary keys
 * `(id, <time column>)` are created unconditionally, so they are already
 * hypertable-ready — but they are never partitioned, never compressed, and,
 * most consequentially, **their retention policies never apply, so they grow
 * without bound**.
 *
 * So the declaration lives here, once, and two things read it: the migrations
 * that create these tables, and `timescale:ensure`, which repairs a database
 * that gained the extension later. Neither holds a copy — a copy of somebody
 * else's policy values drifts the first time they change, and then the two
 * disagree silently.
 *
 * Applications register their own the same way:
 *
 * ```php
 * HypertableRegistry::register('readings', [
 *     'time_column'    => 'measured_at',
 *     'chunk_interval' => '1 day',
 *     'compress_after' => '7 days',
 *     'retention'      => '2 years',
 *     'segmentby'      => 'device_id',
 * ]);
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class HypertableRegistry
{
    /**
     * Every declared hypertable, keyed by its logical table name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $tables = [];

    /** @var bool Whether the framework's own declarations have been loaded */
    protected static bool $defaultsLoaded = false;

    /**
     * Declare a table as a hypertable, or replace an existing declaration.
     *
     * @param string               $table Logical name, e.g. `authserver.user_consents`
     * @param array<string, mixed> $spec  {
     *     @type string      $time_column    Column to partition on. Required.
     *     @type string      $chunk_interval Chunk size, e.g. '7 days'. Required.
     *     @type string|null $compress_after When to compress chunks; null = never
     *     @type string|null $retention      When to drop chunks; null = keep for ever
     *     @type string|null $segmentby      Compression segment-by columns
     *     @type string|null $orderby        Compression order-by clause
     *     @type bool        $deferred_writes Whether late rows should be queued
     *                                       rather than lost; see
     *                                       {@see DeferredWriteQueue}
     *     @type list<string>|null $conflict Columns identifying an existing row,
     *                                       for a deferred write that should
     *                                       overwrite rather than duplicate
     *     @type list<string>|null $conflict_update Columns to rewrite on a
     *                                       conflict; all non-key columns when
     *                                       omitted
     *     @type string      $feature        Feature key that owns the table
     * }
     */
    public static function register(string $table, array $spec): void
    {
        static::ensureDefaults();

        static::$tables[$table] = $spec + static::specDefaults();
    }

    /**
     * Every declared hypertable.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        static::ensureDefaults();

        return static::$tables;
    }

    /**
     * One declaration, or null when the table is not declared.
     *
     * @return array<string, mixed>|null
     */
    public static function spec(string $table): ?array
    {
        static::ensureDefaults();

        return static::$tables[$table] ?? null;
    }

    /**
     * The declared tables whose late writes should be queued rather than lost.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function deferrable(): array
    {
        return array_filter(
            static::all(),
            static fn(array $spec): bool => !empty($spec['deferred_writes'])
        );
    }

    /**
     * Forget every declaration, including the framework's own.
     *
     * For tests that need to reason about a registry they control. The next
     * read reloads the framework defaults.
     */
    public static function reset(): void
    {
        static::$tables        = [];
        static::$defaultsLoaded = false;
    }

    /**
     * Bring one declared table in line with its declaration.
     *
     * Every step is guarded by its own existence check, so this is safe to run
     * against a fresh table, a fully-configured one, or anything in between —
     * which is what lets a migration and a repair command share one code path.
     *
     * The order is not arbitrary. A compression policy on a table that is not
     * yet a hypertable raises, and so does compression on one where the setting
     * was never enabled, so: convert, enable, then the two policies.
     *
     * `migrate_data => true` is what makes this a repair rather than a
     * create-only step: an existing table with rows is rewritten into chunks.
     * On a years-old audit table that is neither instant nor lock-free, which
     * is why the command that calls this says so first.
     *
     * @param  SchemaBuilder $schema
     * @param  string        $table Logical table name, as declared
     * @return array<int, string>   What was done, in order; empty means nothing
     *                              needed doing
     */
    public static function apply(SchemaBuilder $schema, string $table): array
    {
        $spec = static::spec($table);
        if ($spec === null) {
            return [];
        }

        $done = [];

        if (!$schema->hasHypertable($table)) {
            $schema->createHypertable($table, (string) $spec['time_column'], [
                'chunk_time_interval' => (string) $spec['chunk_interval'],
                'migrate_data'        => true,
                'if_not_exists'       => true,
            ]);
            $done[] = 'converted to hypertable';
        }

        if (!$schema->isCompressionEnabled($table)) {
            $options = [];
            if ($spec['segmentby'] !== null) {
                $options['segmentby'] = (string) $spec['segmentby'];
            }
            if ($spec['orderby'] !== null) {
                $options['orderby'] = (string) $spec['orderby'];
            }
            $schema->enableCompression($table, $options);
            $done[] = 'compression enabled';
        }

        if ($spec['compress_after'] !== null && !$schema->hasCompressionPolicy($table)) {
            $schema->addCompressionPolicy($table, (string) $spec['compress_after']);
            $done[] = 'compression policy added (' . $spec['compress_after'] . ')';
        }

        if ($spec['retention'] !== null && !$schema->hasRetentionPolicy($table)) {
            $schema->addRetentionPolicy(
                $table,
                (string) $spec['retention'],
                (string) $spec['time_column']
            );
            $done[] = 'retention policy added (' . $spec['retention'] . ')';
        }

        return $done;
    }

    /**
     * Load the framework's own declarations once.
     */
    protected static function ensureDefaults(): void
    {
        if (static::$defaultsLoaded) {
            return;
        }
        static::$defaultsLoaded = true;

        foreach (static::frameworkTables() as $table => $spec) {
            static::$tables[$table] = $spec + static::specDefaults();
        }
    }

    /**
     * What a declaration means when it does not say.
     *
     * One list, used for both the framework's own tables and registered ones,
     * so that a spec read from the registry always has every key — callers can
     * read `$spec['conflict']` without asking whether it is there.
     *
     * @return array<string, mixed>
     */
    protected static function specDefaults(): array
    {
        return [
            'time_column'     => 'created_at',
            'chunk_interval'  => '7 days',
            'compress_after'  => null,
            'retention'       => null,
            'segmentby'       => null,
            'orderby'         => null,
            'deferred_writes' => false,
            'conflict'        => null,
            'conflict_update' => null,
            'feature'         => '',
        ];
    }

    /**
     * The framework's hypertables and their intended parameters.
     *
     * This is the table that used to be spread across seven migrations.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function frameworkTables(): array
    {
        return [
            'tokenactions' => [
                'time_column'    => 'action_time',
                'chunk_interval' => '14 days',
                'compress_after' => '60 days',
                'retention'      => '3 years',
                'segmentby'      => 'tokenid, urlid, method',
                'orderby'        => 'action_time DESC',
                'feature'        => 'auth',
            ],
            'authserver.twofactor_attempts' => [
                'time_column'    => 'attempt_time',
                'chunk_interval' => '7 days',
                'compress_after' => '7 days',
                'retention'      => '2 years',
                'feature'        => 'auth',
            ],
            'authserver.user_activity_log' => [
                'time_column'    => 'created_at',
                'chunk_interval' => '1 day',
                'compress_after' => '30 days',
                'retention'      => '24 months',
                'feature'        => 'auth',
            ],
            'authserver.user_consents' => [
                'time_column'    => 'granted_at',
                'chunk_interval' => '1 month',
                'compress_after' => '6 months',
                'retention'      => '7 years',
                'feature'        => 'auth',
            ],
            'authserver.data_processing_records' => [
                'time_column'    => 'processed_at',
                'chunk_interval' => '1 week',
                'compress_after' => '90 days',
                'retention'      => '36 months',
                'feature'        => 'auth',
            ],
            'authserver.gdpr_requests' => [
                'time_column'    => 'requested_at',
                'chunk_interval' => '1 month',
                'compress_after' => '1 year',
                'retention'      => '7 years',
                'feature'        => 'auth',
            ],
            'applications.application_stats' => [
                'time_column'    => 'time',
                'chunk_interval' => '14 days',
                'compress_after' => '60 days',
                'retention'      => '3 years',
                'segmentby'      => 'appid',
                'orderby'        => 'time DESC',
                'feature'        => 'applications',
            ],
        ];
    }
}
