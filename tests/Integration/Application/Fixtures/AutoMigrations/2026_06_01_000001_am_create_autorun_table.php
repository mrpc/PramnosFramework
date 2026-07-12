<?php

namespace Pramnos\Tests\Integration\Application\Fixtures\AutoMigrations;

use Pramnos\Database\Migration;

/**
 * Test migration fixture — autoExecute=true.
 *
 * Creates the `am_autorun_test` table.  Used by ApplicationAutoMigrations*Test
 * to verify that Application::runAutoMigrations() runs this migration
 * automatically without `pramnos migrate`.
 *
 * Uses portable SQL (no backticks, no schema-specific syntax) so the same
 * fixture works against both MySQL and PostgreSQL.
 */
class AmCreateAutorunTable extends Migration
{
    public string $feature  = 'test';
    public string $scope    = 'framework';
    public $autoExecute     = true;
    public $description     = 'Creates am_autorun_test — fixture for auto-migration tests';

    public function up(): void
    {
        $this->application->database->query(
            'CREATE TABLE IF NOT EXISTS am_autorun_test (id INTEGER NOT NULL PRIMARY KEY)'
        );
    }

    public function down(): void
    {
        $this->application->database->query('DROP TABLE IF EXISTS am_autorun_test');
    }
}
