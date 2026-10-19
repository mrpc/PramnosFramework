<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * Bytes, bound as bytes: a value for a `blob` (MySQL) or `bytea` (PostgreSQL) column.
 *
 * ```php
 * $db->execute('INSERT INTO files (name, body) VALUES (%s, %s)', ...[$name, new Binary($bytes)]);
 * $db->queryBuilder()->table('files')->insert(['name' => $name, 'body' => new Binary($bytes)]);
 * ```
 *
 * PostgreSQL takes a bound string as text: bytes that are not UTF-8 are refused, and a backslash
 * is read as an escape. Nothing at the moment of binding says the column is `bytea`, so the value
 * says it: bound with `pg_escape_bytea()` there, as the bytes themselves on MySQL. Read back, a
 * `bytea` column is the bytes again on both.
 */
final class Binary
{
    public function __construct(public readonly string $bytes)
    {
    }
}
