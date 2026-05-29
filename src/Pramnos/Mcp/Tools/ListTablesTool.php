<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Database\Database;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: list all tables in the connected database with row counts.
 *
 * Returns a JSON array of objects with 'table' and 'rows' keys.
 * Works across MySQL and PostgreSQL.
 *
 */
class ListTablesTool implements McpToolInterface
{
    public function __construct(private readonly Database $db) {}

    public function name(): string
    {
        return 'list-tables';
    }

    public function description(): string
    {
        return 'List all tables in the connected database with approximate row counts.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): mixed
    {
        if (!$this->db->connected) {
            return ['error' => 'Database not connected'];
        }

        if ($this->db->type === 'postgresql') {
            $sql = "SELECT table_name AS name,
                           (xpath('/row/cnt/text()', query_to_xml(
                               'SELECT COUNT(*) AS cnt FROM \"' || table_name || '\"',
                               false, true, ''
                           )))[1]::text::int AS row_count
                    FROM information_schema.tables
                    WHERE table_schema = 'public'
                      AND table_type   = 'BASE TABLE'
                    ORDER BY table_name";
        } else {
            $dbName = $this->db->database ?? '';
            $sql = "SELECT table_name AS name, table_rows AS row_count
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                      AND table_type   = 'BASE TABLE'
                    ORDER BY table_name";
        }

        $result = $this->db->query($sql);
        if (!$result) {
            return [];
        }

        $rows   = $result->fetchAll();
        $tables = [];
        foreach ($rows as $row) {
            $tables[] = [
                'table' => $row['name']      ?? '',
                'rows'  => (int) ($row['row_count'] ?? 0),
            ];
        }

        return $tables;
    }
}
