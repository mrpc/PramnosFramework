<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Framework\Factory;
use Pramnos\Mcp\McpToolInterface;

/**
 * The tables and their columns, as `sql` and `export_csv` need them named — and what the
 * application says they mean, which the catalogue cannot:
 *
 * ```php
 * 'llm' => ['analyst' => ['schema' => [
 *     'orders' => ['about' => 'One customer order.', 'columns' => ['status' => '1 open, 2 paid, 3 refunded']],
 * ]]],
 * ```
 *
 * Read with `SchemaBuilder`, which pins `information_schema` to this database.
 */
final class SchemaTool implements McpToolInterface
{
    public function name(): string
    {
        return 'schema';
    }

    public function description(): string
    {
        return 'The database\'s tables, or one table\'s columns: type, nullable, primary and foreign keys, '
            . 'and what the application says a table or a column\'s codes mean. Use it before writing SQL.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'table' => ['type' => 'string', 'description' => 'One table\'s columns; every table without it.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $db     = Factory::getDatabase();
        $schema = $db->schema();
        $notes  = self::notes((string) $db->prefix);
        $tables = $schema->tableNames();
        $table  = trim((string) ($input['table'] ?? ''));

        if ($table === '') {
            $list = [];
            foreach ($tables as $name) {
                $list[$name] = (string) ($notes[$name]['about'] ?? '');
            }

            return ['tables' => $list];
        }

        if (!in_array($table, $tables, true)) {
            return 'There is no table called ' . $table . '. Ask without a table for the list.';
        }

        $columns = [];
        foreach ($schema->columnDetails($table) as $name => $column) {
            $columns[$name] = array_filter([
                'type'       => $column['type'],
                'nullable'   => $column['nullable'],
                'primary'    => $column['isPrimary'],
                'references' => $column['isForeign'] ? $column['foreignTable'] . '.' . $column['foreignColumn'] : '',
                'means'      => (string) ($notes[$table]['columns'][$name] ?? ''),
            ], static fn ($v) => $v !== '' && $v !== false);
        }

        return ['table' => $table, 'about' => (string) ($notes[$table]['about'] ?? ''), 'columns' => $columns];
    }

    /**
     * The application's notes, keyed as the catalogue names the tables — with the prefix, which
     * the application leaves off.
     *
     * @return array<string, array{about?: string, columns?: array<string, string>}>
     */
    private static function notes(string $prefix): array
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);
        $notes  = [];

        foreach (is_array($config) && is_array($config['schema'] ?? null) ? $config['schema'] : [] as $table => $note) {
            $table = (string) $table;
            $notes[$prefix !== '' && !str_starts_with($table, $prefix) ? $prefix . $table : $table] = is_array($note) ? $note : [];
        }

        return $notes;
    }
}
