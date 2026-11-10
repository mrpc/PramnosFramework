<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Expression;
use Pramnos\Database\QueryBuilder;

/**
 * A join on a subquery — `joinSub()` / `leftJoinSub()` (FW-398) — on MySQL with and without a
 * prefix and on PostgreSQL.
 *
 * The case that asked for it: every nationality, with how many people of one site have it, in one
 * statement — `nationalities n LEFT JOIN (SELECT nationality, COUNT(*) … WHERE site = ? GROUP BY
 * nationality) x ON x.nationality = n.id`. What must hold: the subquery's own binding is bound in
 * its place, before an outer WHERE's; the left join keeps the nationalities nobody has; an inner
 * join keeps only those somebody has; a closure gives several conditions; the subquery's bare
 * table names take the prefix; and a builder as well as a closure is a subquery.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderJoinSubTest extends TestCase
{
    private ?Database $db = null;

    /** @var list<string> */
    private array $tables = [];

    /** @return array<string, array{string, string, int, string, string}> */
    public static function databases(): array
    {
        return [
            'mysql'           => ['mysql', 'db', 3306, 'root', ''],
            'mysql, prefixed' => ['mysql', 'db', 3306, 'root', 'js_'],
            'postgresql'      => ['postgresql', 'timescaledb', 5432, 'postgres', ''],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->db?->query('DROP TABLE IF EXISTS ' . $table);
        }
        $this->db?->close();
    }

    /** Nationalities, and people of two sites holding them; the connection under test. */
    private function seed(string $type, string $host, int $port, string $user, string $prefix): Database
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
        $this->db     = $db;
        $this->tables = [$prefix . 'js_people', $prefix . 'js_nationalities'];
        foreach ($this->tables as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }
        $schema = $db->schema();
        $schema->createTable('js_nationalities', static function ($t): void {
            $t->integer('id')->primary();
            $t->string('name', 20);
        });
        $schema->createTable('js_people', static function ($t): void {
            $t->integer('id')->primary();
            $t->integer('nationality')->nullable();
            $t->integer('site');
        });
        foreach ([[1, 'Greek'], [2, 'British'], [3, 'Italian'], [4, 'Cypriot']] as [$id, $name]) {
            $db->queryBuilder()->table('js_nationalities')->insert(['id' => $id, 'name' => $name]);
        }
        // Site 1: three Greek, one British, one with none. Site 2: two Italian, one Greek.
        foreach ([[1, 1, 1], [2, 1, 1], [3, 1, 1], [4, 2, 1], [5, null, 1], [6, 3, 2], [7, 3, 2], [8, 1, 2]] as [$id, $nationality, $site]) {
            $db->queryBuilder()->table('js_people')->insert(['id' => $id, 'nationality' => $nationality, 'site' => $site]);
        }

        return $db;
    }

    /** Site `$site`'s count of each nationality, as a subquery. */
    private static function counted(int $site): \Closure
    {
        return static fn (QueryBuilder $q) => $q->table('js_people p')->select(['p.nationality', new Expression('COUNT(*) AS c')])
            ->where('p.site', $site)->groupBy('p.nationality');
    }

    /**
     * Every nationality with one site's count, most first, in one statement: the subquery's binding
     * keeps it to its site, an outer binding after it still applies, and the left join keeps those
     * nobody there has, with no count.
     */
    #[DataProvider('databases')]
    public function testEveryNationalityWithOneSitesCount(string $type, string $host, int $port, string $user, string $prefix): void
    {
        // Arrange
        $db = $this->seed($type, $host, $port, $user, $prefix);

        // Act
        $rows = $db->queryBuilder()->table('js_nationalities n')->select(['n.name', 'x.c'])
            ->leftJoinSub(self::counted(1), 'x', 'x.nationality', '=', 'n.id')
            ->where('n.id', '<', 4)                                   // an outer binding, after the subquery's
            ->orderBy('n.id')->getAllOrFail();

        // Assert — site 1 only: three Greek, one British, no Italian; Cypriot left out by the outer WHERE
        $this->assertSame([['Greek', 3], ['British', 1], ['Italian', null]],
            array_map(static fn (array $r): array => [$r['name'], $r['c'] === null ? null : (int) $r['c']], $rows));
    }

    /**
     * An inner join keeps only the nationalities somebody has; a closure joins on several
     * conditions; a builder is a subquery as a closure is.
     */
    #[DataProvider('databases')]
    public function testAnInnerJoinSeveralConditionsAndABuilder(string $type, string $host, int $port, string $user, string $prefix): void
    {
        // Arrange
        $db  = $this->seed($type, $host, $port, $user, $prefix);
        $sub = $db->queryBuilder();
        (self::counted(2))($sub);

        // Act
        $inner = $db->queryBuilder()->table('js_nationalities n')->select(['n.name', 'x.c'])
            ->joinSub($sub, 'x', 'x.nationality', '=', 'n.id')->orderBy('n.id')->getAllOrFail();
        $several = $db->queryBuilder()->table('js_nationalities n')->select(['n.name', 'x.c'])
            ->joinSub(self::counted(1), 'x', static function ($on): void {
                $on->on('x.nationality', '=', 'n.id')->on('x.c', '>', 'n.id');
            })->orderBy('n.id')->getAllOrFail();

        // Assert
        $this->assertSame([['Greek', 1], ['Italian', 2]],
            array_map(static fn (array $r): array => [$r['name'], (int) $r['c']], $inner), 'site 2, from a builder');
        $this->assertSame([['Greek', 3]],
            array_map(static fn (array $r): array => [$r['name'], (int) $r['c']], $several), 'both conditions: British has 1, not more than its id 2');
    }
}
