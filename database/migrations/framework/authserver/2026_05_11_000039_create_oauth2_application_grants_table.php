<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\Migration;

/**
 * Creates the applications.oauth2_application_grants table, two read-only
 * views, and the authserver.cleanup_expired_oauth2_tokens() helper function.
 *
 * oauth2_application_grants
 *   Stores which OAuth2 grant types each application is allowed to use.
 *   One row per (appid, grant_type) pair with an is_enabled flag so individual
 *   grants can be suspended without removing them permanently.
 *
 * oauth2_application_permissions (VIEW)
 *   Aggregates an application's full OAuth2 profile (allowed scopes, redirect
 *   URI, grant types) into a single row for quick authorisation decisions.
 *   Uses array_agg on PostgreSQL, GROUP_CONCAT on MySQL.
 *
 * oauth2_active_tokens (VIEW)
 *   Joins active OAuth2 tokens with their issuing application and the owning
 *   user — useful for dashboards, audits, and revocation lookups.
 *
 * cleanup_expired_oauth2_tokens() (PostgreSQL only)
 *   Deletes access_token / refresh_token / auth_code rows that have been
 *   expired for more than 7 days. Designed to be called from a cron job or
 *   the Policy Engine daemon.
 *
 * Built with the schema builder, so every name carries the installation's prefix, and on
 * MySQL the `applications` schema becomes part of it: `{prefix}applications_oauth2_…`.
 *
 */
