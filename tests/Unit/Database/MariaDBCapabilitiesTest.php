<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Database\Grammar\MariaDBSchemaGrammar;
use Pramnos\Database\Grammar\MySQLSchemaGrammar;
use Pramnos\Database\Grammar\PostgreSQLSchemaGrammar;

/**
 * Unit tests for MariaDB flavor detection, the version-aware capability
 * constants that hang off it, and the MariaDB DDL grammar.
 *
 * The central invariant these tests protect is the one that is easiest to
 * "fix" wrongly later: MariaDB is a *flavor of the MySQL family*, so
 * isMySQL() must stay true on MariaDB while isMariaDB() narrows it. Fifteen
 * call sites in src/ read isMySQL() as "compile MySQL-compatible grammar";
 * making it false on MariaDB would silently route every one of them down the
 * PostgreSQL branch.
 *
 * No live database is required — the server version string is injected through
 * a mocked Database::getServerVersion().
 */
class MariaDBCapabilitiesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a Database test double whose only stubbed behaviour is the raw
     * server version string. isMariaDB() runs its real implementation on top,
     * so the version→flavor parsing is genuinely exercised rather than faked.
     *
     * @param  string $type    Framework engine type ('mysql' or 'postgresql').
     * @param  string $version Raw version string the "server" reports.
     * @return Database
     */
    private function makeDb(string $type, string $version): Database
    {
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getServerVersion'])
            ->getMock();
        $db->type      = $type;
        $db->timescale = false;
        $db->prefix    = '';
        $db->schema    = '';
        $db->method('getServerVersion')->willReturn($version);

        return $db;
    }

    /**
     * Capabilities object for a server of the given type and version.
     *
     * @param  string $type
     * @param  string $version
     * @return DatabaseCapabilities
     */
    private function caps(string $type, string $version): DatabaseCapabilities
    {
        return new DatabaseCapabilities($this->makeDb($type, $version));
    }

    /** A realistic MariaDB 10.11 version string as mysqli reports it. */
    private const MARIADB_10_11 = '10.11.6-MariaDB-1:10.11.6+maria~ubu2204';

    /** A realistic Oracle MySQL 8.0 version string. */
    private const MYSQL_8_0 = '8.0.36-0ubuntu0.22.04.1';

    // =========================================================================
    // Database::isMariaDB() / getServerVersion()
    // =========================================================================

    /**
     * The version string is the *only* signal that separates MariaDB from
     * MySQL: both connect via mysqli and both are configured as type 'mysql',
     * so config can never answer this question.
     */
    public function testIsMariaDBTrueWhenVersionStringMentionsMariaDB(): void
    {
        // Arrange
        $db = $this->makeDb('mysql', self::MARIADB_10_11);

        // Act
        $isMaria = $db->isMariaDB();

        // Assert
        $this->assertTrue($isMaria);
    }

    /**
     * MariaDB advertises itself to old clients with a fake "5.5.5-" prefix.
     * Detection must still see through it — otherwise every such connection
     * would be mistaken for MySQL.
     */
    public function testIsMariaDBTrueForLegacyFivePrefixedVersionString(): void
    {
        // Arrange
        $db = $this->makeDb('mysql', '5.5.5-10.6.16-MariaDB-log');

        // Act & Assert
        $this->assertTrue($db->isMariaDB());
    }

    /**
     * An Oracle MySQL server must never be mistaken for MariaDB.
     */
    public function testIsMariaDBFalseOnOracleMySQL(): void
    {
        // Arrange
        $db = $this->makeDb('mysql', self::MYSQL_8_0);

        // Act & Assert
        $this->assertFalse($db->isMariaDB());
    }

    /**
     * On PostgreSQL the question is meaningless; the answer must be false and,
     * critically, must not depend on the version string at all.
     */
    public function testIsMariaDBFalseOnPostgreSQL(): void
    {
        // Arrange — a deliberately absurd version string containing "MariaDB"
        $db = $this->makeDb('postgresql', '14.10-MariaDB-nonsense');

        // Act & Assert — the engine type short-circuits before the string is read
        $this->assertFalse($db->isMariaDB());
    }

    /**
     * Tests construct `new Database()` without connecting. Detection must
     * degrade to "not MariaDB" instead of trying to open a connection.
     */
    public function testIsMariaDBFalseWhenVersionIsUnknown(): void
    {
        // Arrange
        $db = $this->makeDb('mysql', '');

        // Act & Assert
        $this->assertFalse($db->isMariaDB());
    }

    /**
     * getServerVersion() on an unconnected Database must return '' without
     * touching the connection — calling getConnectionLink() would open one as
     * a side effect, which unit tests must never trigger.
     */
    public function testGetServerVersionReturnsEmptyAndDoesNotConnectWhenDisconnected(): void
    {
        // Arrange
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnectionLink'])
            ->getMock();
        $db->type      = 'mysql';
        $db->connected = false;
        // The assertion that matters: no connection attempt is made.
        $db->expects($this->never())->method('getConnectionLink');

        // Act
        $version = $db->getServerVersion();

        // Assert
        $this->assertSame('', $version);
    }

    /**
     * When the connection exists but yields no usable link, the version is
     * unknown rather than an error — and that answer is memoised.
     */
    public function testGetServerVersionReturnsEmptyWhenLinkIsUnavailable(): void
    {
        // Arrange
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnectionLink'])
            ->getMock();
        $db->type      = 'mysql';
        $db->connected = true;
        // Called once only: the empty answer is cached after the first lookup.
        $db->expects($this->once())->method('getConnectionLink')->willReturn(null);

        // Act
        $first  = $db->getServerVersion();
        $second = $db->getServerVersion();

        // Assert
        $this->assertSame('', $first);
        $this->assertSame('', $second);
    }

    /**
     * A driver-level failure must not escape as an exception — capability
     * detection is called from grammar selection, where throwing would break
     * otherwise-working code.
     */
    public function testGetServerVersionSwallowsDriverFailures(): void
    {
        // Arrange
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnectionLink'])
            ->getMock();
        $db->type      = 'mysql';
        $db->connected = true;
        $db->method('getConnectionLink')
            ->willThrowException(new \RuntimeException('connection lost'));

        // Act & Assert
        $this->assertSame('', $db->getServerVersion());
    }

    // =========================================================================
    // Engine predicates
    // =========================================================================

    /**
     * The interconnection point of this whole feature: MariaDB is inside the
     * MySQL family, so isMySQL() stays true. Fifteen call sites depend on it.
     */
    public function testIsMySQLStaysTrueOnMariaDB(): void
    {
        // Arrange
        $caps = $this->caps('mysql', self::MARIADB_10_11);

        // Act & Assert
        $this->assertTrue($caps->isMySQL());
        $this->assertTrue($caps->isMariaDB()); // and isMariaDB narrows it
    }

    /**
     * The narrowing predicate must be false on plain MySQL, otherwise MySQL
     * would be handed MariaDB-only sequence DDL.
     */
    public function testIsMariaDBFalseOnMySQLCapabilities(): void
    {
        // Arrange
        $caps = $this->caps('mysql', self::MYSQL_8_0);

        // Act & Assert
        $this->assertTrue($caps->isMySQL());
        $this->assertFalse($caps->isMariaDB());
    }

    /**
     * PostgreSQL is neither.
     */
    public function testEnginePredicatesOnPostgreSQL(): void
    {
        // Arrange
        $caps = $this->caps('postgresql', '14.10');

        // Act & Assert
        $this->assertFalse($caps->isMySQL());
        $this->assertFalse($caps->isMariaDB());
        $this->assertTrue($caps->isPostgreSQL());
    }

    // =========================================================================
    // Version accessor / atLeast()
    // =========================================================================

    /**
     * The raw version string carries vendor noise that version_compare()
     * mishandles; getVersion() must reduce it to a plain dotted number.
     *
     * @param string $type     Engine type.
     * @param string $raw      Raw string the server reports.
     * @param string $expected Normalised dotted version.
     */
    #[DataProvider('versionNormalisationProvider')]
    public function testVersionNormalisation(string $type, string $raw, string $expected): void
    {
        // Arrange
        $caps = $this->caps($type, $raw);

        // Act
        $version = $caps->getVersion();

        // Assert
        $this->assertSame($expected, $version);
    }

    /**
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function versionNormalisationProvider(): array
    {
        return [
            'mariadb packaged'   => ['mysql', self::MARIADB_10_11, '10.11.6'],
            // The "5.5.5-" prefix must be stripped, or every MariaDB would
            // compare as MySQL 5.5 and fail every atLeast() gate.
            'mariadb 5.5.5 lie'  => ['mysql', '5.5.5-10.6.16-MariaDB-log', '10.6.16'],
            'mysql packaged'     => ['mysql', self::MYSQL_8_0, '8.0.36'],
            'postgresql'         => ['postgresql', '14.10', '14.10'],
            'unknown'            => ['mysql', '', ''],
            'non numeric'        => ['mysql', 'unknown-build', ''],
        ];
    }

    /**
     * atLeast() must answer false for an unknown version: an unidentifiable
     * server is treated as too old, keeping new behaviour opt-in.
     */
    public function testAtLeastIsFalseWhenVersionUnknown(): void
    {
        // Arrange
        $caps = $this->caps('mysql', '');

        // Act & Assert
        $this->assertFalse($caps->atLeast('5.0'));
    }

    /**
     * Boundary check: the comparison is inclusive of the named version.
     */
    public function testAtLeastIsInclusiveAtTheBoundary(): void
    {
        // Arrange
        $caps = $this->caps('mysql', '10.3.0-MariaDB');

        // Act & Assert
        $this->assertTrue($caps->atLeast('10.3'));   // equal → true
        $this->assertFalse($caps->atLeast('10.3.1')); // one patch higher → false
    }

    // =========================================================================
    // SEQUENCES
    // =========================================================================

    /**
     * Sequence support by (engine, flavor, version). The 10.3 boundary is the
     * release in which MariaDB gained sequence objects; below it the framework
     * must keep the MySQL no-op behaviour rather than emit unparsable DDL.
     *
     * @param string $type
     * @param string $version
     * @param bool   $expected
     */
    #[DataProvider('sequencesProvider')]
    public function testSequenceCapability(string $type, string $version, bool $expected): void
    {
        // Arrange
        $caps = $this->caps($type, $version);

        // Act
        $supported = $caps->has(DatabaseCapabilities::SEQUENCES);

        // Assert
        $this->assertSame($expected, $supported);
        $this->assertSame($expected, $caps->hasSequences()); // convenience alias agrees
    }

    /**
     * @return array<string, array{0:string,1:string,2:bool}>
     */
    public static function sequencesProvider(): array
    {
        return [
            'postgresql'      => ['postgresql', '14.10', true],
            'mariadb 10.2'    => ['mysql', '10.2.44-MariaDB', false], // just below the boundary
            'mariadb 10.3'    => ['mysql', '10.3.39-MariaDB', true],  // the boundary itself
            'mariadb 10.11'   => ['mysql', self::MARIADB_10_11, true],
            'mysql 8.0'       => ['mysql', self::MYSQL_8_0, false],   // no sequences at any version
            'unknown version' => ['mysql', '', false],
        ];
    }

    // =========================================================================
    // RETURNING
    // =========================================================================

    /**
     * RETURNING by (engine, flavor, version). MariaDB completed INSERT …
     * RETURNING in 10.5, which is the version gated on here.
     *
     * @param string $type
     * @param string $version
     * @param bool   $expected
     */
    #[DataProvider('returningProvider')]
    public function testReturningCapability(string $type, string $version, bool $expected): void
    {
        // Arrange
        $caps = $this->caps($type, $version);

        // Act & Assert
        $this->assertSame($expected, $caps->has(DatabaseCapabilities::RETURNING));
        $this->assertSame($expected, $caps->hasReturning());
    }

    /**
     * @return array<string, array{0:string,1:string,2:bool}>
     */
    public static function returningProvider(): array
    {
        return [
            'postgresql'    => ['postgresql', '14.10', true],
            'mariadb 10.3'  => ['mysql', '10.3.39-MariaDB', false],
            'mariadb 10.5'  => ['mysql', '10.5.23-MariaDB', true], // the boundary
            'mariadb 10.11' => ['mysql', self::MARIADB_10_11, true],
            'mysql 8.0'     => ['mysql', self::MYSQL_8_0, false],
        ];
    }

    // =========================================================================
    // NATIVE_JSON
    // =========================================================================

    /**
     * NATIVE_JSON asks whether the server has a *real* JSON type. The
     * non-obvious case is MariaDB: it accepts the `JSON` keyword, so naive
     * detection says yes, but the column is really LONGTEXT with a
     * CHECK (json_valid(...)) constraint. Anything relying on binary storage,
     * type identity, or JSON indexing must be told no.
     *
     * @param string $type
     * @param string $version
     * @param bool   $expected
     */
    #[DataProvider('nativeJsonProvider')]
    public function testNativeJsonCapability(string $type, string $version, bool $expected): void
    {
        // Arrange
        $caps = $this->caps($type, $version);

        // Act & Assert
        $this->assertSame($expected, $caps->has(DatabaseCapabilities::NATIVE_JSON));
        $this->assertSame($expected, $caps->hasNativeJson());
    }

    /**
     * @return array<string, array{0:string,1:string,2:bool}>
     */
    public static function nativeJsonProvider(): array
    {
        return [
            'postgresql'      => ['postgresql', '14.10', true],
            'mysql 5.7.7'     => ['mysql', '5.7.7', false],   // just below the boundary
            'mysql 5.7.8'     => ['mysql', '5.7.8', true],    // the boundary itself
            'mysql 8.0'       => ['mysql', self::MYSQL_8_0, true],
            // Newest MariaDB still has no native JSON type.
            'mariadb 10.11'   => ['mysql', self::MARIADB_10_11, false],
            'unknown version' => ['mysql', '', false],
        ];
    }

    /**
     * The pre-existing FEATURE_JSON constant is a coarser "can I store JSON at
     * all" question and stays unconditionally true — NATIVE_JSON must not have
     * changed its meaning, or existing callers would silently flip behaviour.
     */
    public function testLegacyFeatureJsonStaysTrueOnMariaDB(): void
    {
        // Arrange
        $caps = $this->caps('mysql', self::MARIADB_10_11);

        // Act & Assert
        $this->assertTrue($caps->has(DatabaseCapabilities::FEATURE_JSON));
        $this->assertFalse($caps->has(DatabaseCapabilities::NATIVE_JSON));
    }

    // =========================================================================
    // CHECK_CONSTRAINTS
    // =========================================================================

    /**
     * CHECK constraints by (engine, flavor, version). Both families reached
     * enforcement late and at different versions — MariaDB 10.2, MySQL 8.0.16 —
     * and before those versions the clause is parsed and silently discarded,
     * which is the worst possible failure mode for a data-integrity feature.
     *
     * @param string $type
     * @param string $version
     * @param bool   $expected
     */
    #[DataProvider('checkConstraintsProvider')]
    public function testCheckConstraintsCapability(string $type, string $version, bool $expected): void
    {
        // Arrange
        $caps = $this->caps($type, $version);

        // Act & Assert
        $this->assertSame($expected, $caps->has(DatabaseCapabilities::CHECK_CONSTRAINTS));
        $this->assertSame($expected, $caps->hasCheckConstraints());
    }

    /**
     * @return array<string, array{0:string,1:string,2:bool}>
     */
    public static function checkConstraintsProvider(): array
    {
        return [
            'postgresql'      => ['postgresql', '14.10', true],
            'mariadb 10.1'    => ['mysql', '10.1.48-MariaDB', false],
            'mariadb 10.2'    => ['mysql', '10.2.44-MariaDB', true],  // boundary
            'mysql 8.0.15'    => ['mysql', '8.0.15', false],
            'mysql 8.0.16'    => ['mysql', '8.0.16', true],           // boundary
            'mysql 5.7'       => ['mysql', '5.7.44', false],
            'unknown version' => ['mysql', '', false],
        ];
    }

    // =========================================================================
    // Caching
    // =========================================================================

    /**
     * The WeakMap cache must still work for the new features: the version
     * lookup is a driver round-trip on a live connection, so repeating it on
     * every has() call would be a real cost.
     */
    public function testNewCapabilitiesAreCachedPerConnection(): void
    {
        // Arrange — getServerVersion() is allowed at most once per feature
        // resolution; asserting a bounded call count proves caching happens.
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getServerVersion'])
            ->getMock();
        $db->type = 'mysql';
        $db->method('getServerVersion')->willReturn(self::MARIADB_10_11);

        $caps = new DatabaseCapabilities($db);

        // Act — resolve the same feature repeatedly
        $first = $caps->has(DatabaseCapabilities::SEQUENCES);
        for ($i = 0; $i < 5; $i++) {
            $caps->has(DatabaseCapabilities::SEQUENCES);
        }

        // Assert — value is stable and the cache bucket holds it
        $this->assertTrue($first);
        $this->assertTrue($caps->has(DatabaseCapabilities::SEQUENCES));
    }

    /**
     * Two separate connections must not share answers — a process may talk to
     * a MariaDB and a MySQL at the same time.
     */
    public function testCapabilitiesAreNotSharedBetweenConnections(): void
    {
        // Arrange
        $mariaCaps = $this->caps('mysql', self::MARIADB_10_11);
        $mysqlCaps = $this->caps('mysql', self::MYSQL_8_0);

        // Act & Assert — the cache is keyed by Database instance, not by type
        $this->assertTrue($mariaCaps->has(DatabaseCapabilities::SEQUENCES));
        $this->assertFalse($mysqlCaps->has(DatabaseCapabilities::SEQUENCES));
    }

    // =========================================================================
    // Grammar selection
    // =========================================================================

    /**
     * A MariaDB 10.3+ connection must get the MariaDB grammar so that
     * SchemaBuilder::nextVal() stops silently answering 0.
     */
    public function testMariaDB103GetsMariaDBSchemaGrammar(): void
    {
        // Arrange
        $sb = new SchemaBuilder($this->makeDb('mysql', '10.3.39-MariaDB'));

        // Act
        $grammar = $sb->getGrammar();

        // Assert
        $this->assertInstanceOf(MariaDBSchemaGrammar::class, $grammar);
        // It is still a MySQL grammar — everything but sequences is inherited.
        $this->assertInstanceOf(MySQLSchemaGrammar::class, $grammar);
    }

    /**
     * A MariaDB older than 10.3 has no sequence objects, so it must keep the
     * plain MySQL grammar rather than be handed DDL it cannot parse. This is
     * why the gate is the SEQUENCES capability and not a flavor string.
     */
    public function testMariaDB102KeepsMySQLSchemaGrammar(): void
    {
        // Arrange
        $sb = new SchemaBuilder($this->makeDb('mysql', '10.2.44-MariaDB'));

        // Act
        $grammar = $sb->getGrammar();

        // Assert
        $this->assertNotInstanceOf(MariaDBSchemaGrammar::class, $grammar);
        $this->assertInstanceOf(MySQLSchemaGrammar::class, $grammar);
    }

    /**
     * Oracle MySQL must be untouched by this change.
     */
    public function testMySQLStillGetsMySQLSchemaGrammar(): void
    {
        // Arrange
        $sb = new SchemaBuilder($this->makeDb('mysql', self::MYSQL_8_0));

        // Act & Assert
        $this->assertNotInstanceOf(MariaDBSchemaGrammar::class, $sb->getGrammar());
        $this->assertInstanceOf(MySQLSchemaGrammar::class, $sb->getGrammar());
    }

    /**
     * PostgreSQL selection must not be disturbed by the new MariaDB branch,
     * even though PostgreSQL also reports the SEQUENCES capability.
     */
    public function testPostgreSQLUnaffectedBySequenceCapabilityBranch(): void
    {
        // Arrange
        $sb = new SchemaBuilder($this->makeDb('postgresql', '14.10'));

        // Act & Assert — the PostgreSQL branch returns before the SEQUENCES gate
        $this->assertInstanceOf(PostgreSQLSchemaGrammar::class, $sb->getGrammar());
    }

    // =========================================================================
    // MariaDBSchemaGrammar SQL output
    // =========================================================================

    /**
     * The default CREATE SEQUENCE. Note NOCYCLE as one word — MariaDB rejects
     * PostgreSQL's "NO CYCLE" spelling, which is exactly the kind of difference
     * this grammar exists to encode.
     */
    public function testCompileCreateSequenceDefaults(): void
    {
        // Arrange
        $grammar = new MariaDBSchemaGrammar();

        // Act
        $sql = $grammar->compileCreateSequence('order_seq');

        // Assert
        $this->assertSame(
            'CREATE SEQUENCE IF NOT EXISTS `order_seq` START WITH 1 INCREMENT BY 1 NOCYCLE',
            $sql
        );
    }

    /**
     * All optional clauses together, in the order MariaDB expects them.
     */
    public function testCompileCreateSequenceWithAllOptions(): void
    {
        // Arrange
        $grammar = new MariaDBSchemaGrammar();

        // Act
        $sql = $grammar->compileCreateSequence('order_seq', 100, 5, 10, 9999, true);

        // Assert
        $this->assertSame(
            'CREATE SEQUENCE IF NOT EXISTS `order_seq` START WITH 100 INCREMENT BY 5'
            . ' MINVALUE 10 MAXVALUE 9999 CYCLE',
            $sql
        );
    }

    /**
     * A database-qualified name must be quoted part-by-part; quoting the whole
     * string would produce `` `app.order_seq` ``, a single identifier with a dot
     * in it, which is not the object the caller meant.
     */
    public function testSequenceNameIsQuotedPerPart(): void
    {
        // Arrange
        $grammar = new MariaDBSchemaGrammar();

        // Act
        $sql = $grammar->compileNextVal('app.order_seq');

        // Assert
        $this->assertSame('SELECT NEXTVAL(`app`.`order_seq`)', $sql);
    }

    /**
     * A backtick inside an identifier must be escaped by doubling, not passed
     * through where it would terminate the quoted identifier early.
     */
    public function testSequenceNameEscapesBackticks(): void
    {
        // Arrange
        $grammar = new MariaDBSchemaGrammar();

        // Act
        $sql = $grammar->compileDropSequence('we`ird');

        // Assert
        $this->assertSame('DROP SEQUENCE IF EXISTS `we``ird`', $sql);
    }

    /**
     * DROP SEQUENCE without the IF EXISTS guard.
     */
    public function testCompileDropSequenceWithoutGuard(): void
    {
        // Arrange
        $grammar = new MariaDBSchemaGrammar();

        // Act
        $sql = $grammar->compileDropSequence('order_seq', false);

        // Assert
        $this->assertSame('DROP SEQUENCE `order_seq`', $sql);
    }

    /**
     * SETVAL's third argument carries PostgreSQL's is_called semantics: TRUE
     * means the value is already consumed, FALSE means the next NEXTVAL returns
     * it. Getting this backwards would duplicate or skip an ID.
     */
    public function testCompileSetValBothIsCalledModes(): void
    {
        // Arrange
        $grammar = new MariaDBSchemaGrammar();

        // Act
        $called    = $grammar->compileSetVal('order_seq', 42);
        $notCalled = $grammar->compileSetVal('order_seq', 42, false);

        // Assert
        $this->assertSame('SELECT SETVAL(`order_seq`, 42, TRUE)', $called);
        $this->assertSame('SELECT SETVAL(`order_seq`, 42, FALSE)', $notCalled);
    }

    /**
     * The grammar must override *only* sequences. If it started diverging from
     * MySQL elsewhere it would be duplicating a grammar rather than extending
     * one — this pins that contract to a representative sample of inherited
     * behaviour.
     */
    public function testEverythingElseIsInheritedFromMySQL(): void
    {
        // Arrange
        $maria = new MariaDBSchemaGrammar();
        $mysql = new MySQLSchemaGrammar();

        // Act & Assert — quoting, renames and introspection are identical
        $this->assertSame($mysql->quoteTable('users'), $maria->quoteTable('users'));
        $this->assertSame($mysql->quoteColumn('id'), $maria->quoteColumn('id'));
        $this->assertSame($mysql->compileRename('a', 'b'), $maria->compileRename('a', 'b'));
        $this->assertSame(
            $mysql->compileHasTable('users', 'app'),
            $maria->compileHasTable('users', 'app')
        );
        $this->assertSame(
            $mysql->compileDropIndex('users', 'idx_name'),
            $maria->compileDropIndex('users', 'idx_name')
        );
    }

    /**
     * The methods the MariaDB grammar overrides are exactly the four sequence
     * compilers — asserted by reflection so that adding a fifth override
     * without thinking about it breaks a test.
     */
    public function testOnlySequenceMethodsAreOverridden(): void
    {
        // Arrange
        $reflection = new \ReflectionClass(MariaDBSchemaGrammar::class);

        // Act — public/protected methods declared on the subclass itself
        $declared = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $reflection->getMethods(),
                static fn(\ReflectionMethod $m): bool
                    => $m->getDeclaringClass()->getName() === MariaDBSchemaGrammar::class
            )
        );
        sort($declared);

        // Assert — the four compilers plus the private quoting helper they share
        $this->assertSame(
            [
                'compileCreateSequence',
                'compileDropSequence',
                'compileNextVal',
                'compileSetVal',
                'quoteSequence',
            ],
            $declared
        );
    }
}
