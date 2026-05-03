<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\Result;

class DatabaseCapabilitiesTest extends TestCase
{
    private function makeDb(string $type = 'mysql', bool $timescale = false): Database
    {
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->type      = $type;
        $db->timescale = $timescale;
        return $db;
    }

    private function makeResult(int $numRows): Result
    {
        $stub = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $result          = new Result($stub);
        $result->numRows = $numRows;
        return $result;
    }

    // -------------------------------------------------------------------------
    // ENGINE_MYSQL
    // -------------------------------------------------------------------------

    public function testHasMySQLTrueWhenTypeIsMySQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has(DatabaseCapabilities::ENGINE_MYSQL)
        );
    }

    public function testHasMySQLFalseWhenTypeIsPostgreSQL(): void
    {
        $this->assertFalse(
            (new DatabaseCapabilities($this->makeDb('postgresql')))->has(DatabaseCapabilities::ENGINE_MYSQL)
        );
    }

    // -------------------------------------------------------------------------
    // ENGINE_POSTGRESQL
    // -------------------------------------------------------------------------

    public function testHasPostgreSQLTrueWhenTypeIsPostgreSQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('postgresql')))->has(DatabaseCapabilities::ENGINE_POSTGRESQL)
        );
    }

    public function testHasPostgreSQLFalseWhenTypeIsMySQL(): void
    {
        $this->assertFalse(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has(DatabaseCapabilities::ENGINE_POSTGRESQL)
        );
    }

    // -------------------------------------------------------------------------
    // FEATURE_TIMESCALEDB
    // -------------------------------------------------------------------------

    public function testTimescaleDBFalseOnMySQL(): void
    {
        $this->assertFalse(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has(DatabaseCapabilities::FEATURE_TIMESCALEDB)
        );
    }

    public function testTimescaleDBTrueWhenTimescaleFlagSet(): void
    {
        $caps = new DatabaseCapabilities($this->makeDb('postgresql', true));
        $this->assertTrue($caps->has(DatabaseCapabilities::FEATURE_TIMESCALEDB));
    }

    public function testTimescaleDBTrueWhenQueryReturnsRows(): void
    {
        $db = $this->makeDb('postgresql', false);
        $db->expects($this->once())
            ->method('query')
            ->willReturn($this->makeResult(1));

        $caps = new DatabaseCapabilities($db);
        $this->assertTrue($caps->has(DatabaseCapabilities::FEATURE_TIMESCALEDB));
    }

    public function testTimescaleDBFalseWhenQueryReturnsNoRows(): void
    {
        $db = $this->makeDb('postgresql', false);
        $db->expects($this->once())
            ->method('query')
            ->willReturn($this->makeResult(0));

        $caps = new DatabaseCapabilities($db);
        $this->assertFalse($caps->has(DatabaseCapabilities::FEATURE_TIMESCALEDB));
    }

    public function testTimescaleDBFalseWhenQueryReturnsNull(): void
    {
        $db = $this->makeDb('postgresql', false);
        $db->expects($this->once())
            ->method('query')
            ->willReturn(null);

        $caps = new DatabaseCapabilities($db);
        $this->assertFalse($caps->has(DatabaseCapabilities::FEATURE_TIMESCALEDB));
    }

    public function testTimescaleDBResultIsCached(): void
    {
        $db = $this->makeDb('postgresql', false);
        // query() must be called exactly once even when has() is called twice
        $db->expects($this->once())
            ->method('query')
            ->willReturn($this->makeResult(1));

        $caps = new DatabaseCapabilities($db);
        $caps->has(DatabaseCapabilities::FEATURE_TIMESCALEDB);
        $caps->has(DatabaseCapabilities::FEATURE_TIMESCALEDB); // second call: must use cache
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // FEATURE_JSON
    // -------------------------------------------------------------------------

    public function testJsonSupportedOnMySQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has(DatabaseCapabilities::FEATURE_JSON)
        );
    }

    public function testJsonSupportedOnPostgreSQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('postgresql')))->has(DatabaseCapabilities::FEATURE_JSON)
        );
    }

    // -------------------------------------------------------------------------
    // FEATURE_JSONB
    // -------------------------------------------------------------------------

    public function testJsonbSupportedOnPostgreSQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('postgresql')))->has(DatabaseCapabilities::FEATURE_JSONB)
        );
    }

    public function testJsonbNotSupportedOnMySQL(): void
    {
        $this->assertFalse(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has(DatabaseCapabilities::FEATURE_JSONB)
        );
    }

    // -------------------------------------------------------------------------
    // FEATURE_FULLTEXT
    // -------------------------------------------------------------------------

    public function testFulltextSupportedOnMySQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has(DatabaseCapabilities::FEATURE_FULLTEXT)
        );
    }

    public function testFulltextSupportedOnPostgreSQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('postgresql')))->has(DatabaseCapabilities::FEATURE_FULLTEXT)
        );
    }

    // -------------------------------------------------------------------------
    // FEATURE_SPATIAL
    // -------------------------------------------------------------------------

    public function testSpatialSupportedOnMySQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has(DatabaseCapabilities::FEATURE_SPATIAL)
        );
    }

    public function testSpatialSupportedOnPostgreSQL(): void
    {
        $this->assertTrue(
            (new DatabaseCapabilities($this->makeDb('postgresql')))->has(DatabaseCapabilities::FEATURE_SPATIAL)
        );
    }

    // -------------------------------------------------------------------------
    // Unknown feature
    // -------------------------------------------------------------------------

    public function testUnknownFeatureReturnsFalse(): void
    {
        $this->assertFalse(
            (new DatabaseCapabilities($this->makeDb('mysql')))->has('unknown_feature_xyz')
        );
    }

    // -------------------------------------------------------------------------
    // Convenience methods
    // -------------------------------------------------------------------------

    public function testIsMySQLTrue(): void
    {
        $this->assertTrue((new DatabaseCapabilities($this->makeDb('mysql')))->isMySQL());
    }

    public function testIsMySQLFalse(): void
    {
        $this->assertFalse((new DatabaseCapabilities($this->makeDb('postgresql')))->isMySQL());
    }

    public function testIsPostgreSQLTrue(): void
    {
        $this->assertTrue((new DatabaseCapabilities($this->makeDb('postgresql')))->isPostgreSQL());
    }

    public function testIsPostgreSQLFalse(): void
    {
        $this->assertFalse((new DatabaseCapabilities($this->makeDb('mysql')))->isPostgreSQL());
    }

    public function testHasTimescaleDBDelegatesToHas(): void
    {
        $caps = new DatabaseCapabilities($this->makeDb('postgresql', true));
        $this->assertTrue($caps->hasTimescaleDB());
    }

    // -------------------------------------------------------------------------
    // ifCapable
    // -------------------------------------------------------------------------

    public function testIfCapableCallsIfTrueWhenCapabilityPresent(): void
    {
        $caps   = new DatabaseCapabilities($this->makeDb('mysql'));
        $called = false;
        $caps->ifCapable(DatabaseCapabilities::ENGINE_MYSQL, function ($db) use (&$called) {
            $called = true;
        });
        $this->assertTrue($called);
    }

    public function testIfCapableCallsIfFalseWhenCapabilityAbsent(): void
    {
        $caps        = new DatabaseCapabilities($this->makeDb('mysql'));
        $falseCalled = false;
        $caps->ifCapable(
            DatabaseCapabilities::ENGINE_POSTGRESQL,
            function ($db) {},
            function ($db) use (&$falseCalled) { $falseCalled = true; }
        );
        $this->assertTrue($falseCalled);
    }

    public function testIfCapableReturnsNullWhenAbsentAndNoFallback(): void
    {
        $caps   = new DatabaseCapabilities($this->makeDb('mysql'));
        $result = $caps->ifCapable(
            DatabaseCapabilities::ENGINE_POSTGRESQL,
            function ($db) { return 'yes'; }
        );
        $this->assertNull($result);
    }

    public function testIfCapableReturnsCallbackResult(): void
    {
        $caps   = new DatabaseCapabilities($this->makeDb('mysql'));
        $result = $caps->ifCapable(
            DatabaseCapabilities::ENGINE_MYSQL,
            function ($db) { return 'mysql_result'; }
        );
        $this->assertEquals('mysql_result', $result);
    }

    public function testIfCapablePassesDatabaseToCallback(): void
    {
        $db   = $this->makeDb('mysql');
        $caps = new DatabaseCapabilities($db);
        $received = null;
        $caps->ifCapable(
            DatabaseCapabilities::ENGINE_MYSQL,
            function ($passedDb) use (&$received) { $received = $passedDb; }
        );
        $this->assertSame($db, $received);
    }
}