class CreateOauth2ApplicationGrantsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 27;
    public array  $dependencies = [
        'create_applications_schema',
        'create_applications_table',
        'create_usertokens_table',
    ];
    public $description = 'Creates applications.oauth2_application_grants, oauth2_application_permissions/active_tokens views, and cleanup function';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('applications.oauth2_application_grants')) {
            $schema->createTable('applications.oauth2_application_grants', function ($table) {
                $table->increments('grant_id');
                $table->integer('appid')->unsigned();
                $table->string('grant_type', 50);
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->unique(['appid', 'grant_type'], 'uq_oauth2_grants_app_type');
                $table->index('appid', 'idx_oauth2_grants_appid');
                $table->foreign('appid')->references('appid')->on('applications')->onDelete('cascade');
                $table->comment('OAuth2 grant types enabled per application — drives authorisation server policy');
            });

            // Raw: a named, table-level CHECK, which the builder does not model. Named, and not on the
            // column, so a later migration can drop it everywhere — MariaDB keeps a column's CHECK
            // as part of the column, beyond DROP CONSTRAINT.
            $quote = $schema->getCapabilities()->isPostgreSQL() ? '"' : '`';
            $this->application->database->query(
                'ALTER TABLE ' . $schema->quoteTable('applications.oauth2_application_grants')
                . ' ADD CONSTRAINT ' . $quote . 'chk_oauth2_grant_type' . $quote
                . " CHECK (grant_type IN ('authorization_code', 'client_credentials', 'refresh_token', 'device_code', 'password', 'exchange_token'))"
            );

            // A partial index: only PostgreSQL has them, and MySQL never had this one.
            $schema->whenCapable(DatabaseCapabilities::ENGINE_POSTGRESQL, function ($schema) {
                $schema->alterTable('applications.oauth2_application_grants', function ($table) {
                    $table->index(['appid', 'grant_type'], 'idx_oauth2_grants_enabled')->where('is_enabled = TRUE');
                });
            });
        }

        $this->createViews($schema);
    }

    /**
     * The two views, per dialect: array_agg … FILTER on PostgreSQL, GROUP_CONCAT on MySQL.
     *
     * Raw by necessity — the builder takes a view's body as SQL — but every table in it is
     * named through the schema builder, so the prefix and MySQL's schema-as-prefix apply.
     */
    private function createViews($schema): void
    {
        $pg          = $schema->getCapabilities()->isPostgreSQL();
        $apps        = $schema->quoteTable('applications');
        $grants      = $schema->quoteTable('applications.oauth2_application_grants');
        $tokens      = $schema->quoteTable('usertokens');
        $users       = $schema->quoteTable('users');
        $allowed     = $pg
            ? 'array_agg(ag.grant_type ORDER BY ag.grant_type) FILTER (WHERE ag.is_enabled = TRUE)'
            : "GROUP_CONCAT(CASE WHEN ag.is_enabled = 1 THEN ag.grant_type END ORDER BY ag.grant_type SEPARATOR ',')";
        $now         = $pg ? 'EXTRACT(EPOCH FROM NOW())' : 'UNIX_TIMESTAMP()';

        $schema->createOrReplaceView('applications.oauth2_application_permissions', "SELECT
                a.appid,
                a.name         AS client_name,
                a.apikey       AS client_id,
                a.scope        AS allowed_scopes,
                a.callback     AS redirect_uri,
                a.accesstype,
                {$allowed} AS allowed_grants
            FROM {$apps} a
            LEFT JOIN {$grants} ag
                   ON a.appid = ag.appid AND ag.is_enabled = " . ($pg ? 'TRUE' : '1') . "
            WHERE a.accesstype = 1
            GROUP BY a.appid, a.name, a.apikey, a.scope, a.callback, a.accesstype");

        $schema->createOrReplaceView('applications.oauth2_active_tokens', "SELECT
                ut.tokenid,
                ut.userid,
                ut.tokentype,
                ut.token,
                ut.created,
                ut.expires,
                ut.lastused,
                ut.scope,
                ut.ipaddress,
                a.name   AS client_name,
                a.apikey AS client_id,
                u.username,
                u.email
            FROM {$tokens} ut
            JOIN {$apps} a ON ut.applicationid = a.appid
            LEFT JOIN {$users} u ON ut.userid = u.userid
            WHERE ut.status = 1
              AND ut.expires > {$now}
              AND ut.tokentype IN ('access_token', 'refresh_token', 'auth_code')");

        if (!$pg) {
            return;
        }

        $db = $this->application->database;
        // COMMENT ON VIEW and a PL/pgSQL function: PostgreSQL's own, which the builder does not model.
        $db->query('COMMENT ON VIEW ' . $schema->quoteTable('applications.oauth2_application_permissions')
            . " IS 'Full OAuth2 profile for each application: scopes, redirect URI, allowed grant types'");
        $db->query('COMMENT ON VIEW ' . $schema->quoteTable('applications.oauth2_active_tokens')
            . " IS 'Active OAuth2 tokens with issuing application and owner user information'");

        $db->query("CREATE OR REPLACE FUNCTION authserver.cleanup_expired_oauth2_tokens()
RETURNS INTEGER AS \$\$
DECLARE
    deleted_count INTEGER;
BEGIN
    -- Remove tokens expired for more than 7 days to keep the table lean.
    -- Tokens within the 7-day window are kept so revocation webhooks / audits
    -- can still reference them after they expire.
    DELETE FROM {$tokens}
    WHERE tokentype IN ('access_token', 'refresh_token', 'auth_code')
      AND expires < EXTRACT(EPOCH FROM NOW()) - (7 * 24 * 3600);

    GET DIAGNOSTICS deleted_count = ROW_COUNT;

    RETURN deleted_count;
END;
\$\$ LANGUAGE plpgsql");

        $db->query("COMMENT ON FUNCTION authserver.cleanup_expired_oauth2_tokens() IS
            'Deletes OAuth2 tokens (access/refresh/auth_code) expired for more than 7 days'");
    }

    public function down(): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();

        // DROP VIEW and DROP FUNCTION: no builder method; the names go through it all the same.
        $db->query('DROP VIEW IF EXISTS ' . $schema->quoteTable('applications.oauth2_active_tokens'));
        $db->query('DROP VIEW IF EXISTS ' . $schema->quoteTable('applications.oauth2_application_permissions'));
        if ($schema->getCapabilities()->isPostgreSQL()) {
            $db->query('DROP FUNCTION IF EXISTS authserver.cleanup_expired_oauth2_tokens()');
        }
        $schema->dropTableIfExists('applications.oauth2_application_grants');
    }
}
