<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * On a prefixed installation, the query builder and the schema builder name a table the same:
 * `createTable('things')` makes `{prefix}things`, and `table('things')` reads and writes it — on
 * MySQL and PostgreSQL, through inserts, a SELECT that qualifies its columns with the bare name
 * and joins a second bare table, an update and a delete.
 *
 * With `prefix_bare_names` off, a bare name is the table of that name, unprefixed — what an
 * installation whose own tables were made without the prefix keeps relying on.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderBareNamePrefixIntegrationTest extends TestCase
{
    private ?Database $db = null;

    /** @var list<string> */
    private array $tables = [];

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->db?->query('DROP TABLE IF EXISTS ' . $table);
        }
        $this->db?->close();
    }

    /** A connection to the test database with `$prefix`, or the test is skipped. */
    private function connect(string $type, string $host, int $port, string $user, string $prefix): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        $db->prefix   = $prefix;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        return $this->db = $db;
    }

    /**
     * Tables made by the schema builder under bare names, then read and written by the query
     * builder under the same bare names: every statement reaches the prefixed table.
     */
    #[DataProvider('databases')]
    public function testTheTwoBuildersNameATableTheSame(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $prefix = 'pq' . bin2hex(random_bytes(2)) . '_';
        $db     = $this->connect($type, $host, $port, $user, $prefix);
        $schema = $db->schema();
        $schema->createTable('owners', static function ($t): void {
            $t->integer('id')->primary();
            $t->string('name', 20);
        });
        $schema->createTable('things', static function ($t): void {
            $t->integer('id')->primary();
            $t->integer('owner');
            $t->string('label', 20);
        });
        $this->tables = [$prefix . 'things', $prefix . 'owners'];

        // Act
        $db->queryBuilder()->table('owners')->insert(['id' => 1, 'name' => 'maria']);
        $db->queryBuilder()->table('things')->insert(['id' => 1, 'owner' => 1, 'label' => 'one']);
        $db->queryBuilder()->table('things')->insert(['id' => 2, 'owner' => 1, 'label' => 'two']);
        $db->queryBuilder()->table('things')->where('id', 2)->update(['label' => 'TWO']);
        $db->queryBuilder()->table('things')->where('id', 1)->delete();
        $rows = $db->queryBuilder()->table('things')->select(['things.label', 'owners.name'])
            ->join('owners', 'owners.id', '=', 'things.owner')->where('things.id', 2)->get();
        $direct = $db->query('SELECT COUNT(*) AS n FROM ' . $prefix . 'things');
        $direct->fetch();

        // Assert
        $this->assertSame([['label' => 'TWO', 'name' => 'maria']], array_map(static fn (array $r): array => ['label' => $r['label'], 'name' => $r['name']], $rows->fetchAll()),
            'the bare names in the columns and the join still name the tables');
        $this->assertSame(1, (int) $direct->fields['n'], 'every write reached the prefixed table');
    }

    /**
     * With `prefix_bare_names` off, a bare name reads the unprefixed table, even beside a
     * prefixed one of the same name.
     */
    #[DataProvider('databases')]
    public function testOffABareNameIsTheTableAsNamed(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $prefix = 'pq' . bin2hex(random_bytes(2)) . '_';
        $bare   = 'bn' . bin2hex(random_bytes(3));
        $db     = $this->connect($type, $host, $port, $user, $prefix);
        $db->prefixBareNames = false;
        $db->query('CREATE TABLE ' . $bare . ' (id INT PRIMARY KEY)');
        $db->query('CREATE TABLE ' . $prefix . $bare . ' (id INT PRIMARY KEY)');
        $this->tables = [$bare, $prefix . $bare];

        // Act
        $db->queryBuilder()->table($bare)->insert(['id' => 7]);
        $unprefixed = $db->query('SELECT COUNT(*) AS n FROM ' . $bare);
        $unprefixed->fetch();
        $prefixed = $db->query('SELECT COUNT(*) AS n FROM ' . $prefix . $bare);
        $prefixed->fetch();

        // Assert
        $this->assertSame(1, (int) $unprefixed->fields['n']);
        $this->assertSame(0, (int) $prefixed->fields['n']);
    }
}
