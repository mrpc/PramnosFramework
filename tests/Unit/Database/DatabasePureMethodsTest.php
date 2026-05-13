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
}
