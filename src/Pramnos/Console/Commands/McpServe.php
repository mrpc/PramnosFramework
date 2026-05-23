<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpResource;
use Pramnos\Mcp\McpServiceProvider;
use Pramnos\Mcp\Tools\ListTablesTool;
use Pramnos\Mcp\Tools\MigrationStatusTool;
use Pramnos\Mcp\Tools\ModelInspectTool;
use Pramnos\Mcp\Tools\QuerySchemaTool;
use Pramnos\Mcp\Tools\RouteListTool;

/**
 * Start an MCP (Model Context Protocol) server on stdio.
 *
 * When invoked the command reads JSON-RPC 2.0 messages from STDIN and writes
 * responses to STDOUT. This allows AI assistants (Claude, Copilot, etc.) to
 * discover and call the application's built-in tools without a separate DB
 * MCP server.
 *
 * Register in .mcp.json:
 *   {
 *     "mcpServers": {
 *       "myapp": { "command": "./bin/pramnos", "args": ["mcp:serve"] }
 *     }
 *   }
 *
 * If the 'mcp' feature is registered in app.php and McpServiceProvider has
 * been booted, the server from the container is used (which may have
 * app-specific custom tools added). Otherwise a default server is built with
 * the five built-in tools.
 */
class McpServe extends Command
{
    protected function configure(): void
    {
        $this->setName('mcp:serve')
            ->setDescription('Start an MCP server on stdio for AI assistant integration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        $app        = $consoleApp instanceof \Pramnos\Console\Application
            ? $consoleApp->internalApplication
            : null;

        $server = $this->resolveServer($app);

        // Silence all PHP errors / notices to STDOUT — they would corrupt
        // the JSON-RPC stream. Real errors are caught inside McpServer::run().
        ini_set('display_errors', '0');
        ini_set('log_errors',     '1');

        $server->run();

        return Command::SUCCESS;
    }

    private function resolveServer(?\Pramnos\Application\Application $app): McpServer
    {
        // Prefer the container-bound server (has app-specific tools registered
        // via McpServiceProvider::boot())
        if ($app !== null
            && $app->container->has('mcp.server')) {
            /** @var McpServer $server */
            $server = $app->container->get('mcp.server');
            return $server;
        }

        // Fallback: build a default server with the five built-in tools
        $appName = (string) (\Pramnos\Application\Settings::getSetting('title')
                    ?: (defined('TITLE') ? TITLE : 'Pramnos App'));
        $appVersion = defined('VERSION') ? VERSION : '1.0.0';

        $server = new McpServer($appName, $appVersion);

        if ($app !== null) {
            $db = $app->database ?? null;
            if ($db !== null) {
                $server->addTool(new ListTablesTool($db));
                $server->addTool(new QuerySchemaTool($db));
            }
            $server->addTool(new MigrationStatusTool($app));
            $server->addTool(new ModelInspectTool());
            $server->addTool(new RouteListTool($app));

            $root = defined('ROOT') ? ROOT : getcwd();
            foreach ([
                ['file://CLAUDE.md',   'Claude Code guide',  $root . '/CLAUDE.md'],
                ['file://README.md',   'Project README',     $root . '/README.md'],
                ['file://app/app.php', 'App config',         $root . '/app/app.php'],
            ] as [$uri, $name, $path]) {
                if (is_file($path)) {
                    $server->addResource(new McpResource($uri, $name, $path));
                }
            }
        }

        return $server;
    }
}
