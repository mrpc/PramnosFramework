<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Per-client token lifetimes: how long an access token and a refresh token last for one application.
 *
 * Seconds, nullable: an empty value is the server's default (`oauth.access_token_ttl` and
 * `oauth.refresh_token_ttl` in `app.php`, one hour and thirty days unless set). A machine client
 * calling every minute and a mobile app a person opens once a week want different answers, and
 * one server-wide number made the choice for both.
 */
class AddTokenLifetimesToApplications extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 27;
    public array  $dependencies = ['create_applications_table'];
    public $description = 'Adds access_token_ttl and refresh_token_ttl (seconds, nullable) to applications';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('applications')) {
            return;
        }

        foreach (['access_token_ttl' => 'Access token lifetime in seconds; NULL = the server default',
                  'refresh_token_ttl' => 'Refresh token lifetime in seconds; NULL = the server default'] as $column => $comment) {
            if (!$schema->hasColumn('applications', $column)) {
                $schema->alterTable('applications', function ($table) use ($column, $comment) {
                    $table->integer($column)->nullable()->comment($comment);
                });
            }
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('applications')) {
            return;
        }

        foreach (['access_token_ttl', 'refresh_token_ttl'] as $column) {
            if ($schema->hasColumn('applications', $column)) {
                $schema->alterTable('applications', function ($table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
