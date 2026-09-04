<?php

namespace Pramnos\Migrations;

/**
 * A migration whose up() throws, for MaintenanceFlagLifecycleTest.
 *
 * Extends the framework base class on purpose: the flag handling in
 * runMigration() has to work for both shapes, and TestMigration next to this one
 * already covers the legacy one that extends nothing.
 */
class ThrowingMigration extends \Pramnos\Database\Migration
{
    public $autoExecute = true;
    public $version = '9.9.9';

    public function up(): void
    {
        throw new \RuntimeException('this migration always throws');
    }
}
