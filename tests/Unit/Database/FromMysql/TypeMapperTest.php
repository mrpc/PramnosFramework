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
            'unsigned bigint'  => ['bigint(20) unsigned', 'numeric(20,0)'],
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
            'char'             => ['char(2)', 'char(2)'],
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
}
