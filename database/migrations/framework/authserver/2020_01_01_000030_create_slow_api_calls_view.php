<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver.slow_api_calls view — performance monitoring for slow API calls.
 *
 * Shows all API requests that took longer than 5 seconds (> 5000 ms) in the last
 * 7 days, joined with the token and application that made the request.
 *
 * On PostgreSQL / TimescaleDB the view lives in the `authserver` schema.
 * On MySQL it is named `authserver_slow_api_calls` (schema-as-prefix convention).
 *
 * Depends on both the auth feature (tokenactions, usertokens) and the authserver
 * feature (applications), so this migration must run after both are in place.
 *
 */
class CreateSlowApiCallsView extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 80;
    public array  $dependencies = ['create_tokenactions_table', 'create_applications_table'];
    public $description  = 'Creates authserver.slow_api_calls performance monitoring view';

    public function up(): void
    {
        $db     = $this->application->database;
        $caps   = $db->schema()->getCapabilities();
        $schema = $db->schema();

        $viewRef = $schema->quoteTable('authserver.slow_api_calls');

        if ($caps->isPostgreSQL()) {
            $db->query(
                "CREATE OR REPLACE VIEW {$viewRef} AS
                SELECT a.name AS app_name, ut.token, ta.method, ta.urlid,
                       ta.execution_time_ms, ta.return_status, ta.action_time, ta.params
                  FROM public.tokenactions ta
                  JOIN public.usertokens ut ON ut.tokenid = ta.tokenid
                  JOIN public.applications a ON a.appid = ut.applicationid
                 WHERE ta.execution_time_ms > 5000
                   AND ta.action_time >= CURRENT_TIMESTAMP - INTERVAL '7 days'
                 ORDER BY ta.execution_time_ms DESC"
            );
        } else {
            // MySQL: all tables are in the same database; ORDER BY is valid in views
            $db->query(
                "CREATE OR REPLACE VIEW {$viewRef} AS
                SELECT a.name AS app_name, ut.token, ta.method, ta.urlid,
                       ta.execution_time_ms, ta.return_status, ta.action_time, ta.params
                  FROM `tokenactions` ta
                  JOIN `usertokens` ut ON ut.tokenid = ta.tokenid
                  JOIN `applications` a ON a.appid = ut.applicationid
                 WHERE ta.execution_time_ms > 5000
                   AND ta.action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY ta.execution_time_ms DESC"
            );
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropView('authserver.slow_api_calls');
    }
}
