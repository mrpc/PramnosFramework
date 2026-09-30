<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * Adds pushsubscriptions.trusted_device_id — which trusted device a browser's subscription is.
 *
 * A sign-in approval goes only to browsers the person has trusted, never to every browser
 * that ever granted permission. The subscription learns its device when the browser
 * subscribes while carrying its trust cookie. No foreign key: the device table lives in
 * the auth server's schema, which an application sending notifications need not have.
 */
class AddTrustedDeviceToPushSubscriptions extends Migration
{
    public string $feature      = 'notifications';
    public string $scope        = 'framework';
    public int    $priority     = 60;
    public array  $dependencies = ['create_pushsubscriptions_table'];
    public $description = 'Adds pramnos.pushsubscriptions.trusted_device_id';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('pramnos.pushsubscriptions')
            || $schema->hasColumn('pramnos.pushsubscriptions', 'trusted_device_id')
        ) {
            return;
        }

        $schema->alterTable('pramnos.pushsubscriptions', function ($table) {
            $table->bigInteger('trusted_device_id')->nullable()
                ->comment('authserver.trusted_devices.device_id this browser is; NULL = not trusted');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('pramnos.pushsubscriptions', 'trusted_device_id')) {
            $schema->alterTable('pramnos.pushsubscriptions', function ($table) {
                $table->dropColumn('trusted_device_id');
            });
        }
    }
}
