<?php

namespace Pramnos\Database\Grammar;

/**
 * MariaDB 10.3+ DDL grammar.
 *
 * MariaDB is MySQL-compatible everywhere the framework emits DDL, so this
 * grammar inherits the whole of {@see MySQLSchemaGrammar} and overrides exactly
 * one thing: sequences.
 *
 * Oracle MySQL has no sequence objects, so MySQLSchemaGrammar compiles all four
 * sequence statements to an empty string and SchemaBuilder silently skips them
 * (nextVal() answers 0).  MariaDB 10.3 introduced real `SEQUENCE` objects with
 * `CREATE SEQUENCE`, `NEXTVAL()`, `SETVAL()` and `DROP SEQUENCE`, so on MariaDB
 * that silent no-op is simply wrong — the same migration that works on
 * PostgreSQL can work here too.
 *
 * The grammar is only selected when the connection actually reports the
 * SEQUENCES capability (MariaDB >= 10.3); an older MariaDB keeps the
 * MySQL no-op behaviour.  See {@see \Pramnos\Database\SchemaBuilder::makeGrammar()}.
 *
 * Syntax differences from PostgreSQL worth knowing:
 *   - MariaDB spells the negative cycle option `NOCYCLE`, not `NO CYCLE`.
 *   - `NEXTVAL`/`SETVAL` take a bare (identifier-quoted) sequence name, not a
 *     string literal as PostgreSQL's `nextval('seq')` does.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
class MariaDBSchemaGrammar extends MySQLSchemaGrammar
{
    // =========================================================================
    // Sequence DDL (MariaDB 10.3+)
    // =========================================================================

    /**
     * Compile a CREATE SEQUENCE statement.
     *
     * @param  string   $name      Sequence name (optionally `db.sequence`).
     * @param  int      $start     Starting value.
     * @param  int      $increment Increment per NEXTVAL.
     * @param  int|null $minValue  Optional MINVALUE.
     * @param  int|null $maxValue  Optional MAXVALUE.
     * @param  bool     $cycle     Wrap around when the range is exhausted.
     * @return string
     */
    public function compileCreateSequence(
        string $name,
        int $start = 1,
        int $increment = 1,
        ?int $minValue = null,
        ?int $maxValue = null,
        bool $cycle = false
    ): string {
        $sql = 'CREATE SEQUENCE IF NOT EXISTS ' . $this->quoteSequence($name)
            . " START WITH {$start}"
            . " INCREMENT BY {$increment}";

        if ($minValue !== null) {
            $sql .= " MINVALUE {$minValue}";
        }
        if ($maxValue !== null) {
            $sql .= " MAXVALUE {$maxValue}";
        }

        // MariaDB spells this NOCYCLE (one word), unlike PostgreSQL's NO CYCLE.
        $sql .= $cycle ? ' CYCLE' : ' NOCYCLE';

        return $sql;
    }

    /**
     * Compile a DROP SEQUENCE statement.
     *
     * @param  string $name     Sequence name.
     * @param  bool   $ifExists Emit the IF EXISTS guard.
     * @return string
     */
    public function compileDropSequence(string $name, bool $ifExists = true): string
    {
        $guard = $ifExists ? 'IF EXISTS ' : '';

        return 'DROP SEQUENCE ' . $guard . $this->quoteSequence($name);
    }

    /**
     * Compile a statement that advances the sequence and returns its new value.
     *
     * @param  string $name Sequence name.
     * @return string
     */
    public function compileNextVal(string $name): string
    {
        return 'SELECT NEXTVAL(' . $this->quoteSequence($name) . ')';
    }

    /**
     * Compile a statement that sets the sequence's current value.
     *
     * MariaDB's third SETVAL argument is `is_used` and carries the same meaning
     * as PostgreSQL's `is_called`: TRUE means the next NEXTVAL returns
     * `$value + increment`, FALSE means it returns `$value` itself.
     *
     * @param  string $name     Sequence name.
     * @param  int    $value    Value to set.
     * @param  bool   $isCalled Whether the value counts as already consumed.
     * @return string
     */
    public function compileSetVal(string $name, int $value, bool $isCalled = true): string
    {
        $used = $isCalled ? 'TRUE' : 'FALSE';

        return 'SELECT SETVAL(' . $this->quoteSequence($name) . ", {$value}, {$used})";
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Backtick-quote a sequence name, quoting each dot-separated part
     * independently so a `database.sequence` reference stays valid.
     *
     * @param  string $name Raw sequence name.
     * @return string
     */
    protected function quoteSequence(string $name): string
    {
        $parts = \explode('.', $name);

        $quoted = \array_map(
            static fn(string $part): string => '`' . \str_replace('`', '``', $part) . '`',
            $parts
        );

        return \implode('.', $quoted);
    }
}
