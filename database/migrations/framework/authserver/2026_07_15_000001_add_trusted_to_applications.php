<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds the `trusted` column to the applications table.
 *
 * A trusted application (typically a first-party / internal client) skips the
 * OAuth2 consent screen during the authorization-code flow: the authorization
 * server issues the code silently instead of prompting the user. Third-party
 * clients keep `trusted = 0` and continue to see the consent screen exactly as
 * before.
 *
 * Additive & non-breaking:
 *   - SMALLINT NOT NULL DEFAULT 0 → existing rows become 0 (= untrusted =
 *     current behaviour); the default lets the column be added instantly
 *     without rewriting rows.
 *   - No foreign key. Idempotent via hasColumn() so it is safe to run on
 *     installations whose applications table already exists.
 *
 * Uses the current-date timestamp prefix (framework rule §9) so it auto-runs on
 * existing installations whose migration_cutoff skips the 2020_01_01 baseline.
 */
class AddTrustedToApplications extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 60;
    public array  $dependencies = ['create_applications_table'];
    public $description  = 'Adds trusted column to applications for silent (no-consent) auto-approve';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('applications', 'trusted')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->smallInteger('trusted')->default(0)
                ->comment('1 = trusted first-party client: skips the OAuth2 consent screen; 0 = untrusted (default)');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('applications', 'trusted')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->dropColumn('trusted');
        });
    }
}
