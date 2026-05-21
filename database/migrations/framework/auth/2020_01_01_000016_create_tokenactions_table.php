<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the tokenactions table — API call audit log per token.
 *
 * Records every API request made using a usertokens entry. Each row captures
 * the token, URL, HTTP method, parameters, timing, and response status.
 *
 * On TimescaleDB: converted to a hypertable partitioned by action_time (14-day
 * chunks) with compression after 60 days. This allows efficient range queries
 * over recent activity and transparent compression of historical data.
 *
 * On MySQL / plain PostgreSQL: a regular table is created. Range queries work
 * correctly but without TimescaleDB's chunk-level optimisations. The composite
 * primary key (actionid, action_time) is kept for schema consistency.
 *
 * The sync trigger (PostgreSQL only) keeps servertime and action_time in sync
 * bidirectionally, preserving backwards compatibility with code that writes
 * the legacy integer servertime column.
 *
 * @package PramnosFramework
 */
class CreateTokenactionsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 70;
    public array   $dependencies = ['create_usertokens_table', 'create_urls_table'];
    public $description  = 'Creates the tokenactions API call log table (hypertable on TimescaleDB)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('tokenactions')) {
            return;
        }

        $schema->createTable('tokenactions', function ($table) {
            $table->comment('API call audit log — one row per API request; partitioned by action_time on TimescaleDB');

            $table->increments('actionid')
                ->comment('Auto-increment action identifier (part of composite PK with action_time for TimescaleDB compatibility)');
            $table->integer('tokenid')
                ->comment('FK to usertokens.tokenid — identifies the token used for this request');
            $table->integer('urlid')
                ->comment('FK to urls.urlid — deduplicated URL of the endpoint that was called');
            $table->string('method', 6)
                ->comment('HTTP method: GET | POST | PUT | PATCH | DELETE');
            $table->text('params')
                ->comment('Request parameters — URL query string or POST body (may be truncated for large payloads)');
            $table->integer('servertime')->default(0)
                ->comment('Unix timestamp of the request (legacy integer; kept in sync with action_time via trigger on PostgreSQL)');
            $table->integer('return_status')->nullable()
                ->comment('HTTP response status code returned by the API (e.g. 200, 404, 500)');
            $table->decimal('execution_time_ms', 10, 3)->nullable()
                ->comment('Total request execution time in milliseconds (including DB queries)');
            $table->jsonb('return_data')->nullable()
                ->comment('Response data snapshot — JSONB on PostgreSQL for indexability; JSON on MySQL');
            $table->timestampTz('action_time')->useCurrent()
                ->comment('Timestamp with timezone of the request — time dimension for TimescaleDB partitioning');

            // Composite PK: required for TimescaleDB hypertables (partition key must be part of PK).
            // On MySQL/plain PG this is simply a composite primary key — functionally correct.
            $table->primary(['actionid', 'action_time']);

            $table->index(['action_time', 'tokenid'], 'idx_tokenactions_time_tokenid');
            $table->index(['action_time', 'urlid'], 'idx_tokenactions_time_urlid');
            $table->index(['action_time', 'return_status'], 'idx_tokenactions_time_status');
        });

        // TimescaleDB: convert to hypertable with 14-day chunks and 60-day compression
        $schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, function ($schema) {
            $schema->createHypertable('tokenactions', 'action_time', [
                'chunk_time_interval' => '14 days',
                'migrate_data'        => true,
            ]);
            $schema->enableCompression('tokenactions', [
                'segmentby' => 'tokenid, urlid, method',
                'orderby'   => 'action_time DESC',
            ]);
            $schema->addCompressionPolicy('tokenactions', '60 days');
        });

        // PostgreSQL: sync trigger keeps servertime (legacy UNIX int) and
        // action_time (TIMESTAMPTZ) in sync bidirectionally so old code that
        // writes only servertime still gets a correct action_time for range queries.
        $schema->ifCapable(DatabaseCapabilities::ENGINE_POSTGRESQL, function () {
            $db = $this->application->database;
            $db->query(
                "CREATE OR REPLACE FUNCTION sync_tokenactions_time() RETURNS TRIGGER AS $$\n"
                . "BEGIN\n"
                . "  IF NEW.servertime IS NOT NULL AND NEW.servertime <> 0 THEN\n"
                . "    NEW.action_time = TO_TIMESTAMP(NEW.servertime);\n"
                . "  ELSE\n"
                . "    NEW.action_time = CURRENT_TIMESTAMP;\n"
                . "    NEW.servertime = EXTRACT(EPOCH FROM NEW.action_time)::INTEGER;\n"
                . "  END IF;\n"
                . "  RETURN NEW;\n"
                . "END;\n"
                . "$$ LANGUAGE plpgsql;"
            );
            $db->query(
                'CREATE OR REPLACE TRIGGER sync_tokenactions_time'
                . ' BEFORE INSERT OR UPDATE ON tokenactions'
                . ' FOR EACH ROW EXECUTE FUNCTION sync_tokenactions_time()'
            );
        });
    }

    public function down(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db = $this->application->database;
            $db->query('DROP TRIGGER IF EXISTS sync_tokenactions_time ON tokenactions');
            $db->query('DROP FUNCTION IF EXISTS sync_tokenactions_time()');
        }

        $this->application->database->schema()->dropTableIfExists('tokenactions');
    }
}
