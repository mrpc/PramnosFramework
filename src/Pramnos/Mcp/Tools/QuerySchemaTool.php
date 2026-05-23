<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Database\Database;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: return full schema of a specific database table.
 *
 * Returns columns (name, type, nullable, default), indexes, and foreign keys.
 * Works across MySQL 8.0 and PostgreSQL 14+.
 *
 * @package PramnosFramework
 */
class QuerySchemaTool implements McpToolInterface
{
    public function __construct(private readonly Database $db) {}

    public function name(): string
    {
        return 'query-schema';
    }

    public function description(): string
    {
        return 'Return the full schema of a database table (columns, types, indexes, foreign keys).';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'table' => [
                    'type'        => 'string',
                    'description' => 'Table name to inspect.',
                ],
            ],
            'required' => ['table'],
        ];
    }

    public function execute(array $input): mixed
    {
        $table = trim($input['table'] ?? '');
        if ($table === '') {
            return ['error' => 'table parameter is required'];
        }

        if (!$this->db->connected) {
            return ['error' => 'Database not connected'];
        }

        return [
            'table'        => $table,
            'columns'      => $this->fetchColumns($table),
            'indexes'      => $this->fetchIndexes($table),
            'foreign_keys' => $this->fetchForeignKeys($table),
        ];
    }

    private function fetchColumns(string $table): array
    {
        if ($this->db->type === 'postgresql') {
            $sql = $this->db->prepareQuery(
                "SELECT column_name, data_type, is_nullable, column_default, character_maximum_length
                 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = %s
                 ORDER BY ordinal_position",
                $table
            );
        } else {
            $sql = $this->db->prepareQuery(
                "SELECT column_name, column_type AS data_type,
                        is_nullable, column_default, character_maximum_length
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = %s
                 ORDER BY ordinal_position",
                $table
            );
        }

        $result = $this->db->query($sql);
        return $result ? $result->fetchAll() : [];
    }

    private function fetchIndexes(string $table): array
    {
        if ($this->db->type === 'postgresql') {
            $sql = $this->db->prepareQuery(
                "SELECT indexname AS name, indexdef AS definition
                 FROM pg_indexes
                 WHERE schemaname = 'public' AND tablename = %s
                 ORDER BY indexname",
                $table
            );
        } else {
            $sql = $this->db->prepareQuery(
                "SELECT index_name AS name, non_unique, seq_in_index,
                        column_name, index_type
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = %s
                 ORDER BY index_name, seq_in_index",
                $table
            );
        }

        $result = $this->db->query($sql);
        return $result ? $result->fetchAll() : [];
    }

    private function fetchForeignKeys(string $table): array
    {
        if ($this->db->type === 'postgresql') {
            $sql = $this->db->prepareQuery(
                "SELECT conname AS name,
                        kcu.column_name,
                        ccu.table_name  AS referenced_table,
                        ccu.column_name AS referenced_column,
                        rc.update_rule,
                        rc.delete_rule
                 FROM information_schema.table_constraints AS tc
                 JOIN information_schema.key_column_usage AS kcu
                   ON tc.constraint_name = kcu.constraint_name
                  AND tc.table_schema    = kcu.table_schema
                 JOIN information_schema.constraint_column_usage AS ccu
                   ON ccu.constraint_name = tc.constraint_name
                  AND ccu.table_schema    = tc.table_schema
                 JOIN information_schema.referential_constraints AS rc
                   ON rc.constraint_name = tc.constraint_name
                  AND rc.constraint_schema = tc.table_schema
                 WHERE tc.constraint_type = 'FOREIGN KEY'
                   AND tc.table_schema = 'public'
                   AND tc.table_name   = %s",
                $table
            );
        } else {
            $sql = $this->db->prepareQuery(
                "SELECT constraint_name AS name,
                        column_name,
                        referenced_table_name  AS referenced_table,
                        referenced_column_name AS referenced_column,
                        update_rule,
                        delete_rule
                 FROM information_schema.key_column_usage kcu
                 JOIN information_schema.referential_constraints rc
                   ON rc.constraint_name = kcu.constraint_name
                  AND rc.constraint_schema = kcu.table_schema
                 WHERE kcu.table_schema = DATABASE() AND kcu.table_name = %s",
                $table
            );
        }

        $result = $this->db->query($sql);
        return $result ? $result->fetchAll() : [];
    }
}
