<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the user_privacy_settings table — per-user GDPR privacy preference flags.
 *
 * One row per user (userid is the primary key). Settings are upserted:
 * the row is created on first setPrivacySettings() call and updated on subsequent ones.
 *
 * @package PramnosFramework
 */
class CreateUserPrivacySettingsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 105;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.user_privacy_settings GDPR preference table';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.user_privacy_settings')) {
            return;
        }

        $schema->createTable('authserver.user_privacy_settings', function ($table) {
            $table->comment('Per-user GDPR privacy preferences — one row per user; created on first privacy settings update');

            $table->bigInteger('userid')
                ->comment('User ID — primary key matching users.userid');
            $table->tinyInteger('share_usage_analytics')->default(0)
                ->comment('1 = user consents to anonymous usage analytics collection');
            $table->tinyInteger('marketing_emails')->default(0)
                ->comment('1 = user consents to receiving marketing email communications');
            $table->tinyInteger('data_processing')->default(0)
                ->comment('1 = user consents to processing of personal data beyond service provision');
            $table->timestampTz('updated_at')
                ->comment('Timestamp of the most recent settings update');

            $table->primary(['userid']);
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_privacy_settings');
    }
}
