<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database\FromMysql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\FromMysql\TypeMapper;

/**
 * MySQL types and values as PostgreSQL takes them: ranges that fit, values the target type can
 * hold, and NULL — counted — for what it cannot.
 */
#[CoversClass(TypeMapper::class)]
final class TypeMapperTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function types(): array
    {
        return [
            'int'              => ['int(11)', 'integer'],
            'unsigned int'     => ['int(10) unsigned', 'bigint'],
            'unsigned bigint'  => ['bigint(20) unsigned', 'bigint'],
            'bigint'           => ['bigint(20)', 'bigint'],
            'tinyint(1)'       => ['tinyint(1)', 'smallint'],
            'unsigned smallint'=> ['smallint(5) unsigned', 'integer'],
            'mediumint'        => ['mediumint(9)', 'integer'],
            'decimal'          => ['decimal(12,4)', 'numeric(12,4)'],
            'float'            => ['float', 'real'],
            'double'           => ['double', 'double precision'],
            'bit(1)'           => ['bit(1)', 'boolean'],
            'bit(8)'           => ['bit(8)', 'bit varying(8)'],
            'datetime'         => ['datetime', 'timestamp'],
            'timestamp'        => ['timestamp', 'timestamp'],
            'date'             => ['date', 'date'],
            'time'             => ['time', 'time'],
            'year'             => ['year(4)', 'smallint'],
            'char, as a varchar' => ['char(2)', 'varchar(2)'],
            'varchar'          => ['varchar(100)', 'varchar(100)'],
            'longtext'         => ['longtext', 'text'],
            'enum'             => ["enum('a','b')", 'text'],
            'blob'             => ['mediumblob', 'bytea'],
            'varbinary'        => ['varbinary(16)', 'bytea'],
            'json'             => ['json', 'jsonb'],
            'unknown'          => ['geometry', 'text'],
        ];
    }

    /** Each MySQL type has the PostgreSQL type that holds its range and its values. */
    #[DataProvider('types')]
    public function testTypes(string $mysql, string $postgres): void
    {
        // Act + Assert
        $this->assertSame($postgres, TypeMapper::toPostgres($mysql));
    }

    /**
     * Values become what the target column takes: flags as booleans, unix times and dates as
     * moments, MySQL's zero date as NULL, JSON checked, bytes as bytea, text without NUL bytes
     * and in valid UTF-8; what cannot be is NULL and refused.
     */
    public function testValuesBecomeWhatTheTargetTakes(): void
    {
        // Arrange
        $cases = [
            ['1', 'tinyint(1)', 'boolean', 't', false],
            ['0', 'tinyint(1)', 'boolean', 'f', false],
            ['1760000000', 'int(11)', 'timestamp with time zone', '2025-10-09 08:53:20+00', false],
            ['1760000000', 'int(11)', 'timestamp without time zone', '2025-10-09 08:53:20', false],
            ['1760000000', 'int(11)', 'date', '2025-10-09', false],
            ['0', 'int(11)', 'timestamp with time zone', null, false],
            ['-5', 'int(11)', 'timestamp with time zone', null, true],
            ['0000-00-00 00:00:00', 'datetime', 'timestamp without time zone', null, false],
            ['2026-10-18 09:00:00', 'datetime', 'timestamp without time zone', '2026-10-18 09:00:00', false],
            ['', 'varchar(20)', 'date', null, false],
            ['{"a":1}', 'text', 'jsonb', '{"a":1}', false],
            ['{broken', 'text', 'json', null, true],
            ['', 'text', 'jsonb', null, false],
            ["\x00\xff", 'blob', 'bytea', '\\x00ff', false],
            ['', 'varchar(5)', 'integer', null, false],
            ['12', 'varchar(5)', 'integer', '12', false],
            ['abc', 'varchar(5)', 'numeric(10,2)', null, true],
            ["a\0b", 'text', 'text', 'ab', false],
            ["caf\xe9", 'text', 'character varying(10)', 'caf?', false],
            [null, 'int', 'integer', null, false],
        ];

        foreach ($cases as [$in, $mysql, $pg, $out, $refusal]) {
            // Act
            $got = TypeMapper::value($in, $mysql, $pg, $refused);

            // Assert
            $this->assertSame($out, $got, var_export($in, true) . ' → ' . $pg);
            $this->assertSame($refusal, $refused, var_export($in, true) . ' refused?');
        }
    }

    /**
     * Moments and zones: a `datetime` is the wall-clock time of the zone it was written in, a
     * MySQL `timestamp` (read with the session in UTC) and a unix time are moments. Into a
     * `timestamptz` each becomes the moment; into a column without a zone, the zone's wall clock.
     * A date that is not one is refused rather than passed to PostgreSQL to fail the batch.
     */
    public function testMomentsInAZone(): void
    {
        // Arrange
        $cases = [
            ['2026-01-15 12:00:00', 'datetime', 'timestamp with time zone', '2026-01-15 17:00:00+00', false],
            ['2026-01-15 12:00:00.250000', 'datetime(6)', 'timestamp with time zone', '2026-01-15 17:00:00.250000+00', false],
            ['2026-01-15 12:00:00', 'datetime', 'timestamp without time zone', '2026-01-15 12:00:00', false],
            ['2026-01-15', 'date', 'timestamp with time zone', '2026-01-15 05:00:00+00', false],
            ['2026-01-15 12:00:00', 'timestamp', 'timestamp with time zone', '2026-01-15 12:00:00+00', false],
            ['2026-01-15 12:00:00', 'timestamp', 'timestamp without time zone', '2026-01-15 07:00:00', false],
            ['2026-01-15 03:00:00', 'timestamp', 'date', '2026-01-14', false],
            ['1768478400', 'int(11)', 'timestamp without time zone', '2026-01-15 07:00:00', false],
            ['not a date', 'datetime', 'timestamp with time zone', null, true],
        ];

        foreach ($cases as [$in, $mysql, $pg, $out, $refusal]) {
            // Act
            $got = TypeMapper::value($in, $mysql, $pg, $refused, 'America/New_York');

            // Assert
            $this->assertSame($out, $got, $in . ' ' . $mysql . ' → ' . $pg);
            $this->assertSame($refusal, $refused, $in . ' refused?');
        }
    }

    /**
     * UTF-8 as a `latin1` connection stores it: each byte made the character Windows-1252 (MySQL's
     * `latin1`) gives it, then stored as UTF-8 again.
     */
    private static function doubleEncoded(string $text): string
    {
        $cp1252 = [0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
            0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
            0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
            0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178];
        $out = '';
        foreach (str_split($text) as $byte) {
            $out .= mb_chr($cp1252[ord($byte)] ?? ord($byte), 'UTF-8');
        }

        return $out;
    }

    /**
     * Double-encoded text is repaired to what it was — Greek (with `ρ`, whose second byte is
     * 0x81, a byte Windows-1252 leaves undefined), a typographic apostrophe, an emoji — and text
     * that is not double-encoded is left: proper Greek, ASCII, and `café` from a real `latin1`
     * column, whose `é` is one byte that is not UTF-8 on its own.
     */
    public function testDoubleEncodedTextIsRepairedAndOnlyThat(): void
    {
        // Arrange
        $repaired = ['Ελληνικά ρ', 'it’s', 'ok 😀', 'Ἀθῆναι'];
        $left     = ['Ελληνικά', 'plain ascii', 'café', 'Â£ is not a pound sign here, Ω'];

        foreach ($repaired as $text) {
            // Act + Assert
            $this->assertSame($text, TypeMapper::repairEncoding(self::doubleEncoded($text)), $text);
        }
        foreach ($left as $text) {
            $this->assertNull(TypeMapper::repairEncoding($text), $text . ' is not double-encoded');
        }
    }
}
