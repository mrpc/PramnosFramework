<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * The mapping that decides what a scaffolded column *is* — 22 statements, never executed.
 *
 * `mapSqlTypeToLogical()` turns a raw type as the database reports it into the vocabulary the
 * migration wizard, the CRUD generator and the SPA field descriptors all read. It is the single
 * place that decides a column is a checkbox rather than a number input, or a date picker rather
 * than a text box — and it has to answer for **two dialects**, because the same logical type
 * arrives as `varchar` from MySQL and `character varying` from PostgreSQL.
 *
 * Two things in it are load-bearing and both are asserted for what they are:
 *
 *   - **`tinyint(1)` is a boolean, and it is decided before the length qualifier is stripped.**
 *     That ordering is the whole trick: strip first and `tinyint(1)` becomes `tinyint`, which is
 *     an integer — so every MySQL boolean in the project would scaffold as a number input, and
 *     the person filling the form would type `1`.
 *   - **An unknown type falls back to `string`.** A text input holds anything, so a type this
 *     mapping has never met produces a usable screen rather than a broken one. Falling back to,
 *     say, `integer` would generate a form that refuses the data the column actually holds.
 *
 * A unit test with a data provider, because the method is pure: it takes a string and returns a
 * string, and every arm of its `switch` is a separate claim about a real database's vocabulary.
 */
