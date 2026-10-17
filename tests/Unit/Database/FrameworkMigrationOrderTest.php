<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * The framework's own migrations, in the order a fresh installation runs them.
 *
 * A migration that adds to tables other migrations create has to run after them. Run before,
 * it finds no table, does nothing and is recorded as run; the installation then lacks what it
 * adds, while one migrated table by table has it — the final shape depends on the order. This
 * held `add_missing_indexes_to_existing_tables` before the `auth` tables for months.
 */
final class FrameworkMigrationOrderTest extends TestCase
{
    /**
     * Each migration that adds to existing tables runs after every migration that creates one
     * of them, in the order the runner really produces for the whole framework set.
     */
    public function testWhatAddsToATableRunsAfterWhatCreatesIt(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::currentInstance() ?? \Pramnos\Application\Application::getInstance();
        $dirs = MigrationLoader::resolveDefaultDirectories(ROOT);
        $all  = MigrationLoader::loadFromDirectories(array_values(array_filter($dirs, static fn (string $d): bool => str_contains($d, '/framework/'))), $app);

        // Act
        $order = array_map(static fn ($m): string => $m->getSlug(), (new MigrationRunner())->sort($all));
        $at    = array_flip($order);

        // Assert
        $this->assertNotEmpty($order);
        foreach (['add_missing_indexes_to_existing_tables', 'add_missing_indexes_after_their_tables', 'add_missing_foreign_keys_to_existing_tables'] as $adds) {
            foreach (['create_usertokens_table', 'create_tokenactions_table', 'create_sessions_table', 'create_users_table'] as $creates) {
                $this->assertArrayHasKey($adds, $at);
                $this->assertArrayHasKey($creates, $at);
                $this->assertGreaterThan($at[$creates], $at[$adds], $adds . ' must run after ' . $creates);
            }
        }
    }
}
