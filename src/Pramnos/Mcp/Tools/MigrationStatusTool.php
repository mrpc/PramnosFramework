<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Application\Application;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: report pending and applied migration status.
 *
 * Returns counts plus a list of pending and recently-applied migrations so the
 * AI assistant can identify schema drift without accessing raw DB tables.
 *
 */
class MigrationStatusTool implements McpToolInterface
{
    public function __construct(private readonly Application $app) {}

    public function name(): string
    {
        return 'migration-status';
    }

    public function description(): string
    {
        return 'Show pending and applied migrations — counts, last applied migration, and all pending slugs.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): mixed
    {
        $db = $this->app->database ?? null;
        if ($db === null) {
            return ['error' => 'No database connection'];
        }

        $dirs       = $this->resolveDirs();
        $migrations = MigrationLoader::loadFromDirectories($dirs, $this->app);
        $runner     = new MigrationRunner($db);
        $history    = $runner->getHistory();

        $historyMap = [];
        foreach ($history as $row) {
            $historyMap[$row['key']] = $row;
        }

        $pending = [];
        $applied = [];
        foreach ($migrations as $migration) {
            $slug = $migration->getSlug();
            if (isset($historyMap[$slug])) {
                $row = $historyMap[$slug];
                if ((int) ($row['result'] ?? 0) === 1) {
                    $applied[] = [
                        'slug'  => $slug,
                        'batch' => $row['batch'] ?? '-',
                        'when'  => $row['when']  ?? '-',
                    ];
                }
                unset($historyMap[$slug]);
            } else {
                $pending[] = $slug;
            }
        }

        $lastApplied = !empty($applied) ? end($applied) : null;

        return [
            'pending_count'  => count($pending),
            'applied_count'  => count($applied),
            'pending'        => $pending,
            'last_applied'   => $lastApplied,
        ];
    }

    private function resolveDirs(): array
    {
        $root = defined('ROOT') ? ROOT : getcwd();
        $dirs = [$root . '/app/Migrations'];

        $base = dirname(__DIR__, 5) . '/database/migrations/framework';
        if (!is_dir($base)) {
            $base = $root . '/vendor/mrpc/pramnosframework/database/migrations/framework';
        }
        if (is_dir($base)) {
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $dirs[] = $d;
            }
        }
        return $dirs;
    }
}
