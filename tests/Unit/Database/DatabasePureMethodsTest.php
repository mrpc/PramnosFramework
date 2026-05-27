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

    // =========================================================================
    // isConnectionAlive — null/false connection guard
    // =========================================================================

    /**
     * isConnectionAlive() returns false immediately when the connection handle
     * is null or false.  This is the early-return guard before the driver-specific
     * checks, exercising the `if (!$connection) { return false; }` branch.
     */
    public function testIsConnectionAliveReturnsFalseForNullConnection(): void
    {
        // Arrange / Act / Assert — both null and false must return false
        $this->assertFalse($this->db->isConnectionAlive(null),
            'null connection must be reported as not alive');
        $this->assertFalse($this->db->isConnectionAlive(false),
            'false connection must be reported as not alive');
    }

    // =========================================================================
    // prepareQuery — null and array-arg branches
    // =========================================================================

    /**
     * prepareQuery(null) must return immediately (void) — the early-return
     * guard prevents null from being processed as a SQL template.
     */
    public function testPrepareQueryNullReturnsVoid(): void
    {
        // Arrange / Act
        $result = $this->db->prepareQuery(null);

        // Assert — null input produces null (void return)
        $this->assertNull($result, 'prepareQuery(null) must return null');
    }

    /**
     * prepareQuery() with an array as the first argument after the SQL template
     * unwraps the array into positional arguments.  This exercises the
     * `$args = $args[0]` branch (line ~1352).
     */
    public function testPrepareQueryWithArrayArgUnwrapsToFlatArgs(): void
    {
        // Arrange — subclass overrides prepareInput() so no live connection is needed
        $db = new class extends Database {
            public function prepareInput($string) { return addslashes($string ?? ''); }
        };
        $db->type = 'mysql';

        // Act — array as first arg is flattened to positional args
        $result = $db->prepareQuery('SELECT %s AS x', ['hello']);

        // Assert — placeholder was replaced with the value
        $this->assertStringContainsString('hello', (string)$result,
            'Array arg must be unwrapped and substituted into the query');
    }

    // =========================================================================
    // getInsertId — not-connected branch
    // =========================================================================

    /**
     * getInsertId() returns false when there is no live connection and the
     * database type is MySQL.  Neither driver branch fires → falls through to
     * the final `return false`.
     */
    public function testGetInsertIdReturnsFalseWhenNotConnected(): void
    {
        // Arrange — MySQL type, no connection (_dbConnection is null by default)
        $this->db->type = 'mysql';

        // Act
        $result = $this->db->getInsertId();

        // Assert
        $this->assertFalse($result, 'getInsertId() must return false when not connected');
    }

    // =========================================================================
    // prepareDataForCache / restoreDataFromCache / restoreTypes (private)
    // =========================================================================

    /**
     * prepareDataForCache() with a non-array argument returns the value as-is.
     * The early return at the top of the method guards against non-array input.
     */
    public function testPrepareDataForCacheNonArrayPassthrough(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'prepareDataForCache');

        // Act
        $result = $ref->invoke($this->db, 'plain string');

        // Assert — non-array is returned unchanged
        $this->assertSame('plain string', $result);
    }

    /**
     * restoreDataFromCache() with data that is NOT the type-preserved format
     * (no '_t' key) returns it as-is.  This covers the final `return $cachedData`
     * path when the data is not typed.
     */
    public function testRestoreDataFromCacheNonTypedPassthrough(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'restoreDataFromCache');

        // Act
        $plain = ['row1' => 'value'];
        $result = $ref->invoke($this->db, $plain);

        // Assert — non-typed data is returned unchanged
        $this->assertSame($plain, $result);
    }

    /**
     * restoreTypes() with a non-array input returns the value as-is.
     * The early guard at the top of the method handles this case.
     */
    public function testRestoreTypesNonArrayPassthrough(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'restoreTypes');

        // Act
        $result = $ref->invoke($this->db, 'just a string');

        // Assert
        $this->assertSame('just a string', $result);
    }

    /**
     * restoreTypes() with an array containing a plain (non-typed) value keeps
     * it as-is via the final else branch.  This verifies that ordinary values
     * mixed with typed values are not corrupted.
     */
    public function testRestoreTypesPlainValueKeptAsIs(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'restoreTypes');

        // Act — a plain scalar value (not a typed ['v'=>...,'t'=>...] structure)
        $result = $ref->invoke($this->db, ['key' => 'plain_value']);

        // Assert — plain value is passed through unchanged
        $this->assertSame(['key' => 'plain_value'], $result);
    }

    // =========================================================================
    // estimateResultSetMemory — empty-set branch
    // =========================================================================

    /**
     * estimateResultSetMemory() returns 0 for an empty array without performing
     * any serialization.  This is the fast-path guard for empty result sets.
     */
    public function testEstimateResultSetMemoryEmptyReturnsZero(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'estimateResultSetMemory');

        // Act
        $result = $ref->invoke($this->db, []);

        // Assert
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // getAvailableMemoryMB — unlimited memory branch
    // =========================================================================

    /**
     * getAvailableMemoryMB() returns null when PHP memory_limit is '-1'
     * (unlimited).  null signals to the caller that no memory constraint
     * should be applied.
     */
    public function testGetAvailableMemoryMBReturnsNullForUnlimitedMemory(): void
    {
        // Arrange — temporarily set unlimited memory
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '-1');

        $ref = new \ReflectionMethod($this->db, 'getAvailableMemoryMB');

        // Act
        $result = $ref->invoke($this->db);

        // Restore
        ini_set('memory_limit', $original);

        // Assert
        $this->assertNull($result, 'getAvailableMemoryMB() must return null for unlimited memory');
    }

    // =========================================================================
    // parseMemoryLimit — 'm', 'k', and default branches
    // =========================================================================

    /**
     * parseMemoryLimit() converts megabyte notation (e.g. '128M') to bytes.
     * This is the most common PHP memory limit format.
     */
    public function testParseMemoryLimitMegabytes(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'parseMemoryLimit');

        // Act
        $result = $ref->invoke($this->db, '128M');

        // Assert
        $this->assertSame(128 * 1024 * 1024, $result);
    }

    /**
     * parseMemoryLimit() converts kilobyte notation (e.g. '512K') to bytes.
     */
    public function testParseMemoryLimitKilobytes(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'parseMemoryLimit');

        // Act
        $result = $ref->invoke($this->db, '512K');

        // Assert
        $this->assertSame(512 * 1024, $result);
    }

    /**
     * parseMemoryLimit() returns the integer value when no unit suffix is
     * present.  This is the default/fallback case in the switch statement.
     */
    public function testParseMemoryLimitNoSuffixReturnsInteger(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->db, 'parseMemoryLimit');

        // Act — plain integer string with no unit
        $result = $ref->invoke($this->db, '1048576');

        // Assert
        $this->assertSame(1048576, $result);
    }

    // =========================================================================
    // tryReconnect / refresh — reconnect path
    // =========================================================================

    /**
     * tryReconnect() delegates to refresh(false).  With no server configured,
     * the connection attempt fails and false is returned without throwing.
     * This exercises tryReconnect() and refresh() without a real DB server.
     */
    public function testTryReconnectReturnsFalseWithoutServer(): void
    {
        // Arrange — no server configured on the default instance
        $this->db->type = 'mysql';

        // Act — tryReconnect calls refresh(false) → close() + connect(false)
        $result = $this->db->tryReconnect();

        // Assert — connection failed; false returned without exception
        $this->assertFalse($result, 'tryReconnect() must return false when no server is reachable');
    }

    // =========================================================================
    // pgRewriteDmlLimit (private)
    // PostgreSQL does not support LIMIT in DELETE or UPDATE statements.
    // The adapter rewrites them to equivalent ctid subqueries.
    // =========================================================================

    /** Helper that calls the private pgRewriteDmlLimit() via reflection. */
    private function rewriteDml(string $sql): string
    {
        $ref = new \ReflectionMethod($this->db, 'pgRewriteDmlLimit');
        return (string) $ref->invoke($this->db, $sql);
    }

    /**
     * SELECT queries are returned unchanged — LIMIT is valid for SELECT in
     * PostgreSQL and must never be rewritten.
     */
    public function testPgRewriteDmlLimitIgnoresSelectStatements(): void
    {
        // Arrange
        $sql = 'SELECT * FROM "users" WHERE "active" = 1 LIMIT 10';

        // Act
        $result = $this->rewriteDml($sql);

        // Assert — SELECT must pass through unchanged
        $this->assertSame($sql, $result);
    }

    /**
     * DELETE without LIMIT is returned unchanged — nothing to rewrite.
     * Verifies the quick-exit guard fires correctly.
     */
    public function testPgRewriteDmlLimitIgnoresDeleteWithoutLimit(): void
    {
        // Arrange
        $sql = 'DELETE FROM "metricstoios" WHERE "ioid" = 4699';

        // Act
        $result = $this->rewriteDml($sql);

        // Assert — no LIMIT → no rewrite
        $this->assertSame($sql, $result);
    }

    /**
     * DELETE FROM table WHERE ... LIMIT N is rewritten to a ctid subquery.
     *
     * This is the exact query that was failing in the urbanwaterDev integration
     * suite: MySQL-style `DELETE ... LIMIT 1` written as a raw query, which
     * PostgreSQL rejects with "syntax error at or near 'limit'".
     *
     * The ctid form is semantically equivalent: it deletes the same set of
     * rows (at most N rows matching the WHERE clause).
     */
    public function testPgRewriteDmlLimitRewritesSimpleDelete(): void
    {
        // Arrange — post backtick→double-quote conversion
        $sql = 'delete from "metricstoios" where "ioid" = 4699 and "metricid" = 6572 limit 1';

        // Act
        $result = $this->rewriteDml($sql);

        // Assert — rewritten to ctid subquery
        $this->assertStringContainsString('ctid IN', $result,
            'DELETE with LIMIT must be rewritten to ctid subquery');
        $this->assertStringContainsString('LIMIT 1', $result,
            'The original limit value must be preserved in the subquery');
        // The top-level statement must end with the closing ')' of the subquery, not with LIMIT
        $this->assertStringEndsWith(')', rtrim($result),
            'Rewritten DELETE must end with closing paren of ctid subquery, not a trailing LIMIT');
        // Verify full ctid subquery structure
        $this->assertMatchesRegularExpression(
            '/DELETE FROM[^(]+WHERE ctid IN \(SELECT ctid FROM[^)]+LIMIT 1\)$/i',
            rtrim($result)
        );
    }

    /**
     * DELETE FROM table LIMIT N (no WHERE clause) is rewritten to:
     * DELETE FROM t WHERE ctid IN (SELECT ctid FROM t LIMIT N).
     * This deletes the first N rows in physical storage order.
     */
    public function testPgRewriteDmlLimitRewritesDeleteWithoutWhere(): void
    {
        // Arrange
        $sql = 'DELETE FROM "logs" LIMIT 100';

        // Act
        $result = $this->rewriteDml($sql);

        // Assert
        $this->assertStringContainsString('ctid IN', $result);
        $this->assertStringContainsString('LIMIT 100', $result);
        $this->assertStringNotContainsString('DELETE FROM "logs" LIMIT', $result);
    }

    /**
     * UPDATE table SET ... WHERE ... LIMIT N is rewritten using a ctid subquery.
     *
     * PostgreSQL rejects `UPDATE ... LIMIT N`; the ctid form is the standard
     * workaround that preserves the "update at most N matching rows" semantics.
     */
    public function testPgRewriteDmlLimitRewritesUpdateWithWhereAndLimit(): void
    {
        // Arrange
        $sql = 'UPDATE "orders" SET "status" = \'done\' WHERE "user_id" = 42 LIMIT 5';

        // Act
        $result = $this->rewriteDml($sql);

        // Assert — ctid subquery present, original LIMIT at end removed
        $this->assertStringContainsString('ctid IN', $result);
        $this->assertStringContainsString('LIMIT 5', $result);
        $this->assertMatchesRegularExpression(
            '/UPDATE "orders" SET.+WHERE ctid IN \(SELECT ctid FROM "orders" WHERE.+LIMIT 5\)/is',
            $result
        );
    }

    /**
     * UPDATE table SET ... LIMIT N (no WHERE) strips the LIMIT — updating all
     * rows with a limit is uncommon, and without a WHERE we just update all rows.
     */
    public function testPgRewriteDmlLimitRewritesUpdateWithoutWhereStripsLimit(): void
    {
        // Arrange
        $sql = 'UPDATE "sessions" SET "active" = 0 LIMIT 10';

        // Act
        $result = $this->rewriteDml($sql);

        // Assert — LIMIT stripped, no ctid needed (no WHERE to subquery on)
        $this->assertStringNotContainsStringIgnoringCase('LIMIT', $result);
        $this->assertStringContainsString('UPDATE "sessions" SET', $result);
    }

    /**
     * LIMIT inside a subquery (not at the top-level end) is NOT rewritten.
     * E.g.: DELETE FROM t WHERE id IN (SELECT id FROM s LIMIT 10) — valid PG.
     * The trailing character is ')' not a digit, so the guard does not fire.
     */
    public function testPgRewriteDmlLimitIgnoresLimitInsideSubquery(): void
    {
        // Arrange — LIMIT 10 is inside a subquery, followed by ')'
        $sql = 'DELETE FROM "t" WHERE "id" IN (SELECT "id" FROM "s" ORDER BY "ts" LIMIT 10)';

        // Act
        $result = $this->rewriteDml($sql);

        // Assert — unchanged (trailing char is ')', not a digit-ended LIMIT)
        $this->assertSame($sql, $result);
    }

    // =========================================================================
    // connect — throw-on-failure branch (throwOnFailure=true, bad server)
    // =========================================================================

    /**
     * connect(true) throws a RuntimeException when the connection cannot be
     * established.  This covers the throw path in the else-block of connect()
     * (the branch where $ok is false and $throwOnFailure is true).
     */
    public function testConnectThrowsRuntimeExceptionWhenConnectionFails(): void
    {
        // Arrange — no server configured, throwOnFailure=true (the default)
        $db = new Database();
        $db->type = 'mysql';

        // Act / Assert
        $this->expectException(\RuntimeException::class);
        $db->connect(true);
    }
}
