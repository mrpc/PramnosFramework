<?php

namespace Pramnos\Framework\Migrations\Applications;

use Pramnos\Database\Migration;

/**
 * Gives `applications.oauth2_active_tokens` back to the per-token view, and the per-application
 * summary its own name, on PostgreSQL installations that already ran both view migrations.
 *
 * Two framework migrations defined the same name with different shapes:
 * `create_oauth2_application_grants_table` one row per live token, and
 * `create_applications_views`, which runs after it, a per-application count that replaced it.
 * MySQL never had the clash — the summary is `applications_oauth2_active_tokens_summary`
 * there. Both migrations now agree with MySQL, but an installation that recorded them keeps
 * the summary under the per-token name until this runs.
 *
 * Only the one view moves. `create_applications_views` is not re-run: it drops and rebuilds
 * the continuous aggregates, which would lose what they had materialized.
 *
 * The drop is not `CASCADE`: nothing in the framework is built on either view, and if an
 * application built something on the summary, failing loudly here is better than taking its
 * view away silently.
 */
class RenameOauth2ActiveTokensSummary extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';

    /** After `widen_applications_callback` (35), the last of the views migrations. */
    public int    $priority     = 36;

    public array  $dependencies = [
        'create_oauth2_application_grants_table',
        'create_applications_views',
    ];

    public $description = 'Moves the per-application token summary to applications.oauth2_active_tokens_summary on PostgreSQL';

    public function up(): void
    {
        $schema = $this->DB()->schema();
        if (!$schema->getCapabilities()->isPostgreSQL() || !$this->summaryHoldsTheName()) {
            // MySQL, a fresh installation, or one that already moved: nothing to do.
            return;
        }

        // DROP VIEW: no builder method takes the kind check this relies on.
        $this->DB()->query('DROP VIEW ' . $schema->quoteTable('applications.oauth2_active_tokens'));

        // The one definition of each view, in the migration that owns it.
        (new \Pramnos\Framework\Migrations\AuthServer\CreateOauth2ApplicationGrantsTable($this->application))
            ->createActiveTokensView($schema);
        (new CreateApplicationsViews($this->application))->createActiveTokensSummary();

        \Pramnos\Logs\Logger::log(
            'applications.oauth2_active_tokens is the per-token view again; the per-application '
            . 'summary is applications.oauth2_active_tokens_summary',
            'migrations'
        );
    }

    /**
     * Deliberately empty: putting the summary back under the per-token name restores the clash
     * this migration exists to end.
     */
    public function down(): void
    {
    }

    /**
     * Is `applications.oauth2_active_tokens` a plain view in the summary's shape?
     *
     * `token_count` is the summary's and not the per-token view's. A materialized view or a
     * table under the name is an application's, and left alone.
     */
    private function summaryHoldsTheName(): bool
    {
        $schema = $this->DB()->schema();

        return $schema->getRelationKind('applications.oauth2_active_tokens') === 'v'
            && $schema->hasColumn('applications.oauth2_active_tokens', 'token_count');
    }
}
