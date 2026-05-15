<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates the `pramnos` PostgreSQL schema for framework infrastructure tables.
 *
 * Tables that belong purely to the framework (not used directly by application
 * code) live here so they stay separate from the application's `public` schema.
 * Currently that means `framework_policies`; future framework-only tables
 * follow the same convention.
 *
 * MySQL has no equivalent schema concept (schemas = databases), so this
 * migration is a no-op there — MySQL tables keep their flat names.
 *
 * @package PramnosFramework
 */
class CreatePramnosSchema extends Migration
{
    public string  $feature      = 'core';
    public string  $scope        = 'framework';
    public int     $priority     = 25;
    public $description  = 'Creates the pramnos schema namespace (PostgreSQL only)';

    public function up(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                'CREATE SCHEMA IF NOT EXISTS pramnos'
            );
        }
    }

    public function down(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                'DROP SCHEMA IF EXISTS pramnos CASCADE'
            );
        }
    }
}
