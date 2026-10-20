<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Grammar\Grammar;
use Pramnos\Database\Grammar\PostgreSQLGrammar;
use Pramnos\Database\QueryBuilder;
use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Datasource;

/**
 * Text search that ignores case and accents on every database: `μαρια` finds `Μαρία`.
 *
 * MySQL's `_ci` collations already compare so; PostgreSQL's `LIKE` tells both apart, so an
 * administration's search found nothing for a name typed without its accent or in lower case.
 * `whereLike()` folds both sides there, and the datatables search through it.
 */
#[CoversClass(QueryBuilder::class)]
#[CoversClass(Grammar::class)]
#[CoversClass(PostgreSQLGrammar::class)]
#[CoversClass(Datasource::class)]
class DatasourceSearchIgnoresCaseAndAccentsTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

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
        $this->db?->query('DROP TABLE IF EXISTS dsfold_people');
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $_POST = [];
        \Pramnos\Http\Request::resetInstance();
    }

    /** Connect, make the connection the framework's, and seed two people. */
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
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        $db->query('DROP TABLE IF EXISTS dsfold_people');
        $db->query($type === 'mysql'
            ? 'CREATE TABLE dsfold_people (id INT PRIMARY KEY, name VARCHAR(50), email VARCHAR(80)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : 'CREATE TABLE dsfold_people (id INT PRIMARY KEY, name VARCHAR(50), email VARCHAR(80))');
        $db->queryBuilder()->table('dsfold_people')->insert(['id' => 1, 'name' => 'Μαρία Παπαδοπούλου', 'email' => 'Maria@Example.com']);
        $db->queryBuilder()->table('dsfold_people')->insert(['id' => 2, 'name' => 'GEORGE Smith', 'email' => 'george@example.com']);

        return $db;
    }

    /**
     * `whereLike()` finds a name typed without its accents or capitals, Greek and Latin, and
     * `orWhereLike()` joins a second one with OR.
     */
    #[DataProvider('databases')]
    public function testWhereLikeIgnoresCaseAndAccents(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $db = $this->connect($type, $host, $port, $user);

        // Act
        $greek = $db->queryBuilder()->table('dsfold_people')->whereLike('name', '%μαρια παπαδοπουλου%')->count();
        $both  = $db->queryBuilder()->table('dsfold_people')->whereLike('name', '%ΜΑΡΊΑ%')->orWhereLike('name', '%george%')->count();
        $none  = $db->queryBuilder()->table('dsfold_people')->whereLike('name', '%νίκος%')->count();

        // Assert
        $this->assertSame(1, (int) $greek, 'lower case, no accents, final sigma');
        $this->assertSame(2, (int) $both);
        $this->assertSame(0, (int) $none);
    }

    /**
     * A datatable's search — the one box and a column's own filter — finds `Μαρία` for `μαρια`,
     * and an address column finds `Maria@Example.com` for the address typed in lower case.
     */
    #[DataProvider('databases')]
    public function testADatatableSearchIgnoresCaseAndAccents(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->connect($type, $host, $port, $user);
        $search = static function (array $post): array {
            $_POST = $post + ['sEcho' => 1, 'iDisplayStart' => 0, 'iDisplayLength' => 10, 'bSearchable_1' => 'true'];
            \Pramnos\Http\Request::resetInstance();

            return (array) json_decode((string) (new Datasource())->render('dsfold_people', ['id', 'name'], true, '', '', false), true);
        };

        // Act
        $global = $search(['sSearch' => 'μαρια παπαδοπουλου']);
        $column = $search(['sSearch_1' => 'μαρία παπαδοπούλου']);

        $_POST = ['sEcho' => 1, 'iDisplayStart' => 0, 'iDisplayLength' => 10, 'bSearchable_0' => 'true', 'sSearch' => 'maria@example.com'];
        \Pramnos\Http\Request::resetInstance();
        $addresses = new Datasource();
        $addresses->addField('email', 'email', '', false, false);
        $address = (array) json_decode((string) $addresses->render('dsfold_people', null, true, '', '', false), true);

        // Assert
        $this->assertSame(1, (int) ($address['iTotalDisplayRecords'] ?? -1), 'the address column');
        $this->assertSame(1, (int) ($global['iTotalDisplayRecords'] ?? -1), 'the search box');
        $this->assertSame(1, (int) ($column['iTotalDisplayRecords'] ?? -1), 'a column\'s filter');
    }
}
