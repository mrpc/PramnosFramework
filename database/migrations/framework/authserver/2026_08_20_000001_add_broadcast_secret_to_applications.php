<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds the nullable `broadcast_secret` column to the applications table.
 *
 * When broadcasting resolves its app keys from the AuthServer, the realtime edge
 * needs an HMAC key per application. `apisecret` can serve — it is already 32
 * random bytes stored in the clear — and remains the fallback, so an installation
 * that never runs this migration keeps working.
 *
 * **Why a separate column rather than reusing `apisecret`.** The exposure profiles
 * differ. `apisecret` is read inside an OAuth2 token exchange: one request, one
 * read, then the process ends. A WebSocket daemon is a long-running process that
 * must hold every connected app's secret in memory for the life of the connection,
 * so a core dump or a crash log from it would leak OAuth2 client credentials as
 * well as broadcasting ones. Sharing one secret couples the two blast radii
 * permanently; a dedicated column lets an operator rotate the realtime key without
 * invalidating OAuth2 clients, and vice versa.
 *
 * Additive & non-breaking:
 *   - Nullable with no default → existing rows are unaffected and the column is
 *     added without rewriting them.
 *   - Idempotent via hasColumn(), so it is safe on installations whose
 *     applications table already exists.
 *
 * Uses the current-date timestamp prefix (framework rule §9) so it auto-runs on
 * existing installations whose migration_cutoff skips the 2020_01_01 baseline.
 */
class AddBroadcastSecretToApplications extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 60;
    public array  $dependencies = ['create_applications_table'];
    public $description  = 'Adds nullable broadcast_secret to applications for realtime channel signing';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('applications', 'broadcast_secret')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->string('broadcast_secret', 255)->nullable()
                ->comment('HMAC key for realtime channel authorization; NULL falls back to apisecret');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('applications', 'broadcast_secret')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->dropColumn('broadcast_secret');
        });
    }
}
