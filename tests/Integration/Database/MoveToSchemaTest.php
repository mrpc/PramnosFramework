<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Moving an existing table into a schema, with its rows.
 *
 * The operation a migration needs when a table turns out to be in the wrong place after it has
 * been deployed. `renameTable()` cannot do it: PostgreSQL's `ALTER TABLE … RENAME TO` takes a
 * bare name, and handing it a qualified one is a syntax error rather than a move.
 *
 * What has to hold is that it is **safe to run again** — a migration runs on installations that
 * already moved, on ones that never had the table, and on ones where somebody moved it by hand.
 */
#[CoversClass(SchemaBuilder::class)]
class MoveToSchemaTest extends BaseTestCase
{
    private $db;

    private string $table = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = \Pramnos\Framework\Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->table = 'movetest_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ([$this->table, 'pramnos.' . $this->table] as $name) {
            try {
                $this->db->schema()->dropTableIfExists($name);
            } catch (\Throwable) {
                // Already gone, which is the state this wanted.
            }
        }

        parent::tearDown();
    }

    /**
     * The table moves, and the rows move with it.
     *
     * `SET SCHEMA` is a catalogue update rather than a copy, which is what makes this safe to
     * put in a migration that runs against a live installation — but "no rows were copied" and
     * "no rows survived" would look the same to a migration that only checked the table exists.
     */
    public function testTheTableAndItsRowsMove(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->ensureSchema('pramnos');
        $schema->createTable($this->table, function ($table) {
            $table->increments('id');
            $table->string('note', 40);
        });
        $this->db->queryBuilder()->table($this->table)->insert(['note' => 'survives the move']);

        // Act
        $moved = $schema->moveToSchema($this->table, 'pramnos');

        // Assert
        $this->assertTrue($moved);
        $this->assertTrue($schema->hasTable('pramnos.' . $this->table));
        $this->assertFalse($schema->hasTable($this->table), 'and it is no longer where it was');

        $row = $this->db->queryBuilder()->table('pramnos.' . $this->table)->limit(1)->get();

        $this->assertSame('survives the move', $row->fields['note']);
    }

    /**
     * Running it twice is not an error.
     *
     * The state every installation that has already migrated is in. A move that raised there
     * would fail the whole migration run for a step that had nothing left to do.
     */
    public function testMovingAgainIsANoOp(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->ensureSchema('pramnos');
        $schema->createTable($this->table, function ($table) {
            $table->increments('id');
        });
        $schema->moveToSchema($this->table, 'pramnos');

        // Act
        $again = $schema->moveToSchema($this->table, 'pramnos');

        // Assert
        $this->assertFalse($again, 'nothing to do, and it says so rather than raising');
        $this->assertTrue($schema->hasTable('pramnos.' . $this->table), 'and it is still there');
    }

    /**
     * A table that does not exist is not an error either.
     *
     * An installation that never had the table — a feature nobody enabled — runs the same
     * migration.
     */
    public function testMovingATableThatIsNotThereIsANoOp(): void
    {
        // Act & Assert
        $this->assertFalse(
            $this->db->schema()->moveToSchema('no_such_table_anywhere', 'pramnos')
        );
    }

    /**
     * And it refuses rather than clobbering something already at the destination.
     *
     * Two tables of the same name, one in each schema — a half-finished manual move. Overwriting
     * the destination would destroy the rows somebody had already moved, which is the one
     * outcome worse than not moving.
     */
    public function testItRefusesWhenTheDestinationIsTaken(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->ensureSchema('pramnos');

        foreach ([$this->table, 'pramnos.' . $this->table] as $name) {
            $schema->createTable($name, function ($table) {
                $table->increments('id');
            });
        }

        $this->db->queryBuilder()->table('pramnos.' . $this->table)->insert([]);

        // Act
        $moved = $schema->moveToSchema($this->table, 'pramnos');

        // Assert
        $this->assertFalse($moved);
        $this->assertTrue($schema->hasTable($this->table), 'the source is untouched');
        $this->assertSame(
            1,
            (int) $this->db->queryBuilder()->table('pramnos.' . $this->table)->count(),
            'and so is the row at the destination'
        );
    }
}
