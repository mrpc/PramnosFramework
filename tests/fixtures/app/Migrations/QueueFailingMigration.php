<?php

namespace Pramnos\Migrations;

/**
 * A migration that queues one statement the database refuses, then returns.
 *
 * The shape that used to be recorded as a plain success: up() completes, so
 * nothing threw, while nothing it asked for actually happened.
 */
class QueueFailingMigration extends \Pramnos\Database\Migration
{
    public $autoExecute = true;
    public $version = '9.9.8';

    public function up(): void
    {
        $this->addQuery('ALTER TABLE users ADD COLUMN doomed VARCHAR(1)');
        $this->executeQueries();
    }
}
