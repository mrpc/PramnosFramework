<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

use Pramnos\Application\ServiceProvider;
use Pramnos\Mcp\Tools\FrameworkDocsTool;
use Pramnos\Mcp\Tools\PramnosCheckTool;
use Pramnos\Mcp\Tools\ListTablesTool;
use Pramnos\Mcp\Tools\MigrationStatusTool;
use Pramnos\Mcp\Tools\ModelInspectTool;
use Pramnos\Mcp\Tools\QuerySchemaTool;
use Pramnos\Mcp\Tools\RouteListTool;

/**
 * Bootstraps the MCP server with the application's built-in tools and resources.
 *
 * Opt-in via app.php features list (feature key: 'mcp'):
 *
 *   'features' => ['mcp'],
 *
 * The McpServer singleton is registered in the container under 'mcp.server' so
 * apps can add custom tools in their own service providers:
 *
 *   $server = $app->getContainer()->get('mcp.server');
 *   $server->addTool(new MyCustomTool());
 *
 * The `pramnos mcp:serve` command reads the server from the container when it
 * starts, so registrations done in boot() are included automatically.
 *
 */
class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;

        $appName    = (string) (\Pramnos\Application\Settings::getSetting('title')
                        ?: (defined('TITLE') ? TITLE : 'Pramnos App'));
        $appVersion = defined('VERSION') ? VERSION : '1.0.0';

        $server = new McpServer((string) $appName, (string) $appVersion);

        $app->getContainer()->singleton('mcp.server', fn() => $server);
    }

    public function boot(): void
    {
        $app = $this->app;

        if (!$app->getContainer()->has('mcp.server')) {
            return;
        }

        /** @var McpServer $server */
        $server = $app->getContainer()->get('mcp.server');

        $db = $app->database ?? null;
        if ($db !== null) {
            $server->addTool(new ListTablesTool($db));
            $server->addTool(new QuerySchemaTool($db));
        }

        $server->addTool(new MigrationStatusTool($app));
        $server->addTool(new ModelInspectTool());
        $server->addTool(new RouteListTool($app));

        // Not application introspection like the five above — this one answers "how
        // does the framework work", from the guides vendored alongside this class. It
        // takes no application, because it is the same answer in every project.
        $server->addTool(new FrameworkDocsTool());

        // The other half of the same idea. `framework-docs` lets an assistant *find* a rule;
        // this one tells it when a rule has been broken — and only the second has evidence
        // behind it, since every rule it checks is something that happened after the guide
        // describing it was written.
        $server->addTool(new PramnosCheckTool());

        // Register standard file resources
        $root = defined('ROOT') ? ROOT : getcwd();
        foreach ($this->defaultResources($root) as [$uri, $name, $path]) {
            if (is_file($path)) {
                $server->addResource(new McpResource($uri, $name, $path));
            }
        }
    }

    /** @return list<array{string, string, string}> */
    private function defaultResources(string $root): array
    {
        return [
            ['file://CLAUDE.md',     'Claude Code guide',       $root . '/CLAUDE.md'],
            ['file://README.md',     'Project README',          $root . '/README.md'],
            ['file://app/app.php',   'Application config',      $root . '/app/app.php'],
        ];
    }
}
