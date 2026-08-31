<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;

/**
 * Which physical table a model addresses, on all four backends.
 *
 * A unit test rather than an integration one, deliberately. The integration lane runs **one**
 * engine, so an engine-specific mistake cannot fail there — and that is exactly what happened:
 * the schema-table fix was written and tested against MySQL, shipped, and the first PostgreSQL
 * request produced
 *
 *     INSERT INTO public.authserver.roles () VALUES ()
 *
 * because the connection's own schema was prepended to a name that already carried one. Here the
 * engine is a property of a throwaway connection, so MySQL, MariaDB and PostgreSQL are all
 * answered in the same run.
 *
 * MariaDB and TimescaleDB are asserted separately even though neither has a `type` of its own —
 * MariaDB reports `mysql`, and a settings file saying `timescaledb` is normalised to `postgresql`
 * with a `timescale` flag. Both identities are **decisions**, and this method compares
 * `type == 'postgresql'` literally: drop the normalisation and every Timescale installation
 * silently takes the MySQL path, where a schema is flattened into a name that does not exist.
 * Nothing else in this suite runs either flavour.
 */
#[CoversClass(Model::class)]
class ModelTableNameTest extends TestCase
{
    private ?\Pramnos\Database\Database $previous = null;

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $dbRef = &\Pramnos\Database\Database::getInstance();
            $dbRef = $this->previous;
            $this->previous = null;
        }
    }

    /**
     * Put a connection of the given type in place of the singleton.
     *
     * A **clone**, not a mutated singleton. `DatabaseCapabilities` memoises per Database instance
     * in a WeakMap, so flipping `->type` on the shared object leaves `isMySQL()` answering for the
     * driver it was first asked about — which is a test that passes or fails depending on the
     * order its own methods ran in. A fresh object gets a fresh answer.
     */
    private function driver(string $type, string $schema = ''): \Pramnos\Database\Database
    {
        $dbRef = &\Pramnos\Database\Database::getInstance();

        if ($this->previous === null) {
            $this->previous = $dbRef;
        }

        $connection         = clone $this->previous;
        $connection->type   = $type;
        $connection->schema = $schema;
        $dbRef              = $connection;

        return $connection;
    }

    /**
     * The MariaDB connection nothing else in this suite has.
     *
     * `isMariaDB()` asks the server for its version string, which a throwaway connection has no
     * way to obtain — so the memoised answer is set directly. `type` stays `mysql`, because that
     * is what a real MariaDB connection reports.
     */
    private function mariadb(): \Pramnos\Database\Database
    {
        $connection = $this->driver('mysql');

        $flag = new \ReflectionProperty(\Pramnos\Database\Database::class, 'isMariaDB');
        $flag->setValue($connection, true);

        return $connection;
    }

    /** A model over `$table`, with no controller and no connection of its own. */
    private function model(string $table, ?string $schema = null): Model
    {
        $model = new class () extends Model {
            public string $tableName = '';

            public ?string $schemaName = null;

            public function __construct()
            {
                // Deliberately not parent::__construct(): it wants a Controller, and nothing
                // below this line touches one.
            }

            public function point(string $table, ?string $schema): static
            {
                $this->_dbtable  = $table;
                $this->_dbschema = $schema;

                return $this;
            }
        };

        return $model->point($table, $schema);
    }

    /**
     * On PostgreSQL a qualified name is left alone, whatever the connection's schema is.
     *
     * The regression, in one assertion: `public` was prepended to `authserver.roles`, and
     * PostgreSQL reads `public.authserver.roles` as database.schema.table — a name that cannot
     * exist.
     */
    public function testPostgresLeavesAQualifiedNameAlone(): void
    {
        // Arrange
        $this->driver('postgresql', 'public');

        // Assert
        $this->assertSame(
            'authserver.roles',
            $this->model('authserver.roles')->getFullTableName()
        );
    }

    /** And an explicit `_dbschema` does not double it either. */
    public function testAnExplicitSchemaDoesNotDoubleTheQualifiedName(): void
    {
        // Arrange
        $this->driver('postgresql', 'public');

        // Assert
        $this->assertSame(
            'authserver.roles',
            $this->model('authserver.roles', 'pramnos')->getFullTableName()
        );
    }

    /**
     * On MySQL the schema is flattened into the name, because there are no schemas.
     *
     * `authserver.roles` reaches MySQL as *another database*, which is why it threw rather than
     * quietly reading the wrong rows.
     */
    public function testMysqlFlattensAQualifiedName(): void
    {
        // Arrange
        $prefix = $this->driver('mysql')->prefix;

        // Act
        $name = $this->model('authserver.roles')->getFullTableName();

        // Assert
        $this->assertSame($prefix . 'authserver_roles', $name);
        $this->assertStringNotContainsString('.', $name);
    }

    /**
     * MariaDB flattens it exactly as MySQL does.
     *
     * It is the same family — one wire protocol, one grammar, one `information_schema` — and the
     * framework says so in `DatabaseCapabilities`: `isMySQL()` is true on MariaDB on purpose.
     * Asserted because that is a decision, not a fact, and reversing it would route MariaDB down
     * the PostgreSQL path where a dotted name is left as a schema reference MariaDB cannot honour.
     */
    public function testMariadbFlattensAQualifiedNameLikeMysql(): void
    {
        // Arrange
        $connection = $this->mariadb();

        // Act
        $name = $this->model('authserver.roles')->getFullTableName();

        // Assert
        $this->assertTrue(
            $connection->isMariaDB(),
            'this test is meaningless unless the connection reports MariaDB'
        );
        $this->assertSame($connection->prefix . 'authserver_roles', $name);
        $this->assertStringNotContainsString('.', $name);
    }

    /**
     * TimescaleDB addresses tables exactly as PostgreSQL does.
     *
     * It is an extension, not an engine: the same grammar, the same schemas, the same
     * `information_schema`. The flag changes what a *migration* may ask for — hypertables,
     * compression, retention — and nothing about where a row lives.
     */
    public function testTimescaleAddressesTablesLikePostgres(): void
    {
        // Arrange
        $connection            = $this->driver('postgresql', 'public');
        $connection->timescale = true;

        // Assert
        $this->assertSame(
            'authserver.roles',
            $this->model('authserver.roles')->getFullTableName()
        );
        $this->assertSame('public.users', $this->model('users')->getFullTableName());
    }

    /**
     * And a settings file that says `timescaledb` becomes a `postgresql` connection.
     *
     * The trap this guards: `getFullTableName()` — and half the framework besides — compares
     * `type == 'postgresql'` literally. A connection left reporting `timescaledb` matches none of
     * those branches and falls through to the MySQL path, which flattens `authserver.roles` into
     * a table PostgreSQL does not have. The normalisation happens once, when the settings are
     * read, and everything downstream depends on it having happened.
     */
    public function testTimescaledbIsNormalisedToPostgresql(): void
    {
        // Arrange
        if (!extension_loaded('pgsql')) {
            $this->markTestSkipped('The constructor refuses PostgreSQL without the extension.');
        }

        $previous = \Pramnos\Application\Settings::getSetting('database');
        \Pramnos\Application\Settings::setSetting('database', (object) [
            'type'     => 'timescaledb',
            'hostname' => 'db',
            'database' => 'irrelevant',
            'user'     => 'irrelevant',
            'password' => '',
        ], false);

        // Act — the constructor is where the normalisation happens.
        $connection = new \Pramnos\Database\Database(new \Pramnos\Application\Settings());

        // Assert
        $this->assertSame('postgresql', $connection->type);
        $this->assertTrue($connection->timescale);

        // Cleanup
        \Pramnos\Application\Settings::setSetting('database', $previous, false);
    }

    /**
     * An unqualified name still gets the connection's schema on PostgreSQL.
     *
     * The behaviour every other model in the framework relies on, asserted so the fix above
     * cannot be mistaken for a change to it.
     */
    public function testAnUnqualifiedNameStillGetsTheSchema(): void
    {
        // Arrange
        $this->driver('postgresql', 'public');

        // Assert
        $this->assertSame('public.users', $this->model('users')->getFullTableName());
        $this->assertSame(
            'pramnos.users',
            $this->model('users', 'pramnos')->getFullTableName()
        );
    }

    /**
     * The column list is asked for with the schema and the table **split**.
     *
     * The second half of the same failure, and the one that produced the 500. `getFullTableName()`
     * was fixed and the screen still broke, because the introspection query asked PostgreSQL for
     * `table_schema = 'public' AND table_name = 'authserver.roles'` — a row that cannot exist. An
     * empty column list is not an error anywhere, so `_save()` went on to build
     * `INSERT INTO authserver.roles () VALUES ()` and the operator saw a syntax error two steps
     * from the cause.
     */
    public function testPostgresAsksForTheSchemaAndTableSeparately(): void
    {
        // Arrange
        $this->driver('postgresql', 'public');
        $model = $this->model('authserver.roles');

        // Act
        $sql = $this->introspectionSql($model, $model->getFullTableName());

        // Assert
        $this->assertStringContainsString("table_schema = 'authserver'", $sql);
        $this->assertStringContainsString("table_name = 'roles'", $sql);
        $this->assertStringNotContainsString("'authserver.roles'", $sql);
    }

    /** An unqualified name asks for the connection's schema, as it always did. */
    public function testPostgresFallsBackToTheConnectionSchema(): void
    {
        // Arrange
        $this->driver('postgresql', 'public');
        $model = $this->model('users');

        // Act
        $sql = $this->introspectionSql($model, $model->getFullTableName());

        // Assert
        $this->assertStringContainsString("table_schema = 'public'", $sql);
        $this->assertStringContainsString("table_name = 'users'", $sql);
    }

    /** The MySQL family asks the only way it can, with no schema to split off. */
    public function testTheMysqlFamilyShowsColumns(): void
    {
        // Arrange
        $connection = $this->mariadb();
        $model      = $this->model('authserver.roles');

        // Act
        $sql = $this->introspectionSql($model, $model->getFullTableName());

        // Assert
        $this->assertSame(
            'SHOW COLUMNS FROM `' . $connection->prefix . 'authserver_roles`',
            $sql
        );
    }

    /** The private one reader, reached the only way a test can reach it. */
    private function introspectionSql(Model $model, string $resolved): string
    {
        $method = new \ReflectionMethod(Model::class, 'columnIntrospectionSql');

        return (string) $method->invoke($model, $resolved);
    }

    /**
     * `#PREFIX#` wins over the dot, on both backends.
     *
     * It has already said where the prefix goes; resolving the dot as a schema on top of that
     * would rename the table twice.
     */
    public function testAnExplicitPrefixTokenIsNotTreatedAsASchema(): void
    {
        // Act
        $prefix = $this->driver('mysql')->prefix;
        $mysql  = $this->model('#PREFIX#users')->getFullTableName();

        $this->driver('postgresql', 'public');
        $postgres = $this->model('#PREFIX#users')->getFullTableName();

        // Assert
        $this->assertSame($prefix . 'users', $mysql);
        $this->assertSame('public.' . $prefix . 'users', $postgres);
    }
}
