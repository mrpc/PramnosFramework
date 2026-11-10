<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * An INSERT on a builder that asked something first binds its own values alone (FW-401), on MySQL
 * and PostgreSQL.
 *
 * The pattern is natural: `$q = table('x')->where('key', $k)`; `if (!$q->exists()) $q->insert(…)`.
 * The WHERE's bindings went with the insert's values, the arguments no longer matched the
 * placeholders, and every insert failed with "wrong number of arguments" — nothing written. Every
 * statement of the insert family — insert, insertOrIgnore, upsert, insertMany, upsertMany — writes
 * its row after a WHERE on the same instance.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderInsertAfterWhereTest extends TestCase
{
    private ?Database $db = null;

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
        $this->db?->query('DROP TABLE IF EXISTS fw401_keys');
        $this->db?->close();
    }

    /** A connection with an empty `fw401_keys`, or the test is skipped. */
    private function connect(string $type, string $host, int $port, string $user): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->db = $db;
        $db->query('DROP TABLE IF EXISTS fw401_keys');
        $db->schema()->createTable('fw401_keys', static function ($t): void {
            $t->string('k', 20)->primary();
            $t->integer('n')->default(0);
        });

        return $db;
    }

    /** A builder that has asked whether `$key` is there, as a caller does before writing it. */
    private function asked(string $key): QueryBuilder
    {
        $q = $this->db->queryBuilder()->table('fw401_keys')->where('k', $key)->where('n', '>=', 0);
        $this->assertFalse($q->exists(), 'not there yet');

        return $q;
    }

    /**
     * After a WHERE with two bindings on the same instance, each of the five writes its row — with
     * its own values, not the WHERE's.
     */
    #[DataProvider('databases')]
    public function testEveryInsertWritesAfterAWhereOnTheSameBuilder(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $db = $this->connect($type, $host, $port, $user);

        // Act
        $this->asked('a')->insert(['k' => 'a', 'n' => 1]);
        $this->asked('b')->insertOrIgnore(['k' => 'b', 'n' => 2]);
        $this->asked('c')->upsert(['k' => 'c', 'n' => 3], ['k'], ['n']);
        $this->asked('d')->insertMany([['k' => 'd', 'n' => 4], ['k' => 'e', 'n' => 5]]);
        $this->asked('f')->upsertMany([['k' => 'f', 'n' => 6]], ['k'], ['n']);
        // The WHERE still holds for what the same builder asks next.
        $after = $this->asked('z')->count();
        $rows  = $db->queryBuilder()->table('fw401_keys')->orderBy('k')->getAllOrFail();

        // Assert
        $this->assertSame([['a', 1], ['b', 2], ['c', 3], ['d', 4], ['e', 5], ['f', 6]],
            array_map(static fn (array $r): array => [(string) $r['k'], (int) $r['n']], $rows));
        $this->assertSame(0, $after);
    }
}
