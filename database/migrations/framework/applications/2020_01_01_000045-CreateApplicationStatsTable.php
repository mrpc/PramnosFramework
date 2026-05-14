<?php

namespace Pramnos\Database\Migrations;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * CreateApplicationStatsTable migration.
 *
 * Creates the applications.application_stats hypertable for storing time-series metrics
 * about API requests, response times, status codes, rate limiting, and data transfer.
 *
 * On TimescaleDB (PostgreSQL): Creates as hypertable with 14-day chunks and compression.
 * On MySQL/PostgreSQL: Creates as regular table.
 *
 * Dependent on: CreateApplicationsTable (000025)
 */
class CreateApplicationStatsTable extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up()
    {
        $this->schema('applications')
            ->create('application_stats', function ($table) {
                // Time dimension (partition key on TimescaleDB)
                $table->timestamp('time')->useCurrent();

                // Application reference
                $table->integer('appid')->unsigned()->notNull();

                // Request metrics
                $table->bigInteger('total_requests')->unsigned()->default(0);
                $table->bigInteger('successful_requests')->unsigned()->default(0);
                $table->bigInteger('failed_requests')->unsigned()->default(0);

                // Response time metrics (milliseconds)
                $table->decimal('avg_response_time', 10, 3)->nullable();
                $table->decimal('min_response_time', 10, 3)->nullable();
                $table->decimal('max_response_time', 10, 3)->nullable();

                // HTTP status code counts
                $table->bigInteger('status_2xx')->unsigned()->default(0);
                $table->bigInteger('status_3xx')->unsigned()->default(0);
                $table->bigInteger('status_4xx')->unsigned()->default(0);
                $table->bigInteger('status_5xx')->unsigned()->default(0);

                // Rate limiting stats
                $table->bigInteger('rate_limited_requests')->unsigned()->default(0);
                $table->integer('rate_limit_violations')->unsigned()->default(0);

                // Data transfer
                $table->bigInteger('bytes_sent')->unsigned()->default(0);
                $table->bigInteger('bytes_received')->unsigned()->default(0);

                // Unique users/IPs (approximate using HyperLogLog in application logic)
                $table->integer('unique_ips_approx')->unsigned()->default(0);

                // Geographic data
                $table->char('country_code', 2)->nullable();

                // Composite index on app+time
                $table->index(['appid', 'time'], 'idx_application_stats_appid_time');

                // Foreign keys
                $table->foreign('appid')
                    ->references('appid')
                    ->on('public.applications')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });

        // Convert to hypertable on TimescaleDB
        if ($this->DB()->capabilities()->supports(DatabaseCapabilities::TIMESCALEDB)) {
            $this->DB()->statement(
                "SELECT create_hypertable('applications.application_stats', 'time', 
                    chunk_time_interval => INTERVAL '14 days', if_not_exists => TRUE);"
            );

            // Enable compression for data older than 30 days
            $this->DB()->statement("
                ALTER TABLE applications.application_stats
                SET (timescaledb.compress, timescaledb.compress_chunk_time_interval = '30 days');
            ");

            // Create compression policy
            $this->DB()->statement("
                SELECT add_compression_policy('applications.application_stats', 
                    INTERVAL '30 days', if_not_exists => TRUE);
            ");
        }
    }

    /**
     * Rollback the migration.
     *
     * @return void
     */
    public function down()
    {
        // Drop compression policies if using TimescaleDB
        if ($this->DB()->capabilities()->supports(DatabaseCapabilities::TIMESCALEDB)) {
            $this->DB()->statement("
                SELECT remove_compression_policy('applications.application_stats', if_exists => TRUE);
            ");
        }

        // Drop table
        $this->schema('applications')
            ->dropIfExists('application_stats');
    }
}
