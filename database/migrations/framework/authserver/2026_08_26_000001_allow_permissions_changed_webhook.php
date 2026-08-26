<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Let an application subscribe to `permissions_changed`.
 *
 * The instant-invalidation design the integration guide is built around rests on
 * one webhook: when an administrator changes a user's permissions, the server
 * tells the relying party so it can drop its cache. `PermissionsController` has
 * always queued that event.
 *
 * `oauth2_webhook_endpoints.webhook_type` never allowed it. Its CHECK constraint
 * lists seven types and `permissions_changed` is not one of them, so no
 * application could register an endpoint for it — and `queueEvent()` looks up
 * endpoints by type before inserting anything, so the event was quietly dropped
 * with no endpoint to send it to. The documented mechanism could not be switched
 * on.
 *
 * The constraint is replaced rather than dropped: it is what keeps a typo from
 * becoming an endpoint that never fires.
 */
class AllowPermissionsChangedWebhook extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 26;
    public array  $dependencies = ['create_oauth2_webhooks_tables'];
    public $description = 'Adds permissions_changed to the webhook endpoint type constraint';

    /** The full set of event types an endpoint may subscribe to. */
    private const TYPES = [
        'user_deauthorized',
        'token_revoked',
        'gdpr_request',
        'user_profile_changed',
        'device_deauthorized',
        'account_deleted',
        'scope_changed',
        'permissions_changed',
    ];

    public function up(): void
    {
        $db     = $this->application->database;
        $caps   = $db->schema()->getCapabilities();
        $schema = $db->schema();
        $table  = $schema->quoteTable('applications.oauth2_webhook_endpoints');

        if (!$schema->hasTable('applications.oauth2_webhook_endpoints')) {
            return;
        }

        $list = "'" . implode("', '", self::TYPES) . "'";

        if ($caps->isPostgreSQL()) {
            // The constraint name is the one PostgreSQL generates for an inline
            // column CHECK: <table>_<column>_check.
            $db->query(
                "ALTER TABLE {$table}
                 DROP CONSTRAINT IF EXISTS oauth2_webhook_endpoints_webhook_type_check"
            );
            $db->query(
                "ALTER TABLE {$table}
                 ADD CONSTRAINT oauth2_webhook_endpoints_webhook_type_check
                 CHECK (webhook_type IN ({$list}))"
            );

            return;
        }

        // MySQL 8 supports CHECK constraints, and names an inline one
        // <table>_chk_<n>. Dropping by a guessed name is unreliable, so the
        // column is redefined instead — which replaces whatever check was on it.
        $db->query(
            "ALTER TABLE {$table}
             MODIFY COLUMN `webhook_type` VARCHAR(50) NOT NULL
             CHECK (`webhook_type` IN ({$list}))"
        );
    }

    public function down(): void
    {
        $db     = $this->application->database;
        $caps   = $db->schema()->getCapabilities();
        $schema = $db->schema();
        $table  = $schema->quoteTable('applications.oauth2_webhook_endpoints');

        if (!$schema->hasTable('applications.oauth2_webhook_endpoints')) {
            return;
        }

        // Back to the original seven. Any endpoint registered for the eighth would
        // block this, which is the right outcome: rolling back a constraint should
        // not silently orphan a subscription.
        $original = self::TYPES;
        array_pop($original);
        $list = "'" . implode("', '", $original) . "'";

        if ($caps->isPostgreSQL()) {
            $db->query(
                "ALTER TABLE {$table}
                 DROP CONSTRAINT IF EXISTS oauth2_webhook_endpoints_webhook_type_check"
            );
            $db->query(
                "ALTER TABLE {$table}
                 ADD CONSTRAINT oauth2_webhook_endpoints_webhook_type_check
                 CHECK (webhook_type IN ({$list}))"
            );

            return;
        }

        $db->query(
            "ALTER TABLE {$table}
             MODIFY COLUMN `webhook_type` VARCHAR(50) NOT NULL
             CHECK (`webhook_type` IN ({$list}))"
        );
    }
}
