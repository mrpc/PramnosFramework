<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Limits;
use Pramnos\Llm\Analyst\ReadOnlySql;
use Pramnos\Mcp\McpToolInterface;

/**
 * One SELECT of the analyst's own, for a question no other tool answers — up to {@see MAX_ROWS}
 * rows, or `'llm' => ['analyst' => ['sql_rows' => …]]` (a number, or `fn(User): int` for a
 * per-role ceiling — {@see Limits}) — through the redaction every analyst tool's answer goes
 * through.
 *
 * Off unless the application turns it on: `'llm' => ['analyst' => ['sql' => true]]`. What makes
 * it safe to turn on is {@see ReadOnlySql}.
 */
final class SqlTool implements McpToolInterface
{
    public const MAX_ROWS = 200;

    /** @param int $adminId The account asking, for a per-role `sql_rows` ({@see Limits}) */
    public function __construct(private readonly int $adminId = 0)
    {
    }

    public function name(): string
    {
        return 'sql';
    }

    public function description(): string
    {
        $max = self::maxRows($this->adminId);

        return 'Run one read-only SELECT (or WITH … SELECT) against the installation\'s database, when no '
            . 'other tool answers the question. Use the schema tool first for table and column names. At '
            . 'most ' . $max . ' rows come back; ask for counts and groups rather than rows where '
            . 'you can. Writes, locks and slow functions are refused, and a statement stops after '
            . ReadOnlySql::seconds() . ' seconds.';
    }

    public function inputSchema(): array
    {
        $max = self::maxRows($this->adminId);

        return ['type' => 'object', 'required' => ['sql'], 'properties' => [
            'sql'   => ['type' => 'string', 'description' => 'One SELECT statement, with real table names.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return, at most ' . $max . '.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $max   = self::maxRows($this->adminId);
        $limit = max(1, min($max, (int) ($input['limit'] ?? $max)));
        $rows  = [];

        try {
            // One more than asked, to say whether there were more.
            ReadOnlySql::each(Factory::getDatabase(), (string) ($input['sql'] ?? ''), $limit + 1, static function (array $row) use (&$rows): void {
                $rows[] = $row;
            });
        } catch (\InvalidArgumentException $e) {
            return 'Refused: ' . $e->getMessage();
        } catch (\Throwable $e) {
            return 'The database refused it: ' . ReadOnlySql::said($e);
        }

        return [
            'rows'      => array_slice($rows, 0, $limit),
            'count'     => min(count($rows), $limit),
            'truncated' => count($rows) > $limit,
        ];
    }

    /** The row ceiling: `sql_rows`, or {@see MAX_ROWS}. */
    private static function maxRows(int $adminId): int
    {
        return max(1, (int) Limits::of('sql_rows', self::MAX_ROWS, $adminId));
    }
}
