<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Lets the grant policy name `jwt_bearer`.
 *
 * The jwt-bearer grant is opt-in per application, so an application uses it only with a row in
 * `oauth2_application_grants` — and the table's CHECK listed every grant type but that one.
 */
class AllowJwtBearerInOauth2ApplicationGrants extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 28;
    public array  $dependencies = ['create_oauth2_application_grants_table'];
    public $description = 'Adds jwt_bearer to the grant_type CHECK of applications.oauth2_application_grants';

    private const TABLE = 'applications.oauth2_application_grants';

    private const BEFORE = ['authorization_code', 'client_credentials', 'refresh_token', 'device_code', 'password', 'exchange_token'];

    public function up(): void
    {
        $this->replaceCheck([...self::BEFORE, 'jwt_bearer']);
    }

    public function down(): void
    {
        $this->replaceCheck(self::BEFORE);
    }

    /**
     * Drop the grant_type CHECK, whatever its name, and add one allowing these types.
     *
     * @param list<string> $types
     */
    private function replaceCheck(array $types): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->quoteTable(self::TABLE);
        foreach ($this->grantTypeChecks() as $name) {
            $db->query("ALTER TABLE {$table} DROP " . ($this->isPostgreSQL() ? 'CONSTRAINT ' : 'CHECK ') . $this->quoteName($name));
        }
        $db->query(
            "ALTER TABLE {$table} ADD CONSTRAINT " . $this->quoteName('chk_oauth2_grant_type')
            . " CHECK (grant_type IN ('" . implode("', '", $types) . "'))"
        );
    }

    private function isPostgreSQL(): bool
    {
        return $this->application->database->schema()->getCapabilities()->isPostgreSQL();
    }

    private function quoteName(string $name): string
    {
        return $this->isPostgreSQL()
            ? '"' . str_replace('"', '""', $name) . '"'
            : '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * The names of the CHECK constraints on grant_type: generated on PostgreSQL, named on MySQL.
     *
     * @return list<string>
     */
    private function grantTypeChecks(): array
    {
        $db       = $this->application->database;
        $resolved = $db->schema()->resolveTableName(self::TABLE);

        // Introspection: the constraint catalogue is not something the query builder reaches.
        if ($this->isPostgreSQL()) {
            [$schemaName, $tableName] = str_contains($resolved, '.') ? explode('.', $resolved, 2) : ['public', $resolved];
            $sql = $db->prepareQuery(
                "SELECT c.conname AS name
                   FROM pg_constraint c
                   JOIN pg_class t ON t.oid = c.conrelid
                   JOIN pg_namespace n ON n.oid = t.relnamespace
                  WHERE c.contype = 'c' AND n.nspname = %s AND t.relname = %s
                    AND pg_get_constraintdef(c.oid) LIKE %s",
                $schemaName,
                $tableName,
                '%grant_type%'
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
                '%grant_type%'
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
