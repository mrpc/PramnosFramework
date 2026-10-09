<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * When a device last polled for its token, so one polling faster than the interval is told to
 * slow down (RFC 8628 §3.5).
 */
class AddLastPolledAtToOauth2DeviceCodes extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 56;
    public array  $dependencies = ['create_oauth2_device_codes_table'];
    public $description = 'Adds last_polled_at (Unix time, nullable) to authserver.oauth2_device_codes';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('authserver.oauth2_device_codes')
            || $schema->hasColumn('authserver.oauth2_device_codes', 'last_polled_at')
        ) {
            return;
        }

        $schema->alterTable('authserver.oauth2_device_codes', function ($table) {
            $table->integer('last_polled_at')->nullable()
                ->comment('Unix timestamp of the device\'s last token request; NULL before the first');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('authserver.oauth2_device_codes', 'last_polled_at')) {
            $schema->alterTable('authserver.oauth2_device_codes', function ($table) {
                $table->dropColumn('last_polled_at');
            });
        }
    }
}
