<?php

namespace Pramnos\Framework\Migrations\Changelog;

use Pramnos\Database\Migration;

/**
 * Creates the change log: three tables and a read-only view over two of them.
 *
 * The tables the {@see \Pramnos\Event\ChangeFeed} writes through, when an application
 * enables the `changelog` feature. Nothing is created for an application that does not.
 *
 * ## Why three tables rather than one
 *
 * Because a TimescaleDB retention policy drops **whole chunks by time** and takes no row
 * predicate, so one table can only ever have one retention. Three populations here have
 * genuinely different answers to "how long is this worth keeping":
 *
 * | Table | What | Kept |
 * |---|---|---|
 * | `changelog` | machine diffs, one per model save | 30 days |
 * | `changelog_events` | application events — a person did something | 2 years |
 * | `changelog_trace` | the stack trace and request context behind a change | 3 days |
 *
 * The reference application arrived at the same split the expensive way, in a repair
 * migration on a live hypertable, after its automatic save log drowned everything else.
 * Its numbers are where these start.
 *
 * On an empty table the split costs nothing. On a live one it cost that project two
 * migrations, one of which decompressed every chunk to widen a primary key.
 *
 * ## Why no INSTEAD OF triggers
 *
 * That application routes writes through a view with `INSTEAD OF` triggers, because it
 * had hundreds of existing call sites naming one table and a migration that had to leave
 * every one of them alone. This has no call sites to preserve: the writer targets the
 * right table directly, and the view below is **read-only** — which is also what makes it
 * portable, since MySQL has no updatable views of that kind.
 *
 * Current-date timestamp (§9) so it runs on installations whose migration_cutoff skips
 * the 2020_01_01 baseline.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class CreateChangelogTables extends Migration
{
    /** @var string The feature that owns these tables */
    public string $feature = 'changelog';

    /** @var string Framework-level migration */
    public string $scope = 'framework';

    /** @var int After the core schema it lives in */
    public int $priority = 230;

    /** @var string What this migration does */
    public $description = 'Creates the changelog, changelog_events and changelog_trace tables and the history view';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        $this->createFeed($schema);
        $this->createEvents($schema);
        $this->createTrace($schema);
        $this->createHistoryView($schema);
    }

    /**
     * `changelog` — the automatic feed.
     *
     * Written only by ChangelogWriter, one row per emitted ModelChange. The diff and
     * nothing else: no logtype, because it would be 0 on every row; no details and no
     * description, because the feed has no prose. Removing them is what lets this table
     * carry one honest retention.
     */
    protected function createFeed($schema): void
    {
        if ($schema->hasTable('pramnos.changelog')) {
            return;
        }

        $schema->createTable('pramnos.changelog', function ($table) {
            $table->comment('Automatic model change feed — one row per save or delete');

            $table->bigIncrements('logid')
                ->comment('Auto-increment identifier (part of composite PK with created_at for TimescaleDB)');
            $table->string('entity', 64)
                ->comment("The application's own name for the thing, e.g. wcm-device");
            $table->string('itemid', 64)
                ->comment('Primary key value as a string, so a uuid or composite key fits');
            $table->string('op', 8)
                ->comment('created | updated | deleted');
            $table->jsonb('changes')->nullable()
                ->comment('field => {old, new}; the whole record of what happened');
            $table->bigInteger('userid')->nullable()
                ->comment('Who caused it, when that was knowable');
            $table->string('source', 8)
                ->comment('web | api | cli — which surface wrote the row');
            $table->timestampTz('created_at')->useCurrent()
                ->comment('When it happened; the TimescaleDB partition key');

            $table->primary(['logid', 'created_at']);

            // The history-of-this-row query, which is what this table exists for.
            $table->index(['entity', 'itemid', 'created_at'], 'idx_changelog_item');
            $table->index(['userid', 'created_at'], 'idx_changelog_user');
        });

        // segmentby entity, with the high-cardinality itemid first in orderby — measured,
        // see HypertableRegistry. The alternative that puts itemid in segmentby compresses
        // to a ratio below 1: it makes the table larger.
        $this->partition($schema, 'pramnos.changelog', '7 days', 'entity', 'itemid, created_at DESC');
    }

    /**
     * `changelog_events` — what the application writes deliberately.
     */
    protected function createEvents($schema): void
    {
        if ($schema->hasTable('pramnos.changelog_events')) {
            return;
        }

        $schema->createTable('pramnos.changelog_events', function ($table) {
            $table->comment('Application events — things a diff cannot express');

            $table->bigIncrements('eventid')
                ->comment('Auto-increment identifier (part of composite PK with created_at for TimescaleDB)');
            $table->string('entity', 64)
                ->comment('Same vocabulary as the feed, so the reader merges them without translation');
            $table->string('itemid', 64)
                ->comment('Primary key value as a string');
            $table->string('event', 64)
                ->comment('Machine code, e.g. device.assigned_on_finalize — rendered through i18n at read time');
            $table->smallInteger('logtype')->default(0)
                ->comment("The application's own categorisation; 0 when it has none");
            $table->jsonb('details')->nullable()
                ->comment('Whatever the event carries');
            $table->text('description')->nullable()
                ->comment('Free-text escape hatch for an event no code describes');
            $table->bigInteger('userid')->nullable()
                ->comment('Who did it, when that was knowable');
            $table->string('source', 8)
                ->comment('web | api | cli');
            $table->timestampTz('created_at')->useCurrent()
                ->comment('When it happened; the TimescaleDB partition key');

            $table->primary(['eventid', 'created_at']);

            $table->index(['entity', 'itemid', 'created_at'], 'idx_changelog_events_item');
            $table->index(['userid', 'created_at'], 'idx_changelog_events_user');
        });

        $this->partition($schema, 'pramnos.changelog_events', '7 days', 'entity', 'itemid, created_at DESC');
    }

    /**
     * `changelog_trace` — the heavy payload behind a feed row.
     *
     * Split off so the feed's chunks stay small, and given a far shorter retention than
     * the rows it describes: what changed is worth a month, the stack trace that produced
     * it is worth about as long as an incident investigation.
     *
     * No foreign key. TimescaleDB discourages them between hypertables, and a trace
     * outliving or predeceasing its row is not worth a constraint — it is expected, since
     * the two have different retentions on purpose: at three days against thirty, most
     * feed rows spend most of their life with no trace beside them.
     */
    protected function createTrace($schema): void
    {
        if ($schema->hasTable('pramnos.changelog_trace')) {
            return;
        }

        $schema->createTable('pramnos.changelog_trace', function ($table) {
            $table->comment('Request context and stack trace behind a changelog row; kept days, not months');

            // Keyed by the feed row's natural key rather than its logid, and that is not
            // a stylistic choice. A surrogate id would have to be generated *before* the
            // row is written, because the spool does not insert until the drain — which
            // means a database round trip per change, inside the request, undoing the
            // 0.003 ms append this whole design exists for. The natural key is already
            // indexed on the feed and costs nothing.
            $table->string('entity', 64)
                ->comment('Matches pramnos.changelog.entity');
            $table->string('itemid', 64)
                ->comment('Matches pramnos.changelog.itemid');
            $table->timestampTz('created_at')
                ->comment("The parent row's timestamp — part of the join, so chunk exclusion works on both sides");
            $table->text('trace')->nullable()
                ->comment('Stack trace, when the model asked for one');
            $table->string('request_uri', 2048)->nullable()
                ->comment('The URL being served, or null on the command line');
            $table->text('user_agent')->nullable()
                ->comment('HTTP User-Agent at the time');
            $table->string('ip_address', 45)->nullable()
                ->comment('IPv4 or IPv6 address of the caller');
            $table->jsonb('context')->nullable()
                ->comment('Anything else the application attaches');

            $table->index(['entity', 'itemid', 'created_at'], 'idx_changelog_trace_row');
        });

        // No segmentby: a trace is read for one row at a time, never scanned, and it is
        // dropped after three days — which is also why it gets no
        // compression policy below. Nothing lives long enough for compression to repay
        // the CPU it costs.
        $this->partition($schema, 'pramnos.changelog_trace', '1 day', null, null);
    }

    /**
     * `changelog_history` — one query over the feed and the events.
     *
     * Read-only, so writes still go to the tables directly and the view stays portable:
     * MySQL has no updatable views of the kind that would be needed otherwise.
     *
     * PostgreSQL pushes qualifiers into `UNION ALL` branches, so a query filtered by
     * entity and item reaches both tables' indexes rather than materialising the union.
     */
    protected function createHistoryView($schema): void
    {
        if ($schema->hasView('pramnos.changelog_history')) {
            return;
        }

        $feed   = $schema->resolveTableName('pramnos.changelog');
        $events = $schema->resolveTableName('pramnos.changelog_events');

        $schema->createView(
            'pramnos.changelog_history',
            "SELECT logid AS id, 'feed' AS origin, entity, itemid, op,"
            . " 0 AS logtype, NULL AS event, changes, NULL AS details, NULL AS description,"
            . " userid, source, created_at"
            . " FROM {$feed}"
            . " UNION ALL"
            . " SELECT eventid AS id, 'events' AS origin, entity, itemid, NULL AS op,"
            . " logtype, event, NULL AS changes, details, description,"
            . " userid, source, created_at"
            . " FROM {$events}"
        );
    }

    /**
     * Convert to a hypertable and set compression, where the extension exists.
     *
     * Each call is a documented no-op without TimescaleDB, so MySQL and plain PostgreSQL
     * get the plain tables above and nothing else. Retention is **not** applied here: it
     * is declared in {@see \Pramnos\Database\HypertableRegistry} so an application can
     * retune it from app.php, and `timescale:ensure` applies it.
     */
    protected function partition($schema, string $table, string $chunk, ?string $segmentBy, ?string $orderBy): void
    {
        $schema->createHypertable($table, 'created_at', [
            'chunk_time_interval' => $chunk,
            'if_not_exists'       => true,
        ]);

        if ($segmentBy === null) {
            return;
        }

        $schema->enableCompression($table, array_filter([
            'segmentby' => $segmentBy,
            'orderby'   => $orderBy,
        ]));
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        $schema->dropView('pramnos.changelog_history');
        $schema->dropTableIfExists('pramnos.changelog_trace');
        $schema->dropTableIfExists('pramnos.changelog_events');
        $schema->dropTableIfExists('pramnos.changelog');
    }
}
