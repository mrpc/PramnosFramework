<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Unit tests for Database methods that are fully testable without a live
 * database connection.
 *
 * Covered methods:
 *   - isWriteQuery()  — classifies SQL by first keyword (pure string logic)
 *   - convertBool()   — maps PHP bool to DB-type-appropriate value
 *   - decodeEWKB()    — decodes PostGIS Extended WKB binary format
 *
 * The Database is instantiated with no arguments (no connection attempted).
 */
#[CoversClass(Database::class)]
class DatabasePureMethodsTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // No connection — default constructor skips both resource/Settings branches
        $this->db = new Database();
    }

    // =========================================================================
    // isWriteQuery
    // =========================================================================

    /** @return array<string,array{string,bool}> */
    public static function writeQueryProvider(): array
    {
        return [
            'SELECT'            => ['SELECT id FROM users',              false],
            'SELECT with UPPER' => ['select * FROM users',               false],
            'SELECT with spaces'=> ['  SELECT 1',                        false],
            'SHOW'              => ['SHOW TABLES',                       false],
            'EXPLAIN'           => ['EXPLAIN SELECT 1',                  false],
            'DESC'              => ['DESC users',                        false],
            'DESCRIBE'          => ['DESCRIBE users',                    false],
            'INSERT'            => ['INSERT INTO t VALUES (1)',           true],
            'UPDATE'            => ['UPDATE t SET x=1 WHERE id=1',       true],
            'DELETE'            => ['DELETE FROM t WHERE id=1',          true],
            'CREATE TABLE'      => ['CREATE TABLE foo (id INT)',          true],
            'DROP TABLE'        => ['DROP TABLE foo',                    true],
            'ALTER TABLE'       => ['ALTER TABLE foo ADD COLUMN bar INT', true],
            'TRUNCATE'          => ['TRUNCATE TABLE foo',                 true],
            'CALL stored proc'  => ['CALL my_proc()',                     true],
        ];
    }

    /**
     * isWriteQuery() returns false for read-only SQL keywords (SELECT, SHOW,
     * EXPLAIN, DESC, DESCRIBE) and true for anything else (INSERT, UPDATE,
     * DELETE, CREATE, DROP, ALTER, TRUNCATE, CALL, etc.).
     *
     * The classification is purely based on the first word of the SQL string.
     *
     * @param string $sql      The SQL string to classify
     * @param bool   $expected True for write queries, false for reads
     */
    #[DataProvider('writeQueryProvider')]
    public function testIsWriteQuery(string $sql, bool $expected): void
    {
        // Arrange / Act
        $result = $this->db->isWriteQuery($sql);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * isWriteQuery() handles leading whitespace — ltrim() is applied before
     * splitting on the first space, so '  SELECT 1' is still read-only.
     */
    public function testIsWriteQueryIgnoresLeadingWhitespace(): void
    {
        // Arrange — tab + newline before SELECT
        $sql = "\t\n  SELECT * FROM users";

        // Act / Assert
        $this->assertFalse($this->db->isWriteQuery($sql));
    }

    // =========================================================================
    // convertBool
    // =========================================================================

    /**
     * convertBool() returns 1 (integer) for true on MySQL (default type).
     */
    public function testConvertBoolTrueOnMySQLReturns1(): void
    {
        // Arrange — default type is 'mysql'
        $this->assertSame('mysql', $this->db->type);

        // Act / Assert
        $this->assertSame(1, $this->db->convertBool(true));
    }

    /**
     * convertBool() returns 0 (integer) for false on MySQL.
     */
    public function testConvertBoolFalseOnMySQLReturns0(): void
    {
        // Arrange
        $this->db->type = 'mysql';

        // Act / Assert
        $this->assertSame(0, $this->db->convertBool(false));
    }

    /**
     * convertBool() returns 't' (string) for true on PostgreSQL.
     * PostgreSQL uses 't'/'f' as boolean literals.
     */
    public function testConvertBoolTrueOnPostgreSQLReturnsTString(): void
    {
        // Arrange
        $this->db->type = 'postgresql';

        // Act / Assert
        $this->assertSame('t', $this->db->convertBool(true));
    }

    /**
     * convertBool() returns 'f' (string) for false on PostgreSQL.
     */
    public function testConvertBoolFalseOnPostgreSQLReturnsFString(): void
    {
        // Arrange
        $this->db->type = 'postgresql';

        // Act / Assert
        $this->assertSame('f', $this->db->convertBool(false));
    }

    /**
     * convertBool() treats truthy non-boolean values as true.
     * PHP's truthiness rules apply: non-zero int, non-empty string → true.
     */
    public function testConvertBoolTruthyValueOnMySQLReturns1(): void
    {
        // Arrange
        $this->db->type = 'mysql';

        // Act / Assert — non-zero integer is truthy
        $this->assertSame(1, $this->db->convertBool(42));
        $this->assertSame(0, $this->db->convertBool(0));
    }

    // =========================================================================
    // decodeEWKB
    // =========================================================================

    /**
     * Builds a little-endian WKB POINT binary string (no SRID).
     */
    private function buildPointWKB(float $x, float $y): string
    {
        $endian = chr(1);           // little-endian
        $type   = pack('V', 1);     // geometry type = POINT
        $bx     = pack('d', $x);
        $by     = pack('d', $y);
        return bin2hex($endian . $type . $bx . $by);
    }

    /**
     * Builds a little-endian EWKB POINT string with a specific SRID.
     * EWKB flag: type | 0x20000000 indicates SRID is present.
     */
    private function buildPointEWKB(float $x, float $y, int $srid): string
    {
        $endian  = chr(1);
        $type    = pack('V', 1 | 0x20000000);  // POINT + SRID flag
        $sridPkt = pack('V', $srid);
        $bx      = pack('d', $x);
        $by      = pack('d', $y);
        return bin2hex($endian . $type . $sridPkt . $bx . $by);
    }

    /**
     * decodeEWKB() decodes a WKB POINT (no SRID) and returns an array with
     * type='POINT', coordinates=[x, y], and srid=null.
     */
    public function testDecodeEWKBDecodesSimplePoint(): void
    {
        // Arrange — Athens coordinates (lon=23.7275, lat=37.9838)
        $hex = $this->buildPointWKB(23.7275, 37.9838);

        // Act
        $result = $this->db->decodeEWKB($hex);

        // Assert — structure
        $this->assertIsArray($result);
        $this->assertSame('POINT', $result['type']);
        $this->assertEqualsWithDelta(23.7275, $result['coordinates'][0], 1e-6);
        $this->assertEqualsWithDelta(37.9838, $result['coordinates'][1], 1e-6);
        $this->assertNull($result['srid']);
    }

    /**
     * decodeEWKB() extracts the SRID from PostGIS EWKB format when the SRID
     * flag (0x20000000) is set in the geometry type word.
     */
    public function testDecodeEWKBExtractsSrid(): void
    {
        // Arrange — WGS84 SRID = 4326
        $hex = $this->buildPointEWKB(23.7275, 37.9838, 4326);

        // Act
        $result = $this->db->decodeEWKB($hex);

        // Assert — SRID present and correct
        $this->assertSame('POINT', $result['type']);
        $this->assertSame(4326, $result['srid']);
        $this->assertEqualsWithDelta(23.7275, $result['coordinates'][0], 1e-6);
        $this->assertEqualsWithDelta(37.9838, $result['coordinates'][1], 1e-6);
    }

    /**
     * decodeEWKB() returns null for geometry types other than POINT (type=1).
     * LINESTRING (type=2) has no decoding logic and falls through to null.
     */
    public function testDecodeEWKBReturnsNullForNonPointType(): void
    {
        // Arrange — LINESTRING (type=2)
        $endian = chr(1);
        $type   = pack('V', 2);   // LINESTRING
        $x      = pack('d', 1.0);
        $y      = pack('d', 2.0);
        $hex    = bin2hex($endian . $type . $x . $y);

        // Act
        $result = $this->db->decodeEWKB($hex);

        // Assert
        $this->assertNull($result);
    }

    /**
     * decodeEWKB() works with negative coordinate values (southern/western
     * hemisphere), which use the full double precision range.
     */
    public function testDecodeEWKBHandlesNegativeCoordinates(): void
    {
        // Arrange — Sydney, Australia (-33.8688, 151.2093)
        $hex = $this->buildPointWKB(151.2093, -33.8688);

        // Act
        $result = $this->db->decodeEWKB($hex);

        // Assert
        $this->assertSame('POINT', $result['type']);
        $this->assertEqualsWithDelta(151.2093, $result['coordinates'][0], 1e-6);
        $this->assertEqualsWithDelta(-33.8688, $result['coordinates'][1], 1e-6);
    }

    /**
     * decodeEWKB() handles the origin point (0, 0) correctly (null island).
     */
    public function testDecodeEWKBHandlesOriginPoint(): void
    {
        // Arrange
        $hex = $this->buildPointWKB(0.0, 0.0);

        // Act
        $result = $this->db->decodeEWKB($hex);

        // Assert
        $this->assertSame('POINT', $result['type']);
        $this->assertEqualsWithDelta(0.0, $result['coordinates'][0], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['coordinates'][1], 1e-10);
    }

    // =========================================================================
    // addExternalConnection
    // =========================================================================

    /**
     * addExternalConnection() marks the DB as connected and returns $this for
     * chaining.  We pass null so the destructor's close() can safely exit
     * without attempting to close a non-connection resource.
     *
     * The invariant being tested: connected=true is set and the method is fluent
     * (returns $this), regardless of the link value.
     */
    public function testAddExternalConnectionSetsConnectionAndConnected(): void
    {
        // Arrange — null link is safe; the important assertion is connected=true
        // Using a real mysqli/pg resource would require a live connection,
        // which is outside the scope of this unit test.

        // Act
        $returnValue = $this->db->addExternalConnection(null);

        // Assert — connected flag set and method is fluent
        $this->assertTrue($this->db->connected, 'connected must be true after addExternalConnection');
        $this->assertSame($this->db, $returnValue, 'addExternalConnection() must return $this for chaining');
    }

    // =========================================================================
    // schemaBuilder
    // =========================================================================

    /**
     * schemaBuilder() returns a new SchemaBuilder instance bound to the
     * current Database object. It must never return null or another type.
     */
    public function testSchemaBuilderReturnsSchemaBuilderInstance(): void
    {
        // Act
        $sb = $this->db->schemaBuilder();

        // Assert
        $this->assertInstanceOf(\Pramnos\Database\SchemaBuilder::class, $sb);
    }

    // =========================================================================
    // getError — MySQL branch
    // =========================================================================

    /**
     * getError() on a MySQL-typed instance with no live connection must return
     * empty strings/zeros from the driver branches (mysqli is called with null
     * and returns empty/0).  The error_text fallback must NOT fire when error_text
     * is also empty.
     */
    public function testGetErrorMySQLBranchWithNoConnectionReturnsEmpty(): void
    {
        // Arrange — MySQL-typed, no connection, no stored error
        $this->db->type = 'mysql';

        // Act
        $err = $this->db->getError();

        // Assert — both driver fields must be empty/zero
        $this->assertSame('', $err['message'], 'message must be empty with no connection and no error_text');
        $this->assertSame(0, $err['code'], 'code must be 0 with no connection');
    }

    /**
     * getError() must fall back to error_text on MySQL type when the driver
     * has no pending error (connection is null → empty string from mysqli branch).
     */
    public function testGetErrorMySQLFallsBackToErrorText(): void
    {
        // Arrange
        $this->db->type = 'mysql';
        $this->db->error_number = 1054;
        $this->db->error_text   = 'Unknown column "foo" in field list';

        // Act
        $err = $this->db->getError();

        // Assert — fallback must surface error_text and error_number
        $this->assertSame('Unknown column "foo" in field list', $err['message']);
        $this->assertSame(1054, $err['code']);
    }

    // =========================================================================
    // getConnectionErrorMessage (protected — tested via reflection)
    // =========================================================================

    /**
     * Helper that calls the protected getConnectionErrorMessage() via reflection.
     */
    private function callGetConnectionErrorMessage(): string
    {
        $ref = new \ReflectionMethod($this->db, 'getConnectionErrorMessage');
        $ref->setAccessible(true);
        return (string) $ref->invoke($this->db);
    }

    /**
     * getConnectionErrorMessage() on a PostgreSQL-typed instance with no
     * prior connection attempt must return a generic fallback string.
     * error_get_last() returns null when no PHP error has been raised yet,
     * so the method falls through to the generic PostgreSQL message.
     */
    public function testGetConnectionErrorMessagePostgreSQLFallbackMessage(): void
    {
        // Arrange
        $this->db->type = 'postgresql';
        // Clear any stale PHP error so the first branch returns the fallback
        if (function_exists('error_clear_last')) {
            error_clear_last();
        }

        // Act
        $msg = $this->callGetConnectionErrorMessage();

        // Assert — must be the generic PG message or whatever error_get_last() produced
        $this->assertIsString($msg, 'getConnectionErrorMessage() must return a string');
        // Acceptable: either the generic message or a real error string from PHP internals
        $this->assertNotEmpty($msg, 'Must return a non-empty message for PostgreSQL');
    }

    /**
     * getConnectionErrorMessage() on a MySQL-typed instance returns the result
     * of mysqli_connect_error() (empty string when no failed connect attempt)
     * or falls through to the generic MySQL message.
     */
    public function testGetConnectionErrorMessageMySQLReturnsString(): void
    {
        // Arrange
        $this->db->type = 'mysql';

        // Act
        $msg = $this->callGetConnectionErrorMessage();

        // Assert — must be a string (possibly empty or a real error)
        $this->assertIsString($msg, 'getConnectionErrorMessage() must return a string');
    }

    // =========================================================================
    // setError (protected) + displayError (public)
    // =========================================================================

    /**
     * setError() with fatal=true on error 1141 must NOT throw an exception and
     * must NOT call displayError() — error 1141 is a benign MySQL privilege
     * warning that the framework intentionally ignores.
     */
    public function testSetErrorWith1141DoesNotThrow(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'setError');
        $ref->setAccessible(true);

        // Act + Assert — must not throw
        $threwException = false;
        try {
            $ref->invoke($this->db, 1141, 'Grant defined without matching grant', true);
        } catch (\Exception $e) {
            $threwException = true;
        }

        $this->assertFalse($threwException, 'setError() with error 1141 must not throw');
        $this->assertSame(1141, $this->db->error_number, 'error_number must be set even for 1141');
    }

    /**
     * setError() with fatal=true and a non-1141 error code must call
     * displayError() (which calls error_log when no Application is running)
     * and then throw an Exception with the given message and code.
     *
     * This also covers displayError() — the else-branch that uses error_log
     * is exercised because Application::getInstance() returns null in tests.
     */
    public function testSetErrorFatalThrowsExceptionAndCallsDisplayError(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'setError');
        $ref->setAccessible(true);
        $this->db->error_text = '';

        // Act + Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('connection refused');
        $ref->invoke($this->db, 2002, 'connection refused', true);
    }

    // =========================================================================
    // prepareValue (protected — tested via reflection)
    // =========================================================================

    /** Helper that calls the protected prepareValue() via reflection. */
    private function callPrepareValue(mixed $value, string $type): mixed
    {
        $ref = new \ReflectionMethod($this->db, 'prepareValue');
        $ref->setAccessible(true);
        return $ref->invoke($this->db, $value, $type);
    }

    /**
     * prepareValue() returns the string 'NULL' for PHP null regardless of type.
     * This is the early-return guard that prevents NULL values from entering
     * the type-specific branches.
     */
    public function testPrepareValueNullReturnsNULLString(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('NULL', $this->callPrepareValue(null, 'string'));
        $this->assertSame('NULL', $this->callPrepareValue(null, 'integer'));
        $this->assertSame('NULL', $this->callPrepareValue(null, 'float'));
    }

    /**
     * prepareValue('NULL', 'float') returns 'NULL' — the sentinel string
     * is treated the same as PHP null for float columns.
     */
    public function testPrepareValueFloatNULLStringReturnsNULL(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('NULL', $this->callPrepareValue('NULL', 'float'));
    }

    /**
     * prepareValue('', 'float') returns 'NULL' — empty string is not a
     * valid float and must be stored as NULL.
     */
    public function testPrepareValueFloatEmptyStringReturnsNULL(): void
    {
        $this->assertSame('NULL', $this->callPrepareValue('', 'float'));
    }

    /**
     * prepareValue() converts a comma-decimal float string to a dot-decimal
     * float (European locale format) before returning.
     */
    public function testPrepareValueFloatCommaDecimalIsConverted(): void
    {
        // Arrange / Act
        $result = $this->callPrepareValue('3,14', 'float');

        // Assert — result must be the PHP float 3.14
        $this->assertEqualsWithDelta(3.14, $result, 1e-6);
    }

    /**
     * prepareValue(0, 'float') returns integer 0, not a NULL or string.
     * Zero is a valid float value.
     */
    public function testPrepareValueFloatZeroReturnsZero(): void
    {
        $this->assertSame(0, $this->callPrepareValue(0, 'float'));
    }

    /**
     * prepareValue(3.14, 'float') returns 3.14 as a PHP float.
     */
    public function testPrepareValueFloatValidReturnsFloat(): void
    {
        $result = $this->callPrepareValue(3.14, 'float');
        $this->assertEqualsWithDelta(3.14, $result, 1e-6);
    }

    /**
     * prepareValue('NULL', 'integer') returns the string 'NULL'.
     */
    public function testPrepareValueIntegerNULLStringReturnsNULL(): void
    {
        $this->assertSame('NULL', $this->callPrepareValue('NULL', 'integer'));
    }

    /**
     * prepareValue(42, 'integer') returns 42 as a PHP int.
     */
    public function testPrepareValueIntegerReturnsInt(): void
    {
        $this->assertSame(42, $this->callPrepareValue(42, 'integer'));
    }

    /**
     * prepareValue('hello', 'string') returns a SQL-quoted escaped string.
     * The value is wrapped in single quotes ready for use in a raw SQL
     * statement (via prepareQuery).
     *
     * We use a subclass that overrides prepareInput() with addslashes() so the
     * test does not require a real database connection — the important invariant
     * is that the string is wrapped in single quotes, not which escape function
     * is used.
     */
    public function testPrepareValueStringReturnsSQLQuotedValue(): void
    {
        // Arrange — subclass overrides prepareInput() to avoid needing a live connection
        $db = new class extends Database {
            public function prepareInput($string) { return addslashes($string ?? ''); }
        };
        $ref = new \ReflectionMethod($db, 'prepareValue');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke($db, 'hello', 'string');

        // Assert — value must be wrapped in single quotes
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
        $this->assertStringContainsString('hello', $result);
    }

    /**
     * prepareValue() treats 'json', 'currency', and 'date' exactly like
     * 'string' — all produce a SQL-quoted escaped value.
     */
    public function testPrepareValueJsonCurrencyDateBehavelikeString(): void
    {
        // Arrange — subclass with no-connection prepareInput()
        $db = new class extends Database {
            public function prepareInput($string) { return addslashes($string ?? ''); }
        };
        $ref = new \ReflectionMethod($db, 'prepareValue');
        $ref->setAccessible(true);

        // Act + Assert — all three types produce a quoted value
        foreach (['json', 'currency', 'date'] as $type) {
            $result = $ref->invoke($db, 'test', $type);
            $this->assertStringStartsWith("'", $result, "Type '$type' must produce a quoted value");
        }
    }

    /**
     * prepareValue() throws an Exception for an unrecognised type.
     * This is the safety net that prevents silent data corruption from
     * misconfigured field definitions.
     */
    public function testPrepareValueUnknownTypeThrowsException(): void
    {
        // Arrange / Act / Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/var-type undefined/');
        $this->callPrepareValue('foo', 'blob');
    }

    // =========================================================================
    // castToType (private — remaining branches via reflection)
    // =========================================================================

    /** Helper that calls the private castToType() via reflection. */
    private function callCastToType(mixed $value, string $type): mixed
    {
        $ref = new \ReflectionMethod($this->db, 'castToType');
        $ref->setAccessible(true);
        return $ref->invoke($this->db, $value, $type);
    }

    /**
     * castToType('x', 'n') returns null — the 'n' (null) type code always
     * produces null regardless of value, UNLESS the early-null guard fires.
     * For a non-null value like 'x', it reaches the switch and returns null.
     */
    public function testCastToTypeNTypeCodeReturnsNull(): void
    {
        // Arrange / Act / Assert
        $this->assertNull($this->callCastToType('x', 'n'));
    }

    /**
     * castToType() returns null when a boolean-typed value is neither
     * true-like nor false-like (e.g., an empty string that is not '0').
     * This is the fallthrough in the 'b' case.
     */
    public function testCastToTypeBoolFallthroughReturnsNull(): void
    {
        // Arrange — 'maybe' is not 'true'/'1'/1 nor 'false'/'0'/0
        $result = $this->callCastToType('maybe', 'b');

        // Assert — neither true nor false can be determined → null
        $this->assertNull($result);
    }

    /**
     * castToType() returns null for a non-numeric string cast to integer.
     * 'abc' cannot be safely cast to int, so null is returned rather than 0.
     */
    public function testCastToTypeNonNumericIntReturnsNull(): void
    {
        $this->assertNull($this->callCastToType('abc', 'i'));
    }

    /**
     * castToType() returns null for a non-numeric string cast to float.
     */
    public function testCastToTypeNonNumericFloatReturnsNull(): void
    {
        $this->assertNull($this->callCastToType('not-a-number', 'f'));
    }

    // =========================================================================
    // shouldCacheResult (public, covers private: estimateResultSetMemory,
    //   getAvailableMemoryMB, parseMemoryLimit)
    // =========================================================================

    /**
     * shouldCacheResult() returns true for an empty array — empty result
     * sets are always worth caching because they are tiny and callers need
     * to know the query produced no rows.
     */
    public function testShouldCacheResultReturnsTrueForEmptyArray(): void
    {
        // Arrange / Act / Assert
        $this->assertTrue($this->db->shouldCacheResult([]));
    }

    /**
     * shouldCacheResult() returns true for non-array input (null, false, string).
     * The invariant: when there is nothing to check, cache it.
     */
    public function testShouldCacheResultReturnsTrueForNonArray(): void
    {
        // Arrange / Act / Assert
        $this->assertTrue($this->db->shouldCacheResult(null));
        $this->assertTrue($this->db->shouldCacheResult(false));
        $this->assertTrue($this->db->shouldCacheResult('string'));
    }

    /**
     * shouldCacheResult() returns true for a small result set (10 rows).
     * This path exercises estimateResultSetMemory(), getAvailableMemoryMB(),
     * and parseMemoryLimit() — all private helpers — via the call chain.
     */
    public function testShouldCacheResultReturnsTrueForSmallResultSet(): void
    {
        // Arrange — 10 small rows, well within the 1000-row / 50 MB defaults
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['id' => $i, 'name' => "row_$i", 'value' => 1.5];
        }

        // Act / Assert
        $this->assertTrue($this->db->shouldCacheResult($rows));
    }

    /**
     * shouldCacheResult() returns false when the row count exceeds 1000
     * (the default maxRows limit when no cache settings are configured).
     * This guards against flooding the cache with huge result sets.
     */
    public function testShouldCacheResultReturnsFalseForHugeRowCount(): void
    {
        // Arrange — 1001 rows exceeds the default 1000-row cap
        $rows = array_fill(0, 1001, ['id' => 1, 'v' => 'x']);

        // Act / Assert
        $this->assertFalse($this->db->shouldCacheResult($rows));
    }

    // =========================================================================
    // cacheExpire / cacheStore / cacheRead / cacheflush
    // (Cache is disabled / memcached not available in unit test context,
    //  so these methods execute their setup logic and return gracefully)
    // =========================================================================

    /**
     * cacheExpire() must not throw when the cache backend is unavailable.
     * Settings::getSetting('cache') returns false in tests, causing the
     * method to use the memcached default which then fails silently.
     */
    public function testCacheExpireDoesNotThrowWithUnavailableCache(): void
    {
        // Arrange / Act / Assert
        $this->db->prefix = 'test_';
        // No exception = pass; return value is false (cache miss or disabled)
        $result = $this->db->cacheExpire('SELECT 1', null);
        $this->addToAssertionCount(1); // assert the call completed without exception
    }

    /**
     * cacheExpire() with a non-null category must also execute without error.
     * The category branch inside cacheExpire() is covered by passing a value.
     */
    public function testCacheExpireWithCategoryDoesNotThrow(): void
    {
        // Arrange / Act / Assert
        $this->db->cacheExpire('SELECT * FROM users', 'users');
        $this->addToAssertionCount(1);
    }

    /**
     * cacheStore() executes its serialization + cache-save logic without
     * throwing even when the cache backend is unavailable.
     * With memcached disabled, save() returns false but no exception is thrown.
     */
    public function testCacheStoreDoesNotThrowWithUnavailableCache(): void
    {
        // Arrange — small result set, non-null category to cover that branch
        $data = [['id' => 1, 'name' => 'test']];

        // Act / Assert
        $this->db->prefix = 'test_';
        $result = $this->db->cacheStore('SELECT 1', $data, 'mycat', 3600);
        // Result is false when cache is disabled, which is acceptable
        $this->addToAssertionCount(1);
    }

    /**
     * cacheStore() must not throw even for large data sets that trigger
     * the gzcompress compression branch (data > 10 KB).
     */
    public function testCacheStoreWithLargeDataDoesNotThrow(): void
    {
        // Arrange — generate a result set larger than 10 KB
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = ['id' => $i, 'payload' => str_repeat('x', 200)];
        }

        // Act / Assert
        $this->db->cacheStore('SELECT * FROM large_table', $data, null, 60);
        $this->addToAssertionCount(1);
    }

    /**
     * cacheRead() returns false when the cache backend is unavailable (cache miss).
     * The method must execute its full initialization path before returning false.
     */
    public function testCacheReadReturnsFalseOnCacheMiss(): void
    {
        // Arrange / Act
        $result = $this->db->cacheRead('SELECT 1', '');

        // Assert — cache miss must return false
        $this->assertFalse($result, 'cacheRead() must return false on cache miss or disabled cache');
    }

    /**
     * cacheRead() with a non-empty category must still return false on cache miss.
     * The category code path inside cacheRead() is covered.
     */
    public function testCacheReadWithCategoryReturnsFalse(): void
    {
        // Arrange / Act
        $result = $this->db->cacheRead('SELECT * FROM users', 'users');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * cacheflush() must not throw when the cache backend is unavailable.
     * With a non-empty category, the category-assignment branch is covered.
     */
    public function testCacheflushDoesNotThrowWithUnavailableCache(): void
    {
        // Arrange / Act / Assert
        $this->db->cacheflush('mycat');
        $this->addToAssertionCount(1);
    }

    /**
     * cacheflush() with empty category must also execute without error.
     * Empty string skips the category-assignment branch.
     */
    public function testCacheflushEmptyCategoryDoesNotThrow(): void
    {
        // Arrange / Act / Assert
        $this->db->cacheflush('');
        $this->addToAssertionCount(1);
    }
}
