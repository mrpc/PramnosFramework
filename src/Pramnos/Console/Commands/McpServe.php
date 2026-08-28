<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpServiceProvider;

/**
 * Start an MCP (Model Context Protocol) server on stdio.
 *
 * When invoked the command reads JSON-RPC 2.0 messages from STDIN and writes
 * responses to STDOUT. This allows AI assistants (Claude, Copilot, etc.) to
 * discover and call the application's built-in tools without a separate DB
 * MCP server.
 *
 * Register in .mcp.json. `pramnos init` writes this file; the shape depends on
 * where the CLI and the database are:
 *
 *   // a project without Docker — the CLI is <cliName>.php at the project root
 *   { "mcpServers": { "myapp": {
 *       "command": "php", "args": ["myapp.php", "mcp:serve"] } } }
 *
 *   // a Docker project — the database is only reachable inside the container, and
 *   // -T is required because MCP speaks stdio over the pipe
 *   { "mcpServers": { "myapp": { "command": "docker-compose", "args":
 *       ["exec", "-T", "-u", "www-data", "app", "php", "myapp.php", "mcp:serve"] } } }
 *
 * `./bin/pramnos` works only inside the framework's own repository — in a project
 * that path does not exist, which is what every scaffolded .mcp.json used to say.
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
            ->setDescription('Start an MCP server on stdio for AI assistant integration')
            ->addOption(
                'log',
                null,
                InputOption::VALUE_OPTIONAL,
                'Record every JSON-RPC message to a file (default: the log directory\'s mcp.log)',
                false
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        $app        = $consoleApp instanceof \Pramnos\Console\Application
            ? $consoleApp->internalApplication
            : null;

        $server = $this->resolveServer($app);

        // `--log` with no value, `--log=/path`, or absent. VALUE_OPTIONAL gives null for
        // the first and false for the third, which is the only way to tell them apart.
        $log = $input->getOption('log');

        if ($log !== false) {
            $server->setTrafficLog($this->trafficLogPath(is_string($log) ? $log : null));
        }

        $this->announce($server, $output);

        // Silence all PHP errors / notices to STDOUT — they would corrupt
        // the JSON-RPC stream. Real errors are caught inside McpServer::run().
        ini_set('display_errors', '0');
        ini_set('log_errors',     '1');

        $server->run();

        return Command::SUCCESS;
    }

    /**
     * Where the traffic log goes.
     *
     * Defaults into the framework's own log directory rather than the working directory:
     * the file is written in the structured format the log viewer reads, so it shows up
     * beside every other log without anybody configuring anything. `log-errors
     * --files mcp.log` then answers "which call failed", which is the question.
     */
    private function trafficLogPath(?string $given): string
    {
        if ($given !== null && trim($given) !== '') {
            return $given;
        }

        return \Pramnos\Logs\LogManager::getLogFilePath('mcp', 'log');
    }

    /**
     * Say what is being served — on STDERR, never STDOUT.
     *
     * STDOUT is the JSON-RPC channel. A banner there is not cosmetic damage: the
     * client parses the stream, fails on the first line, and reports the server
     * as broken. STDERR has no such contract — MCP clients route it to a log and
     * ignore it — so it is the only place a human-facing word can go.
     *
     * And a word is needed. Run by hand the command otherwise prints nothing and
     * blocks on STDIN, which is indistinguishable from a hang; the one thing the
     * reader needs to know is that it is waiting for a client rather than for
     * them.
     */
    private function announce(McpServer $server, OutputInterface $output): void
    {
        $stderr = $this->errorOutput($output);
        if ($stderr === null) {
            return;
        }

        $tools     = $server->getTools();
        $resources = $server->getResources();

        $stderr->writeln('<info>MCP server ready on stdio.</info>');
        $stderr->writeln(sprintf(
            '  %d tool%s: %s',
            count($tools),
            count($tools) === 1 ? '' : 's',
            $tools === [] ? '(none)' : implode(', ', array_map(fn($t) => $t->name(), $tools))
        ));
        if ($resources !== []) {
            $stderr->writeln('  ' . count($resources) . ' resources: '
                . implode(', ', array_map(fn($r) => $r->name, $resources)));
        }
        if ($server->getTrafficLog() !== null) {
            // Said out loud. A traffic log that nobody knows the path of is a file that
            // gets left switched on, and the payloads are whatever the tools returned.
            $stderr->writeln('  Logging every message to ' . $server->getTrafficLog());
        }

        $stderr->writeln('  Waiting for JSON-RPC on stdin — this is normally launched by an');
        $stderr->writeln('  MCP client (see .mcp.json), not run by hand. Ctrl-C to stop.');
    }

    /**
     * The stream a human-facing message may go to, or null when there is none.
     *
     * A non-console output — a test, an embedded runner — has no separate error
     * stream, and staying silent is better than risking STDOUT. Protected so a
     * test can hand in a buffer: capturing the real STDERR through Symfony's
     * tester means `capture_stderr_separately`, which reaches for
     * ReflectionProperty::setAccessible() and is deprecated on PHP 8.5.
     */
    protected function errorOutput(OutputInterface $output): ?OutputInterface
    {
        return $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : null;
    }

    /**
     * What to call this application, in the one place a client shows a name.
     *
     * `app/app.php`'s `name` first. An MCP client lists its servers by this string,
     * so "Pramnos App" for every project means a picker in which no two projects can
     * be told apart — and the name is right there in a file the console already
     * reads, with no database involved.
     *
     * That ordering matters on PostgreSQL in particular: a database-stored title is
     * reachable only through `Settings`, and a project whose settings load fails for
     * any reason fell all the way through to the constant. The configuration file
     * cannot fail that way.
     *
     * @param  object|null $app
     * @return string
     */
    private static function applicationName(?object $app): string
    {
        $configured = $app->applicationInfo['name'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $title = \Pramnos\Application\Settings::getSetting('title');
        if (is_string($title) && trim($title) !== '') {
            return trim($title);
        }

        return defined('TITLE') && TITLE !== '' ? (string) TITLE : 'Pramnos App';
    }

    private function resolveServer(?\Pramnos\Application\Application $app): McpServer
    {
        // Prefer the container-bound server (has app-specific tools registered
        // via McpServiceProvider::boot()).
        //
        // getContainer() rather than ->container: the console reaches the
        // application without initialising it, so nothing had created a
        // container and this line died with "Call to a member function has() on
        // null" — the command could not start at all.
        if ($app !== null
            && $app->getContainer()->has('mcp.server')) {
            /** @var McpServer $server */
            $server = $app->getContainer()->get('mcp.server');
            return $server;
        }

        // Fallback: a default server, with the same tools the provider would have added.
        //
        // One list, in `McpServiceProvider::registerDefaults()`. This branch used to hold a
        // second copy of it, and the copy went stale — two tools were added to the provider and
        // this command went on advertising seven, so the tools were unreachable through the
        // documented way of launching the server. A catalogue in two places is a catalogue that
        // disagrees with itself.
        $server = new McpServer(self::applicationName($app), defined('VERSION') ? VERSION : '1.0.0');

        McpServiceProvider::registerDefaults($server, $app);

        return $server;
    }
}
