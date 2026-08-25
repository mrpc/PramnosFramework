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

        if ($spec['compress_after'] !== null) {
            if (!$schema->hasCompressionPolicy($table)) {
                $schema->addCompressionPolicy($table, (string) $spec['compress_after']);
                $done[] = 'compression policy added (' . $spec['compress_after'] . ')';
            } elseif (static::hasDrifted($schema, $table, 'compression', (string) $spec['compress_after'])) {
                // Removed and re-added, because add_compression_policy() raises on a
                // duplicate. Until there was a remove there was no replace, and a changed
                // declaration simply never reached the database.
                $schema->removeCompressionPolicy($table);
                $schema->addCompressionPolicy($table, (string) $spec['compress_after']);
                $done[] = 'compression policy changed to ' . $spec['compress_after'];
            }
        }

        if ($spec['retention'] !== null) {
            if (!$schema->hasRetentionPolicy($table)) {
                $schema->addRetentionPolicy(
                    $table,
                    (string) $spec['retention'],
                    (string) $spec['time_column']
                );
                $done[] = 'retention policy added (' . $spec['retention'] . ')';
            } elseif (static::hasDrifted($schema, $table, 'retention', (string) $spec['retention'])) {
                $schema->removeRetentionPolicy($table);
                $schema->addRetentionPolicy(
                    $table,
                    (string) $spec['retention'],
                    (string) $spec['time_column']
                );
                $done[] = 'retention policy changed to ' . $spec['retention'];
            }
        }

        return $done;
    }

    /**
     * Merge per-table overrides from application config over the declarations.
     *
     * ```php
     * // app/app.php
     * 'hypertables' => [
     *     'pramnos.changelog' => ['retention' => '180 days'],
     *     'tokenactions'      => ['compress_after' => '30 days'],
     * ],
     * ```
     *
     * Until this existed, retuning any framework hypertable meant editing the framework.
     * Seven tables are declared here and none of their intervals fit every installation:
     * a busy API's `tokenactions` and a quiet one's are the same declaration and very
     * different amounts of disk.
     *
     * A partial override changes only the keys it names; everything else keeps the
     * framework's value. Overriding an undeclared table registers it, so an application
     * can declare its own tables here as well as retune ours.
     *
     * Same shape as `FeatureRegistry::loadFromConfig()`, and called from the same place
     * in the application's boot.
     *
     * @param array<string, array<string, mixed>> $overrides
     */
    public static function loadOverridesFromConfig(array $overrides): void
    {
        static::ensureDefaults();

        foreach ($overrides as $table => $spec) {
            if (!is_string($table) || $table === '' || !is_array($spec)) {
                continue;
            }

            $existing = static::$tables[$table] ?? static::specDefaults();

            // Only the keys the spec knows about, so a typo in app.php cannot smuggle an
            // unknown option into a create_hypertable() call.
            $known = array_intersect_key($spec, static::specDefaults());

            static::$tables[$table] = $known + $existing;
        }
    }

    /**
     * Does the live policy disagree with what the declaration asks for?
     *
     * **Answers false when it cannot tell.** That bias is deliberate: a false positive
     * removes and re-adds a policy on every single run — churn against the scheduler,
     * for ever, over a formatting difference — while a false negative costs one changed
     * number not taking effect, which is the situation this whole mechanism arrived to
     * improve rather than a regression.
     *
     * @param  string $kind      `retention` or `compression`
     * @param  string $declared  The interval from the declaration
     */
    protected static function hasDrifted($schema, string $table, string $kind, string $declared): bool
    {
        $actual = $schema->policyInterval($table, $kind);

        if ($actual === null) {
            return false;
        }

        $left  = static::normaliseInterval($actual);
        $right = static::normaliseInterval($declared);

        if ($left === '' || $right === '') {
            return false;
        }

        return $left !== $right;
    }

    /**
     * Reduce an interval to something two spellings of the same duration share.
     *
     * PostgreSQL hands back `@ 90 days`, a declaration says `90 days`, and a job config
     * may say `90 day`. None of those are a difference worth churning a policy over.
     * Anything this cannot reduce comes back empty, which {@see hasDrifted()} reads as
     * "do not touch it".
     */
    protected static function normaliseInterval(string $interval): string
    {
        $value = strtolower(trim($interval));
        $value = ltrim($value, '@ ');
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        if (!preg_match('/^(\d+)\s*(second|minute|hour|day|week|month|year)s?$/', $value, $m)) {
            return '';
        }

        return $m[1] . ' ' . $m[2];
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
                // `urlid, method` rather than `tokenid, urlid, method`, and the
                // difference is not a preference. Measured on 2 M rows, 60 endpoints,
                // 90 days (tests/Benchmarks/tokenactions_compression.php):
                //
                // |                          | ratio |  stored | by-token | by-url |
                // |--------------------------|-------|---------|----------|--------|
                // | few long-lived tokens    |       |         |          |        |
                // |   tokenid, urlid, method |  6.95 |  36.8MB |   0.41ms | 0.65ms |
                // |   urlid, method          |  7.72 |  33.0MB |   6.83ms | 0.44ms |
                // | many short-lived tokens  |       |         |          |        |
                // |   tokenid, urlid, method |  0.50 | 515.5MB |   5.44ms | 38.5ms |
                // |   urlid, method          |  6.76 |  37.9MB |   6.68ms | 0.46ms |
                //
                // With `tokenid` in the segment key the layout is excellent for an API
                // whose callers are a handful of long-lived server-to-server tokens —
                // 0.41 ms on "what did this token do" — and collapses for one whose
                // callers are browser sessions: a ratio of 0.50 means compression made
                // the table **larger**, 515 MB against 38 MB, and it is not even faster
                // there, losing on every axis at once.
                //
                // A framework default cannot know which an installation has, and the bad
                // case is silent. So the default is the layout that is never bad, and an
                // installation that knows its callers are few and long-lived takes the
                // 0.41 ms deliberately:
                //
                //     'hypertables' => [
                //         'tokenactions' => ['segmentby' => 'tokenid, urlid, method'],
                //     ],
                //
                // The cost of the safe choice is a token-history listing at 6.8 ms rather
                // than 0.4 ms — an admin screen, not a hot path, and the analytical reads
                // go through the hourly continuous aggregate rather than this table.
                //
                // Existing installations are unaffected: apply() sets compression only on
                // a table that has none, so this reaches new databases only.
                'segmentby'      => 'urlid, method',
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
            // The change log's three populations. Declared here rather than in the
            // migration so an application can retune them from app.php — no single
            // retention fits every installation's write rate.
            //
            // `segmentby entity` with the high-cardinality `itemid` first in `orderby`,
            // rather than the reference application's `segmentby itemtype, itemid`.
            // Measured on 2 M rows, 12 entities, 240 k records over 30 days
            // (tests/Benchmarks/changelog_compression.php):
            //
            // |            | ratio | stored  | compress | per-row | recent |
            // |------------|-------|---------|----------|---------|--------|
            // | this, 7d   | 12.82 |  37.5MB |     5.8s |  11.0ms |  4.8ms |
            // | this, 1d   | 10.02 |  48.7MB |     4.6s |  12.7ms |  2.6ms |
            // | itemid, 7d |  0.89 | 543.0MB |    74.6s |  16.8ms | 176ms  |
            // | itemid, 1d |  0.59 | 822.0MB |   133.6s |   2.2ms | 53ms   |
            //
            // A ratio below 1 means compression made the table **larger**. A change log
            // is sparse per record, so `itemid` in `segmentby` produces segments of a
            // few rows each — far below the 1000-row batch Timescale compresses into —
            // and the per-segment overhead then exceeds the saving. 822 MB against
            // 37.5 MB for the same rows.
            //
            // The one thing that layout wins is the per-row lookup at 1-day chunks,
            // 2.2 ms against 11.0 ms, because `itemid` in `segmentby` locates the
            // segment directly. It costs 22x the disk and compression that does not
            // compress, so it is not the default — but it is the right answer for a log
            // read constantly and kept briefly, which is what `loadOverridesFromConfig()`
            // is for.
            //
            // The trace table gets no compression at all: at three days nothing lives
            // long enough for it to repay the CPU.
            'pramnos.changelog' => [
                'time_column'     => 'created_at',
                'chunk_interval'  => '7 days',
                'compress_after'  => '7 days',
                'retention'       => '30 days',
                'segmentby'       => 'entity',
                'orderby'         => 'itemid, created_at DESC',
                'deferred_writes' => true,
                'feature'         => 'changelog',
            ],
            'pramnos.changelog_events' => [
                'time_column'     => 'created_at',
                'chunk_interval'  => '7 days',
                'compress_after'  => '30 days',
                'retention'       => '2 years',
                'segmentby'       => 'entity',
                'orderby'         => 'itemid, created_at DESC',
                'deferred_writes' => true,
                'feature'         => 'changelog',
            ],
            'pramnos.changelog_trace' => [
                'time_column'     => 'created_at',
                'chunk_interval'  => '1 day',
                'compress_after'  => null,
                'retention'       => '3 days',
                'feature'         => 'changelog',
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
