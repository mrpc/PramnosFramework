<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database\FromMysql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\FromMysql\FdwTransport;

/**
 * The SQL side of a move through `mysql_fdw`: each column read as what the wrapper can hand over,
 * and converted by PostgreSQL to what the target takes — the same answers TypeMapper gives in PHP.
 */
#[CoversClass(FdwTransport::class)]
final class FdwTransportTest extends TestCase
{
    /**
     * Integers are read as numbers (unsigned and bigint as numeric, so the range fits), decimals
     * as numeric, bytes as bytea, everything else as text for PostgreSQL to convert.
     */
    public function testForeignTypes(): void
    {
        // Act + Assert
        $this->assertSame('bigint', FdwTransport::foreignType('int(11)', 'integer'));
        $this->assertSame('numeric', FdwTransport::foreignType('int(10) unsigned', 'bigint'));
        $this->assertSame('numeric', FdwTransport::foreignType('bigint(20)', 'bigint'));
        $this->assertSame('numeric', FdwTransport::foreignType('decimal(10,2)', 'numeric(10,2)'));
        $this->assertSame('bytea', FdwTransport::foreignType('mediumblob', 'text'));
        $this->assertSame('bytea', FdwTransport::foreignType('text', 'bytea'));
        $this->assertSame('text', FdwTransport::foreignType('datetime', 'timestamp without time zone'));
    }

    /**
     * The conversions: flags, unix times into each kind of moment, zero dates, JSON only when it
     * is JSON, numbers only when they are numbers, bytes into text as UTF-8, the rest cast.
     */
    public function testExpressions(): void
    {
        // Act + Assert
        $this->assertSame('("a" <> 0)', FdwTransport::expression('a', 'tinyint(1)', 'boolean'));
        $this->assertSame("(lower(\"a\"::text) IN ('1', 't', 'true', 'y', 'yes'))", FdwTransport::expression('a', "enum('y','n')", 'boolean'));
        $this->assertSame('CASE WHEN "t" > 0 THEN to_timestamp("t") END', FdwTransport::expression('t', 'int(11)', 'timestamp with time zone'));
        $this->assertSame('CASE WHEN "t" > 0 THEN (to_timestamp("t") AT TIME ZONE \'UTC\') END', FdwTransport::expression('t', 'int(11)', 'timestamp without time zone'));
        $this->assertSame('CASE WHEN "t" > 0 THEN (to_timestamp("t") AT TIME ZONE \'UTC\')::date END', FdwTransport::expression('t', 'int(11)', 'date'));
        $this->assertStringContainsString("LIKE '0000-00-00%' THEN NULL ELSE \"d\"::date END", FdwTransport::expression('d', 'date', 'date'));
        $this->assertSame('CASE WHEN "j" IS JSON THEN "j"::jsonb END', FdwTransport::expression('j', 'text', 'jsonb'));
        $this->assertSame('"b"', FdwTransport::expression('b', 'blob', 'bytea'));
        $this->assertSame('"n"::integer', FdwTransport::expression('n', 'int(11)', 'integer'));
        $this->assertStringStartsWith('CASE WHEN "s" ~ ', FdwTransport::expression('s', 'varchar(10)', 'numeric(10,2)'));
        $this->assertSame("convert_from(\"bio\", 'UTF8')::text", FdwTransport::expression('bio', 'varbinary(20)', 'text'));
        $this->assertSame('"v"::character varying(20)', FdwTransport::expression('v', 'varchar(20)', 'character varying(20)'));
        $this->assertSame('"v"::text', FdwTransport::expression('v', 'varchar(20)', ''));
    }

    /**
     * Moments in a zone, in SQL: the wrapper reads with the session in UTC, so a MySQL
     * `timestamp` is UTC and a `datetime` is the wall-clock time of the zone it was written in.
     */
    public function testExpressionsInAZone(): void
    {
        // Act + Assert
        $this->assertStringContainsString('ELSE ("w"::timestamp AT TIME ZONE \'America/New_York\') END', FdwTransport::expression('w', 'datetime', 'timestamp with time zone', 'America/New_York'));
        $this->assertStringContainsString('ELSE "w"::timestamp without time zone END', FdwTransport::expression('w', 'datetime', 'timestamp without time zone', 'America/New_York'), 'a wall clock into a wall clock: as it was');
        $this->assertStringContainsString('ELSE ("a"::timestamp AT TIME ZONE \'UTC\') END', FdwTransport::expression('a', 'timestamp', 'timestamp with time zone', 'America/New_York'));
        $this->assertStringContainsString('ELSE (("a"::timestamp AT TIME ZONE \'UTC\') AT TIME ZONE \'America/New_York\') END', FdwTransport::expression('a', 'timestamp', 'timestamp without time zone', 'America/New_York'));
        $this->assertSame('CASE WHEN "t" > 0 THEN (to_timestamp("t") AT TIME ZONE \'America/New_York\')::date END', FdwTransport::expression('t', 'int(11)', 'date', 'America/New_York'));
    }

    /** Without the extension on the server there is no transport, and nothing is created. */
    public function testWithoutTheExtensionThereIsNone(): void
    {
        // Arrange
        $result = new \stdClass();
        $result->numRows = 0;
        $target = $this->createMock(Database::class);
        $target->expects($this->once())->method('query')->willReturn($result);

        // Act + Assert
        $this->assertNull(FdwTransport::open($target, new Database()));
    }
}
