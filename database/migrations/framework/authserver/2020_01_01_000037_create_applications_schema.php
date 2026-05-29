<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the applications PostgreSQL schema namespace.
 *
 * On PostgreSQL, application-level API infrastructure (webhook endpoints,
 * per-application settings, usage statistics) lives in the dedicated
 * `applications` schema, separate from the core RBAC/OAuth tables in
 * `authserver` and the user-facing tables in `public`.
 *
 * On MySQL, schemas are databases and this migration is a no-op (tables
 * are created in the default database with a configurable prefix).
 *
 * The `applications` TABLE (public.applications — the OAuth2 client registry)
 * is a separate concept from this schema and is created by create_applications_table.
 *
 */
class CreateApplicationsSchema extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 11;
    public $description  = 'Creates the applications schema namespace (PostgreSQL only)';

    public function up(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                'CREATE SCHEMA IF NOT EXISTS applications'
            );
        }
    }

    public function down(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                'DROP SCHEMA IF EXISTS applications CASCADE'
            );
        }
    }
}
