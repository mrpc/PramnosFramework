<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Per-client choice of how `/oauth/authorize` hands a custom-scheme callback back.
 *
 * 0, the default, is a plain 302 to the callback, which an in-app browser (Android Custom Tabs,
 * iOS ASWebAuthenticationSession) intercepts as the user's own navigation. 1 is a page that
 * opens the callback by script and offers it as a button, for a desktop application that opens
 * the system browser, where a 302 to a scheme nothing has registered ends on a blank tab.
 * Ignored for `http(s)` callbacks, which are always redirected.
 *
 * State, not history: what this client is configured to receive.
 */
class AddAppReturnPageToApplications extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 27;
    public array  $dependencies = ['create_applications_table'];
    public $description = 'Adds app_return_page (0 = 302, 1 = a page) to applications';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('applications') || $schema->hasColumn('applications', 'app_return_page')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->smallInteger('app_return_page')->default(0)
                ->comment('Custom-scheme callback: 0 = a 302, 1 = a page that opens the app');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('applications') && $schema->hasColumn('applications', 'app_return_page')) {
            $schema->alterTable('applications', function ($table) {
                $table->dropColumn('app_return_page');
            });
        }
    }
}