#[CoversClass(MakeCommandBase::class)]
class SqlTypeVocabularyTest extends TestCase
{
    /**
     * Every raw type maps to the logical type the generators expect.
     *
     * The two dialects are deliberately interleaved rather than grouped: what matters is that
     * `int4` and `mediumint` end up in the same place, because a project migrated from one engine
     * to the other must scaffold the same screens.
     *
     * @param string $raw      As `Database::getColumns()` reports it
     * @param string $expected The logical type
     */
    #[DataProvider('vocabulary')]
    public function testARawTypeMapsToItsLogicalType(string $raw, string $expected): void
    {
        // Act & Assert
        $this->assertSame($expected, $this->map($raw), $raw . ' mapped to the wrong logical type');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function vocabulary(): array
    {
        return [
            // The MySQL boolean convention, decided before the length is stripped.
            'tinyint(1)'                     => ['tinyint(1)', 'boolean'],
            'tinyint(1) unsigned'            => ['tinyint(1) unsigned', 'boolean'],
            'tinyint (1) with spaces'        => ['tinyint ( 1 )', 'boolean'],

            // …and a tinyint that is not one.
            'tinyint(4)'                     => ['tinyint(4)', 'tinyinteger'],
            'tinyint'                        => ['tinyint', 'tinyinteger'],

            // Integers, both dialects.
            'smallint'                       => ['smallint', 'smallinteger'],
            'int2 (pg)'                      => ['int2', 'smallinteger'],
            'smallserial (pg)'               => ['smallserial', 'smallinteger'],
            'mediumint (mysql)'              => ['mediumint', 'integer'],
            'int'                            => ['int', 'integer'],
            'integer'                        => ['integer', 'integer'],
            'int(11)'                        => ['int(11)', 'integer'],
            'serial (pg)'                    => ['serial', 'integer'],
            'int4 (pg)'                      => ['int4', 'integer'],
            'bigint'                         => ['bigint', 'biginteger'],
            'bigserial (pg)'                 => ['bigserial', 'biginteger'],
            'int8 (pg)'                      => ['int8', 'biginteger'],

            // Booleans by name.
            'bool'                           => ['bool', 'boolean'],
            'boolean'                        => ['boolean', 'boolean'],

            // Exact and floating point.
            'decimal(10,2)'                  => ['decimal(10,2)', 'decimal'],
            'numeric (pg)'                   => ['numeric(10,2)', 'decimal'],
            'money (pg)'                     => ['money', 'decimal'],
            'float'                          => ['float', 'float'],
            'real (pg)'                      => ['real', 'float'],
            'float4 (pg)'                    => ['float4', 'float'],
            'double'                         => ['double', 'double'],
            'double precision (pg)'          => ['double precision', 'double'],
            'float8 (pg)'                    => ['float8', 'double'],

            // Text, and the multi-word PostgreSQL spellings.
            'char(2)'                        => ['char(2)', 'char'],
            'character (pg)'                 => ['character(2)', 'char'],
            'bpchar (pg)'                    => ['bpchar', 'char'],
            'varchar(255)'                   => ['varchar(255)', 'string'],
            'character varying (pg)'         => ['character varying(255)', 'string'],
            'tinytext (mysql)'               => ['tinytext', 'text'],
            'mediumtext (mysql)'             => ['mediumtext', 'text'],
            'text'                           => ['text', 'text'],
            'longtext (mysql)'               => ['longtext', 'longtext'],

            // Time, and the three ways PostgreSQL says timestamp.
            'date'                           => ['date', 'date'],
            'datetime (mysql)'               => ['datetime', 'datetime'],
            'timestamp'                      => ['timestamp', 'timestamp'],
            'timestamp without time zone'    => ['timestamp without time zone', 'timestamp'],
            'timestamp with time zone'       => ['timestamp with time zone', 'timestamp'],
            'timestamptz (pg)'               => ['timestamptz', 'timestamp'],

            // Documents and identifiers.
            'json'                           => ['json', 'json'],
            'jsonb (pg)'                     => ['jsonb', 'json'],
            'uuid (pg)'                      => ['uuid', 'uuid'],

            // Binary, both dialects.
            'binary(16)'                     => ['binary(16)', 'binary'],
            'varbinary (mysql)'              => ['varbinary(255)', 'binary'],
            'blob'                           => ['blob', 'binary'],
            'tinyblob (mysql)'               => ['tinyblob', 'binary'],
            'mediumblob (mysql)'             => ['mediumblob', 'binary'],
            'longblob (mysql)'               => ['longblob', 'binary'],
            'bytea (pg)'                     => ['bytea', 'binary'],
        ];
    }

    /**
     * A type nobody has taught it becomes a string.
     *
     * The safe fallback for a scaffold: a text input holds anything, so a PostGIS `geometry` or a
     * MySQL `set` produces a screen somebody can use and correct. Falling back to a narrower type
     * would generate a form that refuses the data the column actually holds — and the person
     * meeting that has no reason to suspect the generator.
     */
    public function testAnUnknownTypeBecomesAString(): void
    {
        // Act & Assert
        foreach (['geometry', 'set', "enum('a','b')", 'inet', 'tsvector', 'xml', ''] as $exotic) {
            $this->assertSame(
                'string',
                $this->map($exotic),
                var_export($exotic, true) . ' did not fall back to a usable type'
            );
        }
    }

    /**
     * The case and the whitespace a database happens to report do not matter.
     *
     * `getColumns()` answers differently on different engines and versions — `VARCHAR(255)` on one,
     * `character varying` on another, sometimes with padding. A mapping that only recognised the
     * lower-case spelling would fall back to `string` for half the schema, silently, and every
     * integer column would scaffold as a text box.
     */
    public function testCaseAndWhitespaceDoNotMatter(): void
    {
        // Act & Assert
        $this->assertSame('integer', $this->map('  INT(11)  '));
        $this->assertSame('boolean', $this->map('TINYINT(1)'));
        $this->assertSame('timestamp', $this->map('TIMESTAMP   WITHOUT   TIME   ZONE'));
        $this->assertSame('string', $this->map('Character Varying(255)'));
    }

    /**
     * Reach the mapping, which is protected.
     *
     * Protected rather than private on purpose — the SPA field descriptors read the same
     * vocabulary, and a second mapping would be a second opinion about what a `tinyint(1)` is.
     * A closure bound to an anonymous subclass is the smallest way in that does not add a seam
     * the production code has no use for.
     */
    private function map(string $raw): string
    {
        static $command = null;

        if ($command === null) {
            $command = new class ('probe') extends MakeCommandBase {
                public function reachMap(string $raw): string
                {
                    return $this->mapSqlTypeToLogical($raw);
                }
            };
        }

        return $command->reachMap($raw);
    }
}
