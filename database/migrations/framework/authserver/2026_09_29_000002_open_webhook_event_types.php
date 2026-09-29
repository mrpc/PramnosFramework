<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Let an endpoint subscribe to an application's own event types.
 *
 * `oauth2_webhook_endpoints.webhook_type` carried a CHECK constraint listing the framework's
 * eight events, so an application could not deliver `station.live` through `WebhookService`:
 * the service refused it, and bypassing the service met the database. Adding a type meant a
 * framework migration, and the framework cannot know what a radio station is.
 *
 * The constraint is dropped. What it guarded — a typo becoming an endpoint that never fires —
 * is now checked by `WebhookService::saveEndpoint()` against `WebhookEvents::names()`, which
 * includes the application's registered types. Additive for every reader: every value the
 * constraint allowed is still allowed.
 *
 * Found by name rather than assumed, on both databases. PostgreSQL named the inline check
 * `oauth2_webhook_endpoints_webhook_type_check`; on MySQL the `MODIFY COLUMN … CHECK` of
 * `allow_permissions_changed_webhook` created one named `<table>_chk_<n>`.
 */
class OpenWebhookEventTypes extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 28;
    public array  $dependencies = ['create_oauth2_webhooks_tables', 'allow_permissions_changed_webhook'];
    public $description = 'Drops the CHECK on oauth2_webhook_endpoints.webhook_type; registered types are validated in PHP';

    private const TABLE = 'applications.oauth2_webhook_endpoints';

    /** The framework's own types, which down() restores the constraint to. */
    private const BUILT_IN = [
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
        $schema = $db->schema();

        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->quoteTable(self::TABLE);
        foreach ($this->typeChecks() as $name) {
            $db->query("ALTER TABLE {$table} DROP CONSTRAINT " . $this->quoteName($name));
        }
    }

    public function down(): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();

        if (!$schema->hasTable(self::TABLE) || $this->typeChecks() !== []) {
            return;
        }

        // Back to the framework's eight. An endpoint registered for an application's type
        // blocks this, which is the right outcome: a rollback must not orphan a subscription.
        $list  = "'" . implode("', '", self::BUILT_IN) . "'";
        $table = $schema->quoteTable(self::TABLE);
        $db->query(
            "ALTER TABLE {$table} ADD CONSTRAINT "
            . $this->quoteName('oauth2_webhook_endpoints_webhook_type_check')
            . " CHECK (webhook_type IN ({$list}))"
        );
    }

    /**
     * A constraint name quoted for this driver — double quotes on PostgreSQL, backticks on MySQL.
     *
     * @param string $name A name read from the catalogue
     * @return string
     */
    private function quoteName(string $name): string
    {
        if ($this->application->database->schema()->getCapabilities()->isPostgreSQL()) {
            return '"' . str_replace('"', '""', $name) . '"';
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * The names of the CHECK constraints on this table that mention `webhook_type`.
     *
     * Catalogue introspection, so raw SQL: the query builder does not reach
     * `pg_constraint` or `information_schema.check_constraints`.
     *
     * @return list<string>
     */
    private function typeChecks(): array
    {
        $db       = $this->application->database;
        $resolved = $db->schema()->resolveTableName(self::TABLE);

        if ($db->schema()->getCapabilities()->isPostgreSQL()) {
            [$schemaName, $tableName] = str_contains($resolved, '.')
                ? explode('.', $resolved, 2)
                : ['public', $resolved];
            $sql = $db->prepareQuery(
                "SELECT c.conname AS name
                   FROM pg_constraint c
                   JOIN pg_class t ON t.oid = c.conrelid
                   JOIN pg_namespace n ON n.oid = t.relnamespace
                  WHERE c.contype = 'c' AND n.nspname = %s AND t.relname = %s
                    AND pg_get_constraintdef(c.oid) LIKE %s",
                $schemaName,
                $tableName,
                '%webhook_type%'
            );
        } else {
            $sql = $db->prepareQuery(
                "SELECT tc.CONSTRAINT_NAME AS name
                   FROM information_schema.TABLE_CONSTRAINTS tc
                   JOIN information_schema.CHECK_CONSTRAINTS cc
                     ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                    AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                  WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s
                    AND tc.CONSTRAINT_TYPE = 'CHECK' AND cc.CHECK_CLAUSE LIKE %s",
                $resolved,
                '%webhook_type%'
            );
        }

        $names  = [];
        $result = $db->query($sql);
        while ($result && $result->fetch()) {
            $names[] = (string) ($result->fields['name'] ?? $result->fields['NAME'] ?? '');
        }

        return array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
    }
}
