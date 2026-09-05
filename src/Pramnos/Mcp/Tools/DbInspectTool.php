<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Database\Database;
use Pramnos\Mcp\ScopedMcpTool;
use Pramnos\Security\PersonalDataRegistry;
use Pramnos\Security\ReadOnlyQuery;

/**
 * Ask the live database a question, without opening a shell on the box.
 *
 * The reason this exists: diagnosing a production problem meant SSH, and SSH
 * gives a shell — everything the account can do, no record of what was *read*,
 * and one mistyped `DELETE` away from an incident. A tool is the narrower thing:
 * one capability, revocable with a token, and every call logged with its
 * arguments.
 *
 * ## Four boundaries, and which one is load-bearing
 *
 * 1. **The scope.** A token without `mcp:db_read` neither sees this nor can call
 *    it — checked when the list is built and again when the call arrives.
 * 2. **A read-only database account**, when the installation has configured one
 *    (`database.readonly` in the settings). Where it exists this is the real
 *    boundary, because it is enforced by PostgreSQL and not by us.
 * 3. **{@see ReadOnlyQuery}**, which refuses anything that is not a read. Where
 *    there is no read-only account **this is the only boundary**, and it is a
 *    lexer rather than a parser — see its docblock for what that does and does
 *    not promise.
 * 4. **{@see PersonalDataRegistry}**, which decides what comes back.
 *
 * ## What comes back
 *
 * | The query touches | You get |
 * |---|---|
 * | ordinary tables | rows, with personal-looking columns withheld |
 * | a table declared as holding personal data | the row **count** and the column names, no rows |
 *
 * The second is not a lesser answer for most questions. «How many live tokens
 * have no digest», «are there duplicate settings names», «how many images are
 * under this size» are all counts, and a count exposes nobody. Asking for the
 * rows themselves is a different request with a different risk, and it is one a
 * person should make deliberately rather than discover they have made.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class DbInspectTool implements ScopedMcpTool
{
    /** Rows returned at most, whatever the query asks for. */
    public const MAX_ROWS = 200;

    public function __construct(private readonly Database $db)
    {
    }

    public function name(): string
    {
        return 'db-inspect';
    }

    public function requiredScope(): string
    {
        return 'mcp:db_read';
    }

    public function description(): string
    {
        return 'Run one read-only SELECT against the live database. Rows from tables '
            . 'declared as holding personal data are not returned — those answer with a '
            . 'count and their column names. Personal-looking columns are withheld '
            . 'everywhere.';
    }

    public function inputSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'sql' => array(
                    'type'        => 'string',
                    'description' => 'One SELECT statement. WITH is allowed; anything '
                        . 'that writes is refused, including a data-modifying CTE.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Rows to return, at most ' . self::MAX_ROWS . '.',
                ),
            ),
            'required'   => array('sql'),
        );
    }

    public function execute(array $input): mixed
    {
        $sql = trim((string) ($input['sql'] ?? ''));

        if (!ReadOnlyQuery::isRead($sql, $reason)) {
            return array('error' => 'refused', 'reason' => $reason);
        }

        $limit = (int) ($input['limit'] ?? self::MAX_ROWS);
        $limit = max(1, min($limit, self::MAX_ROWS));

        $personal = $this->personalTablesIn($sql);

        // Logged before it runs, and with the statement: the record of what was
        // asked has to survive the query failing, and "what did that token read"
        // is the question an audit actually asks.
        \Pramnos\Logs\Logger::log(
            'db-inspect' . ($personal !== array() ? ' [personal: ' . implode(', ', $personal) . ']' : '')
            . ': ' . $sql,
            'mcpqueries'
        );

        // Outside the try on purpose. A read-only account that is configured and
        // unusable must not arrive as `query_failed`, which is what a typo in the
        // SQL looks like — the two need different people to do different things.
        $connection = $this->connection();

        try {
            $result = $connection->query($this->withLimit($sql, $limit));
        } catch (\Throwable $exception) {
            return array('error' => 'query_failed', 'reason' => $exception->getMessage());
        }

        if (!$result) {
            return array('error' => 'query_failed', 'reason' => 'The query returned nothing at all.');
        }

        $rows = $this->fetchRows($result, $limit);

        if ($personal !== array()) {
            return array(
                'personal_data'    => true,
                'tables'           => $personal,
                'row_count'        => count($rows),
                'columns'          => $rows === array() ? array() : array_keys($rows[0]),
                'rows_withheld'    => true,
                'note'             => 'These tables are declared as holding personal data, so '
                    . 'their rows are not returned. Counts and structure are. Narrow the query '
                    . 'to aggregates, or ask somebody with database access if you need the rows.',
            );
        }

        [$rows, $withheld] = $this->withholdPersonalColumns($rows);

        return array(
            'personal_data'      => false,
            'row_count'          => count($rows),
            'rows'               => $rows,
            'columns_withheld'   => $withheld,
            'truncated'          => count($rows) >= $limit,
        );
    }

    /**
     * The declared-personal tables this statement names.
     *
     * Read off `FROM` and `JOIN` on the comment-and-literal-stripped text. It is
     * a scan and not a parse, so it errs towards **finding** a table: a name that
     * looks like a personal table is treated as one, and the cost of that is a
     * count instead of rows.
     *
     * @return array<int, string>
     */
    private function personalTablesIn(string $sql): array
    {
        $stripped = ReadOnlyQuery::withoutStringsAndComments($sql);
        $found    = array();

        // `#` is in the class because framework SQL names `#PREFIX#users`, and a
        // table the scan cannot see is a table whose rows come back.
        if (preg_match_all('/\b(?:from|join)\s+([A-Za-z0-9_.#]+)/i', $stripped, $matches) !== false) {
            foreach ($matches[1] ?? array() as $table) {
                if (PersonalDataRegistry::isPersonalTable($table)) {
                    $found[PersonalDataRegistry::normalise($table)] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Remove the values of columns that look personal, and say which.
     *
     * The column stays, so the shape of the answer is still visible and the
     * caller can see that something was withheld rather than wonder why a column
     * is missing.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function withholdPersonalColumns(array $rows): array
    {
        if ($rows === array()) {
            return array($rows, array());
        }

        $withheld = array();

        foreach (array_keys($rows[0]) as $column) {
            if (PersonalDataRegistry::isPersonalColumn((string) $column)) {
                $withheld[] = (string) $column;
            }
        }

        if ($withheld === array()) {
            return array($rows, array());
        }

        foreach ($rows as $index => $row) {
            foreach ($withheld as $column) {
                if (array_key_exists($column, $row)) {
                    $rows[$index][$column] = '[withheld]';
                }
            }
        }

        return array($rows, $withheld);
    }

    /**
     * The connection to ask.
     *
     * A read-only account where the installation configured one, and the ordinary
     * connection where it did not. Setting one up is real work and an
     * installation may reasonably decide its developers are trusted; where it has
     * been done, the boundary stops being our lexer and starts being the
     * database, which is a much better place for it.
     */
    private function connection(): Database
    {
        $readonly = \Pramnos\Application\Settings::getSetting('database_readonly_dsn');

        if (!is_string($readonly) || trim($readonly) === '') {
            return $this->db;
        }

        try {
            return $this->readOnlyConnection(trim($readonly));
        } catch (\Throwable $exception) {
            // A misconfigured read-only account must not become a silent
            // downgrade to the writable one — that is the failure where somebody
            // believes they have a boundary and does not.
            throw new \RuntimeException(
                'A read-only database account is configured but could not be used: '
                . $exception->getMessage()
            );
        }
    }

    /**
     * Open the configured read-only connection.
     *
     * `user:password@host:port/database` — the same shape the rest of the
     * settings use, kept out of `app.php` because it is a credential.
     */
    private function readOnlyConnection(string $dsn): Database
    {
        $parts = parse_url('scheme://' . ltrim($dsn, '/'));

        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \RuntimeException('database_readonly_dsn is not user:pass@host:port/name');
        }

        $db           = new Database();
        $db->type     = $this->db->type;
        $db->server   = $parts['host'];
        $db->port     = (int) ($parts['port'] ?? $this->db->port);
        $db->user     = urldecode((string) ($parts['user'] ?? ''));
        $db->password = urldecode((string) ($parts['pass'] ?? ''));
        $db->database = ltrim((string) ($parts['path'] ?? ''), '/') ?: $this->db->database;
        $db->schema   = $this->db->schema;
        // The prefix and the flavour come from the application, not the DSN:
        // a read-only account is a second door onto the same database.
        $db->prefix    = $this->db->prefix;
        $db->timescale = $this->db->timescale;

        if (!$db->connect(false)) {
            throw new \RuntimeException('the connection was refused');
        }

        return $db;
    }

    /**
     * Put a ceiling on the statement.
     *
     * Appended only when the caller has not written one: a query that says
     * `LIMIT 5` means five, and rewriting it would be answering a different
     * question. The fetch loop stops at the ceiling either way, so a query with
     * `LIMIT 100000` cannot return more than {@see MAX_ROWS} rows regardless.
     */
    private function withLimit(string $sql, int $limit): string
    {
        $stripped = strtolower(ReadOnlyQuery::withoutStringsAndComments($sql));

        if (preg_match('/\blimit\b/', $stripped) === 1) {
            return $sql;
        }

        return rtrim(rtrim($sql), ';') . ' LIMIT ' . $limit;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(object $result, int $limit): array
    {
        $rows = array();

        while (count($rows) < $limit && method_exists($result, 'fetch') && $result->fetch()) {
            $fields = $result->fields ?? array();

            if (!is_array($fields)) {
                break;
            }

            $rows[] = $fields;
        }

        return $rows;
    }
}
