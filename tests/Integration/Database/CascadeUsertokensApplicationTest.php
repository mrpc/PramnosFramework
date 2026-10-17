<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\Core\CascadeUsertokensApplication;

/**
 * Deleting an OAuth client takes its tokens with it, and the migration that makes it so touches
 * only a key that still detaches them.
 *
 * It used to drop and re-create the key on every run — which on MySQL rebuilds the whole of
 * `usertokens` — and to add it where the migration that adds keys had skipped it on purpose
 * (orphaned rows, a type mismatch), failing for the same reason.
 */
#[CoversClass(CascadeUsertokensApplication::class)]
final class CascadeUsertokensApplicationTest extends TestCase
{
    private ?Database $db = null;

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
            $this->db->schema()->dropTableIfExists('usertokens');
            $this->db->schema()->dropTableIfExists('applications');
        }
    }

    /**
     * Its own `usertokens` and `applications`, under a prefix of their own, with the key as it
     * was (`SET NULL`) or with none; and the migration over that connection.
     */
    private function tables(string $type, string $host, int $port, ?string $onDelete): CascadeUsertokensApplication
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
        $db->prefix = 'cu' . bin2hex(random_bytes(2)) . '_';
        $this->db   = $db;
        $schema     = $db->schema();
        $schema->createTable('applications', function ($table): void {
            $table->increments('appid');
        });
        $schema->createTable('usertokens', function ($table) use ($onDelete): void {
            $table->increments('tokenid');
            $table->integer('applicationid')->unsigned()->nullable();
            if ($onDelete !== null) {
                $table->foreign('applicationid')->references('appid')->on('applications')
                    ->onDelete($onDelete)->onUpdate('cascade')->name('fk_usertokens_applicationid');
            }
        });
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;

        return new CascadeUsertokensApplication($app);
    }

    /** The key's delete rule, read through the migration itself. */
    private function rule(CascadeUsertokensApplication $migration): ?string
    {
        return (new \ReflectionMethod($migration, 'deleteRule'))->invoke($migration, 'fk_usertokens_applicationid');
    }

    /**
     * `SET NULL` becomes `CASCADE` and a client's tokens go with it; a second run leaves it; and
     * `down()` puts `SET NULL` back.
     */
    #[DataProvider('databases')]
    public function testTheKeyCascadesOnceAndIsReversible(string $type, string $host, int $port): void
    {
        // Arrange
        $migration = $this->tables($type, $host, $port, 'set null');
        $this->db->queryBuilder()->table('#PREFIX#applications')->insert(['appid' => 7]);
        $this->db->queryBuilder()->table('#PREFIX#usertokens')->insert(['tokenid' => 1, 'applicationid' => 7]);

        // Act
        $before = $this->rule($migration);
        $migration->up();
        $after = $this->rule($migration);
        $migration->up();
        $again = $this->rule($migration);
        $this->db->queryBuilder()->table('#PREFIX#applications')->where('appid', 7)->delete();
        $left = $this->db->queryBuilder()->table('#PREFIX#usertokens')->count();
        $migration->down();

        // Assert
        $this->assertSame(['SET NULL', 'CASCADE', 'CASCADE'], [$before, $after, $again]);
        $this->assertSame(0, $left, 'the client\'s token went with it');
        $this->assertSame('SET NULL', $this->rule($migration), 'reversible');
    }

    /**
     * With no key — skipped by the migration that adds keys, for orphans or a type mismatch —
     * none is added, so the reason it was skipped cannot fail this one.
     */
    #[DataProvider('databases')]
    public function testWithoutAKeyNoneIsAdded(string $type, string $host, int $port): void
    {
        // Arrange — an orphaned token, which a key would refuse
        $migration = $this->tables($type, $host, $port, null);
        $this->db->queryBuilder()->table('#PREFIX#usertokens')->insert(['tokenid' => 1, 'applicationid' => 999]);

        // Act
        $migration->up();
        $migration->down();

        // Assert
        $this->assertNull($this->rule($migration));
        $this->assertSame(1, $this->db->queryBuilder()->table('#PREFIX#usertokens')->count());
    }
}
