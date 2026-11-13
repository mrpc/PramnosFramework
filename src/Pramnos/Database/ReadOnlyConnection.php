<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * The connection to read through: the installation's read-only account when it configured one
 * (`database_readonly_dsn`, `user:pass@host:port/name`); otherwise its own replica — the same
 * block {@see \Pramnos\Metrics\Metrics} already reads through, when the analyst turned that on
 * (`'llm' => ['analyst' => ['replica' => true]]`, FW-496); the ordinary connection when neither
 * is set up.
 *
 * Setting a dedicated account up is real work and an installation may decide its people are
 * trusted; where it has been done the boundary stops being a lexer and becomes the database's
 * own privileges. Shared by `db-inspect` and the analyst's `sql` and `export_csv`.
 */
final class ReadOnlyConnection
{
    /**
     * @throws \RuntimeException When a dedicated account is configured and cannot be used —
     *                           never a silent fall back to the writable connection, which is
     *                           the failure where somebody believes they have a boundary and
     *                           does not. A replica, by contrast, falls back and says so in the
     *                           log — it is not a privilege boundary, only where the read lands,
     *                           and the same trade Metrics already makes for the same reason.
     */
    public static function for(Database $db): Database
    {
        $dsn = \Pramnos\Application\Settings::getSetting('database_readonly_dsn');

        if (is_string($dsn) && trim($dsn) !== '') {
            try {
                return self::open($db, trim($dsn));
            } catch (\Throwable $exception) {
                throw new \RuntimeException('A read-only database account is configured but could not be used: ' . $exception->getMessage());
            }
        }

        return self::replica($db);
    }

    /**
     * Without a dedicated account: the database's own replica, read through the same mechanism
     * Metrics uses (`Database::replicaConnection()` — the settings' `replica` or `read` block),
     * when `'llm' => ['analyst' => ['replica' => true]]` turned it on. Never a second copy of
     * the connection details in a setting of its own — there is already one block that knows
     * them, and a DSN kept in two places is a DSN that drifts.
     */
    private static function replica(Database $db): Database
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);
        if (!is_array($config) || empty($config['replica'])) {
            return $db;
        }

        try {
            return $db->replicaConnection();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'The analyst read the database itself: the replica is not available (' . $exception->getMessage() . ').',
                'llm'
            );

            return $db;
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
