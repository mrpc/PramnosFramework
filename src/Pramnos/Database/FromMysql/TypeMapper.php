<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

/**
 * MySQL types as PostgreSQL ones, and MySQL values as the PostgreSQL column they go into takes
 * them.
 *
 * The type mapping is for a table no migration makes: one the application created itself. The
 * value conversion is for every row, because the PostgreSQL table — made by the migrations — is
 * often typed better than the MySQL one it replaces: a `tinyint(1)` that is a `boolean` there, a
 * unix time in an `int` that is a `timestamptz`, JSON in a `text`. What a converted value cannot
 * be becomes NULL where the column takes NULL, and is counted, never guessed.
 */
final class TypeMapper
{
    /** MySQL's "no date", which PostgreSQL refuses. */
    private const ZERO_DATE = '/^0000-00-00/';

    /**
     * The PostgreSQL type for a MySQL column type (`COLUMN_TYPE`, e.g. `int(10) unsigned`).
     *
     * Unsigned integers move up a size, so their range fits; `tinyint(1)` stays a number, as a
     * flag with a third state is common; text and blobs become `text` and `bytea`; `enum` and
     * `set` become `text`; anything unknown becomes `text`.
     */
    public static function toPostgres(string $mysqlType): string
    {
        $type     = strtolower(trim($mysqlType));
        $unsigned = str_contains($type, 'unsigned');
        preg_match('/^([a-z]+)(?:\(([^)]*)\))?/', $type, $m);
        $base = $m[1] ?? $type;
        $size = $m[2] ?? '';

        return match ($base) {
            'tinyint', 'smallint' => $unsigned && $base === 'smallint' ? 'integer' : 'smallint',
            'mediumint'           => 'integer',
            'int', 'integer'      => $unsigned ? 'bigint' : 'integer',
            'bigint'              => $unsigned ? 'numeric(20,0)' : 'bigint',
            'decimal', 'numeric'  => 'numeric' . ($size !== '' ? '(' . $size . ')' : ''),
            'float'               => 'real',
            'double', 'real'      => 'double precision',
            'bit'                 => $size === '' || $size === '1' ? 'boolean' : 'bit varying(' . $size . ')',
            'date'                => 'date',
            'datetime', 'timestamp' => 'timestamp',
            'time'                => 'time',
            'year'                => 'smallint',
            'char'                => 'char(' . ($size !== '' ? $size : '1') . ')',
            'varchar'             => 'varchar(' . ($size !== '' ? $size : '255') . ')',
            'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set' => 'text',
            'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' => 'bytea',
            'json'                => 'jsonb',
            default               => 'text',
        };
    }

    /**
     * A MySQL value as the PostgreSQL column takes it, or `null` with `$refused` set when it
     * cannot be one.
     *
     * @param string $mysqlType The source column's MySQL type
     * @param string $pgType    The target column's PostgreSQL type (`format_type`)
     * @param bool   $refused   Set when a value had to become NULL because it was not one
     */
    public static function value(mixed $value, string $mysqlType, string $pgType, ?bool &$refused = null): mixed
    {
        $refused = false;
        if ($value === null) {
            return null;
        }
        $mysql = strtolower($mysqlType);
        $pg    = strtolower($pgType);
        $text  = (string) $value;

        if ($pg === 'boolean') {
            return in_array(strtolower($text), ['1', 't', 'true', 'y', 'yes', "\x01"], true) ? 't' : 'f';
        }

        if (str_starts_with($pg, 'timestamp') || $pg === 'date') {
            // A unix time in an integer column, as a moment.
            if (preg_match('/^(tiny|small|medium|big)?int/', $mysql) === 1 || ctype_digit($text)) {
                if (!is_numeric($text) || (int) $text <= 0) {
                    $refused = (int) $text < 0;

                    return null;
                }

                return gmdate($pg === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s', (int) $text) . ($pg === 'timestamp with time zone' ? '+00' : '');
            }
            if ($text === '' || preg_match(self::ZERO_DATE, $text) === 1) {
                return null;
            }

            return $text;
        }

        if ($pg === 'json' || $pg === 'jsonb') {
            if ($text === '') {
                return null;
            }
            json_decode($text);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $refused = true;

                return null;
            }

            return $text;
        }

        if ($pg === 'bytea') {
            return '\\x' . bin2hex($text);
        }

        if (preg_match('/^(smallint|integer|bigint|numeric|real|double precision)/', $pg) === 1) {
            if ($text === '') {
                return null;
            }
            if (!is_numeric($text)) {
                $refused = true;

                return null;
            }

            return $text;
        }

        // Text: no NUL bytes, and valid UTF-8 — PostgreSQL refuses both, MySQL keeps them.
        $text = str_replace("\0", '', $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return $text;
    }
}
