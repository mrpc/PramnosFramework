<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth2_device_codes table — RFC 8628 Device Authorization Grant.
 *
 * Stores pending and completed device authorization requests. The device_code
 * is a 64-character hex string (bin2hex(random_bytes(32))); the user_code is
 * a 9-character XXXX-XXXX string from the unambiguous alphabet BCDFGHJKLMNPQRSTVWXZ.
 *
 * The expires_at column stores a unix timestamp (INT) for direct comparison with
 * time() in PHP without time-zone conversion issues.
 *
 * @package PramnosFramework
 */
class CreateOauth2DeviceCodesTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 55;
    public array  $dependencies = ['create_authserver_schema', 'create_applications_table', 'create_users_table'];
    public $description  = 'Creates the oauth2_device_codes table (RFC 8628 Device Authorization Grant)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.oauth2_device_codes')) {
            return;
        }

        $schema->createTable('authserver.oauth2_device_codes', function ($table) {
            $table->comment('RFC 8628 device authorization requests — pending, authorized, and denied');

            $table->increments('id')
                ->comment('Auto-increment primary key');
            $table->string('device_code', 64)
                ->comment('64-character lowercase hex device code (bin2hex(random_bytes(32)))');
            $table->string('user_code', 9)
                ->comment('9-character user-facing code in XXXX-XXXX format (unambiguous alphabet)');
            $table->string('client_id', 255)
                ->comment('OAuth2 client_id (applications.apikey) that initiated the device flow');
            $table->text('scope')->nullable()
                ->comment('Space-separated list of requested OAuth2 scopes');
            $table->integer('expires_at')
                ->comment('Unix timestamp when the device code expires (typically now + 600)');
            $table->string('status', 20)->default('pending')
                ->comment('Current status: pending | authorized | denied');
            $table->bigInteger('user_id')->nullable()
                ->comment('userid of the user who authorized the device; NULL until authorization');
            $table->integer('authorized_at')->nullable()
                ->comment('Unix timestamp when the user approved or denied the request');

            $table->unique(['device_code'], 'uq_oauth2_dc_device_code');
            $table->unique(['user_code'], 'uq_oauth2_dc_user_code');
            $table->index(['expires_at', 'status'], 'idx_oauth2_dc_expires_status');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.oauth2_device_codes');
    }
}
