<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * The connection to read through: the installation's read-only account when it configured one
 * (`database_readonly_dsn`, `user:pass@host:port/name`), the ordinary connection when it did not.
 *
 * Setting one up is real work and an installation may decide its people are trusted; where it
 * has been done the boundary stops being a lexer and becomes the database's own privileges.
 * Shared by `db-inspect` and the analyst's `sql` and `export_csv`.
 */
final class ReadOnlyConnection
{
    /**
     * @throws \RuntimeException When an account is configured and cannot be used — never a
     *                           silent fall back to the writable connection, which is the
     *                           failure where somebody believes they have a boundary and does not.
     */
    public static function for(Database $db): Database
    {
        $dsn = \Pramnos\Application\Settings::getSetting('database_readonly_dsn');

        if (!is_string($dsn) || trim($dsn) === '') {
            return $db;
        }

        try {
            return self::open($db, trim($dsn));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('A read-only database account is configured but could not be used: ' . $exception->getMessage());
        }
    }

    /**
     * Open the account: the type, schema, prefix and flavour are the application's — a
     * read-only account is a second door onto the same database.
     */
    private static function open(Database $base, string $dsn): Database
    {
        $parts = parse_url('scheme://' . ltrim($dsn, '/'));

        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \RuntimeException('database_readonly_dsn is not user:pass@host:port/name');
        }

        $db           = new Database();
        $db->type     = $base->type;
        $db->server   = $parts['host'];
        $db->port     = (int) ($parts['port'] ?? $base->port);
        // Decoded on purpose: credentials in a DSN are percent-encoded so that a `@` or a `:` in a
        // password does not end the field. Nothing here came from `$_GET`.
        $db->user      = urldecode((string) ($parts['user'] ?? ''));
        $db->password  = urldecode((string) ($parts['pass'] ?? ''));
        $db->database  = ltrim((string) ($parts['path'] ?? ''), '/') ?: $base->database;
        $db->schema    = $base->schema;
        $db->prefix    = $base->prefix;
        $db->timescale = $base->timescale;

        if (!$db->connect(false)) {
            throw new \RuntimeException('the connection was refused');
        }

        return $db;
    }
}
