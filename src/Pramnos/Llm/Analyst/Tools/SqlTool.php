<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\ReadOnlySql;
use Pramnos\Mcp\McpToolInterface;

/**
 * One SELECT of the analyst's own, for a question no other tool answers — up to
 * {@see MAX_ROWS} rows, through the redaction every analyst tool's answer goes through.
 *
 * Off unless the application turns it on: `'llm' => ['analyst' => ['sql' => true]]`. What makes
 * it safe to turn on is {@see ReadOnlySql}.
 */
final class SqlTool implements McpToolInterface
{
    public const MAX_ROWS = 200;

    public function name(): string
    {
        return 'sql';
    }

    public function description(): string
    {
        return 'Run one read-only SELECT (or WITH … SELECT) against the installation\'s database, when no '
            . 'other tool answers the question. Use the schema tool first for table and column names. At '
            . 'most ' . self::MAX_ROWS . ' rows come back; ask for counts and groups rather than rows where '
            . 'you can. Writes, locks and slow functions are refused, and a statement stops after '
            . ReadOnlySql::seconds() . ' seconds.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['sql'], 'properties' => [
            'sql'   => ['type' => 'string', 'description' => 'One SELECT statement, with real table names.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return, at most ' . self::MAX_ROWS . '.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $limit = max(1, min(self::MAX_ROWS, (int) ($input['limit'] ?? self::MAX_ROWS)));
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
}
