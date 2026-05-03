<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the urls table — URL lookup table used by token action tracking.
 *
 * Stores a deduplicated list of URLs that have been accessed via API tokens.
 * Each URL is stored once with a CRC32 hash for fast lookup. The tokenactions
 * table references this table by urlid rather than storing the full URL string
 * in every row, keeping tokenactions compact for time-series storage.
 *
 * @package PramnosFramework
 */
class CreateUrlsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 60;
    public string  $description  = 'Creates the urls URL deduplication table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('urls')) {
            return;
        }

        $schema->createTable('urls', function ($table) {
            $table->comment('Deduplicated URL registry — each unique API endpoint URL is stored once; tokenactions references by urlid');

            $table->increments('urlid')
                ->comment('Auto-increment URL identifier');
            $table->string('url', 255)->nullable()
                ->comment('Full URL path (e.g. /api/v1/users/42); NULL for legacy entries');
            $table->bigInteger('hash')
                ->comment('CRC32 hash of the URL for fast lookup without full string comparison');

            $table->index(['hash'], 'idx_urls_hash');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('urls');
    }
}
