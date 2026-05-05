<?php

namespace Pramnos\Database;

/**
 * Base class for database seeders.
 *
 * A seeder populates a table with deterministic fake data for development and
 * testing. Subclasses implement run() and call $this->insert() to write rows.
 *
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
abstract class Seeder
{
    abstract public function run(): void;

    /**
     * Insert a single row into a table via the active Database connection.
     *
     * Uses Database::insertDataToTable() so the prefix, escaping, and connection
     * pooling are all handled by the framework layer.
     *
     * @param string               $table Table name (may include #PREFIX# placeholder)
     * @param array<string, mixed> $data  Column → value map
     */
    protected function insert(string $table, array $data): void
    {
        Database::getInstance()->insertDataToTable($table, $data);
    }
}
