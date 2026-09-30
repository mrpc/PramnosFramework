<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates authserver.trusted_devices — browsers a person told "don't ask again here".
 *
 * Ticked on the two-step page, a browser is trusted for a number of days: a sign-in from it
 * skips the second factor, and — when it also receives notifications — it is one of the
 * devices a sign-in elsewhere can be approved from. The cookie carries a random token and
 * the row only its lookup hash, so a copy of the table trusts nobody.
 *
 * Times are epoch seconds, so "still trusted" is one comparison on every driver.
 */
class CreateAuthserverTrustedDevicesTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.trusted_devices table for "trust this device"';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.trusted_devices')) {
            return;
        }

        $schema->createTable('authserver.trusted_devices', function ($table) {
            $table->comment('Browsers trusted to skip the second factor, and to approve sign-ins elsewhere');

            $table->increments('device_id')
                ->comment('Auto-increment device identifier');
            $table->bigInteger('userid')
                ->comment('users.userid of the account that trusted it');
            $table->string('token_lookup', 64)
                ->comment('Token::lookup() of the cookie token — the token itself is never stored');
            $table->string('user_agent', 255)->default('')
                ->comment('So a person can recognise the device they are revoking');
            $table->string('fingerprint', 64)->default('')
                ->comment('SignInFingerprint of the browser, for its name in the list');
            $table->string('ip', 45)->default('')
                ->comment('Address it was trusted from');
            $table->string('country', 2)->default('')
                ->comment('Country it was trusted from, when known');
            $table->bigInteger('created_at')
                ->comment('Epoch seconds when it was trusted');
            $table->bigInteger('last_used_at')->nullable()
                ->comment('Epoch seconds of the last sign-in or approval it carried');
            $table->bigInteger('expires_at')
                ->comment('Epoch seconds after which it is asked for a second factor again');
            $table->bigInteger('revoked_at')->nullable()
                ->comment('Epoch seconds when it was revoked; NULL = not revoked');

            $table->unique(['token_lookup'], 'uq_authserver_trusted_devices_token');
            $table->index(['userid'], 'idx_authserver_trusted_devices_userid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.trusted_devices');
    }
}
