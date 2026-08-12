<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\Grammar\MariaDBSchemaGrammar;
use Pramnos\Database\Grammar\MySQLSchemaGrammar;
use Pramnos\Database\Grammar\PostgreSQLSchemaGrammar;

/**
 * Integration tests for server flavor / version detection against the real
 * database containers.
 *
 * The unit tests inject a version string; only a live connection can prove that
 * the *driver-specific* lookups (mysqli_get_server_info() and pg_version())
 * actually return something parseable, and that the capability matrix resolves
 * correctly against the servers the project really runs on.
 *
 * There is no MariaDB container in docker-compose.yml, so the MariaDB branch is
 * covered by unit tests only; what these tests pin down is that MySQL 8.0 and
 * PostgreSQL 14 are *not* misidentified as MariaDB and that their capability
 * answers are the expected ones.
 *
 * Requires the Docker MySQL (host: db) and TimescaleDB/PostgreSQL
 * (host: timescaledb) containers.
 */
class ServerFlavorDetectionTest extends TestCase
{
    /**
     * Open a live MySQL connection to the test container.
     *
     * @return Database
     */
    private function mysql(): Database
    {
        $db = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 3306;
        $db->connect(true);

        return $db;
    }

    /**
     * Open a live PostgreSQL connection to the test container.
     *
     * @return Database
     */
    private function postgres(): Database
    {
        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->connect(true);

        return $db;
    }

    // =========================================================================
    // Version lookup through the real drivers
    // =========================================================================

    /**
     * mysqli_get_server_info() must yield a parseable version. If this ever
     * returned an empty string every version-gated capability would silently
     * answer false, which is the failure mode most likely to go unnoticed.
     */
    public function testMySQLReportsAParseableServerVersion(): void
    {
        // Arrange
        $db   = $this->mysql();
        $caps = new DatabaseCapabilities($db);

        // Act
        $raw        = $db->getServerVersion();
        $normalised = $caps->getVersion();

        // Assert
        $this->assertNotSame('', $raw, 'the driver must report a version string');
        // A dotted numeric version is what version_compare() needs.
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)+$/', $normalised);
        $this->assertTrue($caps->atLeast('5.7'));
    }

    /**
     * pg_version() must likewise yield a parseable server version.
     */
    public function testPostgreSQLReportsAParseableServerVersion(): void
    {
        // Arrange
        $db   = $this->postgres();
        $caps = new DatabaseCapabilities($db);

        // Act
        $normalised = $caps->getVersion();

        // Assert
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)*$/', $normalised);
        $this->assertTrue($caps->atLeast('12'));
    }

    /**
     * The version is looked up once and memoised for the life of the
     * connection — it cannot change under a live link.
     */
    public function testServerVersionIsStableAcrossCalls(): void
    {
        // Arrange
        $db = $this->mysql();

        // Act
        $first  = $db->getServerVersion();
        $second = $db->getServerVersion();

        // Assert
        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Flavor detection against real servers
    // =========================================================================

    /**
     * The Docker MySQL 8.0 image must be identified as MySQL, not MariaDB —
     * a false positive here would hand it MariaDB-only sequence DDL that it
     * cannot parse.
     */
    public function testMySQLContainerIsNotDetectedAsMariaDB(): void
    {
        // Arrange
        $db   = $this->mysql();
        $caps = new DatabaseCapabilities($db);

        // Act & Assert
        $this->assertFalse($db->isMariaDB());
        $this->assertFalse($caps->isMariaDB());
        $this->assertTrue($caps->isMySQL()); // still in the MySQL family
    }

    /**
     * PostgreSQL is never MariaDB, and asking must not disturb the connection.
     */
    public function testPostgreSQLContainerIsNotDetectedAsMariaDB(): void
    {
        // Arrange
        $db   = $this->postgres();
        $caps = new DatabaseCapabilities($db);

        // Act & Assert
        $this->assertFalse($caps->isMariaDB());
        $this->assertFalse($caps->isMySQL());
        $this->assertTrue($caps->isPostgreSQL());
    }

    // =========================================================================
    // Capability matrix against real servers
    // =========================================================================

    /**
     * MySQL 8.0: no sequences, no RETURNING, native JSON since 5.7.8, enforced
     * CHECK constraints since 8.0.16. These are the answers the new constants
     * must give on the server the framework's MySQL suite actually runs on.
     */
    public function testCapabilityMatrixOnRealMySQL(): void
    {
        // Arrange
        $caps = new DatabaseCapabilities($this->mysql());

        // Act & Assert
        $this->assertFalse($caps->hasSequences());
        $this->assertFalse($caps->hasReturning());
        $this->assertTrue($caps->hasNativeJson());
        $this->assertTrue($caps->hasCheckConstraints());
    }

    /**
     * PostgreSQL answers yes to all four.
     */
    public function testCapabilityMatrixOnRealPostgreSQL(): void
    {
        // Arrange
        $caps = new DatabaseCapabilities($this->postgres());

        // Act & Assert
        $this->assertTrue($caps->hasSequences());
        $this->assertTrue($caps->hasReturning());
        $this->assertTrue($caps->hasNativeJson());
        $this->assertTrue($caps->hasCheckConstraints());
    }

    // =========================================================================
    // Grammar selection against real servers
    // =========================================================================

    /**
     * The new MariaDB grammar branch must not change which grammar the existing
     * containers get — this is the regression guard for the SchemaBuilder wiring.
     */
    public function testGrammarSelectionIsUnchangedForExistingServers(): void
    {
        // Arrange
        $mysqlSchema    = $this->mysql()->schema();
        $postgresSchema = $this->postgres()->schema();

        // Act
        $mysqlGrammar    = $mysqlSchema->getGrammar();
        $postgresGrammar = $postgresSchema->getGrammar();

        // Assert
        $this->assertInstanceOf(MySQLSchemaGrammar::class, $mysqlGrammar);
        $this->assertNotInstanceOf(MariaDBSchemaGrammar::class, $mysqlGrammar);
        $this->assertInstanceOf(PostgreSQLSchemaGrammar::class, $postgresGrammar);
    }

    /**
     * Sequences remain a documented no-op on MySQL: nextVal() answers 0 rather
     * than throwing. The MariaDB work must not have changed that contract.
     */
    public function testSequencesStillNoOpOnRealMySQL(): void
    {
        // Arrange
        $schema = $this->mysql()->schema();

        // Act
        $schema->createSequence('flavor_probe_seq'); // silently ignored
        $next = $schema->nextVal('flavor_probe_seq');

        // Assert — 0 is the documented "unsupported" answer
        $this->assertSame(0, $next);
    }
}
