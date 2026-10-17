<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;

/**
 * Indexes and foreign keys found by what they cover, not by what they are called.
 *
 * A table older than the framework holds the framework's index as `tokenid` and its foreign key
 * as `…_ibfk_1`. A migration that asked only for the framework's names found neither and added a
 * second of each — duplicate work on every write, and a schema that never matched a fresh one.
 */
#[CoversClass(SchemaBuilder::class)]
#[CoversClass(\Pramnos\Database\Migrations\AddMissingIndexesToExistingTables::class)]
final class SchemaIntrospectionByColumnsTest extends TestCase
{
    private ?Database $db = null;

    private string $parent = '';

    private string $child = '';

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->schema()->dropTableIfExists($this->child);
            $this->db->schema()->dropTableIfExists($this->parent);
        }
    }

    /** A connection, and a parent and child table with an index and a key under legacy names. */
    private function connect(string $type, string $host, int $port): SchemaBuilder
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->db     = $db;
        $mark         = 'ic' . bin2hex(random_bytes(3));
        $this->parent = $mark . '_parent';
        $this->child  = $mark . '_child';
        $schema       = $db->schema();
        $schema->createTable($this->parent, function ($table): void {
            $table->increments('id');
        });
        $parent = $this->parent;
        $schema->createTable($this->child, function ($table) use ($parent): void {
            $table->increments('id');
            $table->integer('parent_id')->unsigned()->nullable();
            $table->string('method', 6)->nullable();
            $table->integer('at')->nullable();
            $table->index(['parent_id'], 'parent_id');
            $table->index(['at', 'method'], 'legacy_at_method');
            $table->foreign('parent_id')->references('id')->on($parent)->name($parent . '_ibfk_1');
        });

        return $schema;
    }

    /**
     * An index under any name is found by its columns, in order and whatever the quoting; a
     * different order or set is not the same index; and a foreign key is found by its column
     * and the table it leads to.
     */
    #[DataProvider('databases')]
    public function testIndexesAndKeysAreFoundByWhatTheyCover(string $type, string $host, int $port): void
    {
        // Arrange
        $schema = $this->connect($type, $host, $port);

        // Act
        $columns = $schema->indexColumns($this->child);
        $found   = [
            $schema->hasIndexOn($this->child, ['parent_id']),
            $schema->hasIndexOn($this->child, ['"AT"', '`method`']),
            $schema->hasIndexOn($this->child, ['method', 'at']),
            $schema->hasIndexOn($this->child, ['method']),
            $schema->hasForeignKeyOn($this->child, 'parent_id', $this->parent),
            $schema->hasForeignKeyOn($this->child, 'method', $this->parent),
            $schema->hasForeignKeyOn($this->child, 'parent_id', $this->child),
        ];

        // Assert
        $this->assertSame(['at', 'method'], $columns['legacy_at_method'] ?? null, 'the columns in their order');
        $this->assertSame([true, true, false, false, true, false, false], $found);
        $this->assertSame([], $schema->indexColumns('no_such_table_' . bin2hex(random_bytes(3))));
        if ($type === 'postgresql') {
            // A schema-qualified name is looked for in that schema.
            $this->assertTrue($schema->hasIndexOn('public.' . $this->child, ['parent_id']));
        }
    }

    /**
     * `add_missing_indexes_to_existing_tables` over a `tokenactions` whose indexes predate the
     * framework adds none of the ones it already has under other names, and adds the ones it
     * lacks — so the table ends with one index per column set.
     */
    #[DataProvider('databases')]
    public function testTheIndexMigrationAddsNoSecondIndex(string $type, string $host, int $port): void
    {
        // Arrange — tokenactions as an older installation had it, under its own prefix
        $this->connect($type, $host, $port);
        $legacy         = clone $this->db;
        $legacy->prefix = 'lg' . bin2hex(random_bytes(2)) . '_';
        $schema         = $legacy->schema();
        $schema->createTable('tokenactions', function ($table): void {
            $table->increments('actionid');
            $table->integer('tokenid');
            $table->integer('urlid');
            $table->string('method', 6);
            $table->integer('action_time');
            $table->index(['tokenid'], 'tokenid');
            $table->index(['action_time', 'method'], 'legacy_time_method');
        });
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $legacy;

        try {
            // Act
            (new \Pramnos\Database\Migrations\AddMissingIndexesToExistingTables($app))->up();
            $indexes = $schema->indexColumns('tokenactions');
        } finally {
            $schema->dropTableIfExists('tokenactions');
        }

        // Assert
        $sets = array_map(static fn (array $c): string => implode(',', $c), array_values($indexes));
        $this->assertSame(1, count(array_keys($sets, 'tokenid')), 'the legacy index, not a second');
        $this->assertSame(1, count(array_keys($sets, 'action_time,method')));
        $this->assertSame(1, count(array_keys($sets, 'urlid')), 'the one it lacked is added');
    }
}
