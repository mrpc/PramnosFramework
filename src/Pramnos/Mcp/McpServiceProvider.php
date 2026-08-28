<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

use Pramnos\Application\ServiceProvider;
use Pramnos\Mcp\Tools\ApiDocsTool;
use Pramnos\Mcp\Tools\ConsoleCommandsTool;
use Pramnos\Mcp\Tools\CoverageTool;
use Pramnos\Mcp\Tools\FindSymbolTool;
use Pramnos\Mcp\Tools\FindTestsTool;
use Pramnos\Mcp\Tools\FrameworkDocsTool;
use Pramnos\Mcp\Tools\LogAnalyticsTool;
use Pramnos\Mcp\Tools\LogErrorsTool;
use Pramnos\Mcp\Tools\PramnosCheckTool;
use Pramnos\Mcp\Tools\ThemeInfoTool;
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

        self::registerDefaults($server, $app);
    }

    /**
     * Every tool and resource the framework ships, on one server.
     *
     * **Public and static because there are two callers.** This provider is one; the other is
     * `mcp:serve`, which builds a server of its own when no container has one — the console can
     * reach an application without initialising it, and it can be run with no application at
     * all. That fallback used to carry its own copy of this list, and the copy went stale: two
     * tools were added here and the command went on advertising seven, so an assistant launching
     * the server the documented way could not call them. There is one list now.
     *
     * @param ?\Pramnos\Application\Application $app Null when there is no application — the
     *                                                framework's own tools still register
     */
    public static function registerDefaults(
        McpServer $server,
        ?\Pramnos\Application\Application $app = null
    ): void {
        // Outside the `$app` guard, deliberately. These answer questions about the framework
        // rather than about this application, so a missing application makes nothing
        // unanswerable — and a server booting without one is exactly when somebody is asking
        // how any of this is supposed to work.
        //
        // `framework-docs` lets an assistant *find* a rule; `pramnos-check` tells it when one
        // has been broken, and only the second has evidence behind it, since every rule it
        // checks is something that happened after the guide describing it was written.
        $server->addTool(new FrameworkDocsTool());
        $server->addTool(new PramnosCheckTool());

        /*
         * Where a symbol lives and who calls it.
         *
         * The question `grep` cannot answer, and the gap was found by hitting it: tracing
         * which code ran a particular query took eight greps and then a patch to the query
         * builder that dumped a backtrace. Grep finds strings; the calling line of the actual
         * caller contained neither word of the thing being searched for.
         *
         * No application needed — it reads source files, which are there whether or not
         * anything boots.
         */
        $server->addTool(new FindSymbolTool());

        /*
         * What this CLI can do, from the live console definition.
         *
         * Twenty of the seventy commands generate code — `create:crud`, `create:screen`,
         * `create:api-client`, `create:policy`. The reason this is a tool is that an assistant
         * working in the codebase all day does not find out: it writes a controller by hand
         * rather than running `create:controller`, because nothing told it the command exists,
         * and `--help` on seventy commands is not a discovery mechanism.
         *
         * Read from the console rather than from a list kept here — a second catalogue is a
         * second thing to forget, and this file has already been bitten by exactly that.
         */
        $server->addTool(new ConsoleCommandsTool());

        /*
         * The palette, and whether the stylesheet on disk was built from it.
         *
         * The second half is the reason. daisyUI is a Tailwind *plugin*, so a project that
         * edits `app.css` and does not rebuild serves a stylesheet in which its component
         * classes resolve to nothing: the page renders, unstyled, with no error anywhere. And
         * the compiled file is committed on purpose — so a checkout serves the site without
         * npm — which makes it exactly the kind of artifact somebody forgets to regenerate.
         */
        $server->addTool(new ThemeInfoTool());

        /*
         * What the API *promises*, as opposed to which URIs exist.
         *
         * Parameters, request bodies, response codes, which credential each operation needs —
         * the shape an integration is written against. And the same freshness question as the
         * stylesheet, for the same reason: the OpenAPI document is a generated file that is
         * committed, so a controller can gain a parameter while the published document goes on
         * describing the old shape. Nothing fails. The API works and the documentation lies.
         */
        $server->addTool(new ApiDocsTool());

        /*
         * Where the test for this is — read from `#[CoversClass]`, not guessed from a filename.
         *
         * Guessing has a wrong answer often enough to matter: `Pramnos\Logs\LogManager` is
         * tested in `tests/Unit/Pramnos/Logs/`, not `tests/Unit/Logs/`, and writing to a
         * directory that does not exist puts a new test somewhere nobody will find it.
         *
         * It reports the command and does not run it. Running tests is something a shell does
         * well, and wrapping it would hide the project's own rule about *how* — these projects
         * hold a lock, and two concurrent runs corrupt the shared test databases.
         */
        $server->addTool(new FindTestsTool());

        /*
         * Which lines of *this change* no test touches.
         *
         * The rule is coverage above 95% on changed code, and it was unverifiable: a coverage
         * run produces a project-wide percentage, which barely moves when fifty uncovered
         * lines are added to twenty thousand. So it was followed by assumption. This reads the
         * clover report and intersects it with the diff, which is a list short enough to act
         * on — and it reads rather than runs, because the test script holds a lock.
         */
        $server->addTool(new CoverageTool());

        /*
         * What is going wrong right now, and what it says.
         *
         * These numbers existed in one place — the `/admin/logs` dashboard — reachable only by a
         * human with an administrator's session. Which is the wrong shape for the first question
         * anybody asks about an installation: an assistant asked to look at a problem had to be
         * handed a pasted log file, which has no counts, no rates and no idea what it left out.
         *
         * Two tools rather than one, because they answer different questions: the summary says
         * whether something is wrong and how much, and a hundred stack traces say nothing until
         * somebody reads them.
         *
         * No application needed — they read the log directory, which is where a broken
         * application's explanation is.
         */
        $server->addTool(new LogAnalyticsTool());
        $server->addTool(new LogErrorsTool());

        if ($app === null) {
            return;
        }

        // Application introspection: what exists in *this* project. Two of them need a
        // database and are skipped without one.
        $db = $app->database ?? null;

        if ($db !== null) {
            $server->addTool(new ListTablesTool($db));
            $server->addTool(new QuerySchemaTool($db));
        }

        $server->addTool(new MigrationStatusTool($app));
        $server->addTool(new ModelInspectTool());
        $server->addTool(new RouteListTool($app));

        $root = defined('ROOT') ? ROOT : getcwd();

        foreach (self::defaultResources((string) $root) as [$uri, $name, $path]) {
            if (is_file($path)) {
                $server->addResource(new McpResource($uri, $name, $path));
            }
        }
    }

    /** @return list<array{string, string, string}> */
    private static function defaultResources(string $root): array
    {
        return [
            ['file://CLAUDE.md',     'Claude Code guide',       $root . '/CLAUDE.md'],
            ['file://README.md',     'Project README',          $root . '/README.md'],
            ['file://app/app.php',   'Application config',      $root . '/app/app.php'],
        ];
    }
}
