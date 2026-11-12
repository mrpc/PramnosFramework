<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * Adds `snoozed_until` to `notifications`: a notification put off until a moment ("tell me again
 * later") is out of the bell and its count until then, and comes back unread. State, as `read_at`
 * is: when it comes back, not a history of putting off.
 */
class AddSnoozedUntilToNotifications extends Migration
{
    public string $feature     = 'notifications';
    public string $scope       = 'framework';
    public int    $priority    = 11;
    public $description = 'Adds snoozed_until to notifications';

    /** Add it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('notifications') || $schema->hasColumn('notifications', 'snoozed_until')) {
            return;
        }
        $schema->table('notifications', fn ($table) => $table->integer('snoozed_until')->nullable()->comment('Out of the bell until then (unix)'));
    }

    /** Drop it. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('notifications', 'snoozed_until')) {
            $schema->table('notifications', fn ($table) => $table->dropColumn(['snoozed_until']));
        }
    }
}
