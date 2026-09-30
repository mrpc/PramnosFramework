<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds authserver.trusted_devices.trusted_via — which second factor trusted the browser.
 *
 * A browser can be trusted after any second factor, the mailed code included. Whether a phone
 * that answers sign-in prompts counts as the account's real second factor
 * (`auth_push_counts_for_enrolment`, "strong") depends on which factor it was trusted with,
 * so the row keeps it. NULL for rows from before the column: treated as not strong.
 */
class AddTrustedViaToAuthserverTrustedDevices extends Migration
{
    public string $feature      = 'auth';
    public string $scope        = 'framework';
    public int    $priority     = 41;
    public array  $dependencies = ['create_authserver_trusted_devices_table'];
    public $description = 'Adds authserver.trusted_devices.trusted_via';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('authserver.trusted_devices')
            || $schema->hasColumn('authserver.trusted_devices', 'trusted_via')
        ) {
            return;
        }

        $schema->alterTable('authserver.trusted_devices', function ($table) {
            $table->string('trusted_via', 32)->nullable()
                ->comment('The second factor that trusted it (twofactor, passkey, email, …); NULL = unknown');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('authserver.trusted_devices', 'trusted_via')) {
            $schema->alterTable('authserver.trusted_devices', function ($table) {
                $table->dropColumn('trusted_via');
            });
        }
    }
}
