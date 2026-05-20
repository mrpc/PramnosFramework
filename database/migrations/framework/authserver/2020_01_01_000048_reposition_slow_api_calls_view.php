<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Repositions slow_api_calls from the authserver schema to the applications schema.
 *
 * The original authserver.slow_api_calls view (migration 000030) sourced individual
 * token-level slow calls from tokenactions + usertokens + applications.
 * Migration 000046 now provides applications.slow_api_calls sourced from the
 * application_stats aggregate table, which is the canonical location.
 *
 * This migration drops authserver.slow_api_calls so all slow-call analysis is
 * consolidated under the applications schema.
 *
 * @package PramnosFramework
 */
class RepositionSlowApiCallsView extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 33;
    public array  $dependencies = [
        'create_slow_api_calls_view',
        'create_applications_views',
    ];
    public $description = 'Drops authserver.slow_api_calls — consolidated into applications.slow_api_calls (migration 000046)';

    public function up(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->DB()->query("DROP VIEW IF EXISTS authserver.slow_api_calls");
        } else {
            $this->DB()->query("DROP VIEW IF EXISTS `authserver_slow_api_calls`");
        }
    }

    public function down(): void
    {
        // Restore the original authserver.slow_api_calls view on rollback
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->DB()->query("
                CREATE OR REPLACE VIEW authserver.slow_api_calls AS
                SELECT a.name AS app_name, ut.token, ta.method, ta.urlid,
                       ta.execution_time_ms, ta.return_status, ta.action_time, ta.params
                  FROM public.tokenactions ta
                  JOIN public.usertokens ut ON ut.tokenid = ta.tokenid
                  JOIN public.applications a ON a.appid = ut.applicationid
                 WHERE ta.execution_time_ms > 5000
                   AND ta.action_time >= CURRENT_TIMESTAMP - INTERVAL '7 days'
                 ORDER BY ta.execution_time_ms DESC
            ");
        } else {
            $this->DB()->query("
                CREATE OR REPLACE VIEW `authserver_slow_api_calls` AS
                SELECT a.name AS app_name, ut.token, ta.method, ta.urlid,
                       ta.execution_time_ms, ta.return_status, ta.action_time, ta.params
                  FROM `tokenactions` ta
                  JOIN `usertokens` ut ON ut.tokenid = ta.tokenid
                  JOIN `applications` a ON a.appid = ut.applicationid
                 WHERE ta.execution_time_ms > 5000
                   AND ta.action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY ta.execution_time_ms DESC
            ");
        }
    }
}
