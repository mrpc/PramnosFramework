<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver PostgreSQL schema namespace.
 *
 * On PostgreSQL, RBAC tables, audit logs, and OAuth server infrastructure live
 * in the dedicated `authserver` schema to separate them from the application's
 * default public schema. On MySQL, schemas are databases and this migration
 * is a no-op (tables are created in the default database with the authserver_
 * prefix instead).
 *
 * Lives under the `auth` feature, not `authserver`, because the RBAC tables it
 * holds are needed by any application that has users — authorisation is not an
 * OAuth concern. The schema keeps its name: renaming it would break every
 * existing installation for no gain.
 *
 */
class CreateAuthserverSchema extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public $description  = 'Creates the authserver schema namespace (PostgreSQL only)';

    public function up(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                'CREATE SCHEMA IF NOT EXISTS authserver'
            );
        }
    }

    public function down(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                'DROP SCHEMA IF EXISTS authserver CASCADE'
            );
        }
    }
}
