<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Mcp\McpServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Call one MCP tool from a terminal and look at what it returns.
 *
 * `mcp:serve` is not something a person can debug. It speaks JSON-RPC on stdio and blocks
 * on STDIN, so answering "what does this tool actually return" meant hand-writing an
 * `initialize` frame and a `tools/call` frame, piping them in, and reading one very long
 * line of JSON. Everybody who tried did it wrong the first time, and a mistake in the
 * frame is indistinguishable from a broken tool.
 *
 *   php <cli> mcp:call                                    # list the tools and their schemas
 *   php <cli> mcp:call log-analytics
 *   php <cli> mcp:call log-analytics --arg timespan=6h
 *   php <cli> mcp:call log-errors --json '{"limit": 5}'
 *   php <cli> mcp:call route-list --raw                    # the JSON-RPC envelope, unwrapped
 *
 * It goes through `McpServer::dispatch()` — the same method the stdio loop calls — rather
 * than reaching for the tool object. A tool that works when called directly and fails
 * through the protocol is exactly the bug this command has to be able to show.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class McpCall extends Command
{
    protected function configure(): void
    {
        $this->setName('mcp:call')
            ->setDescription('Call one MCP tool and print what it returns (no client needed)')
            ->addArgument(
                'tool',
                InputArgument::OPTIONAL,
                'Tool name. Omit to list every tool with its input schema.'
            )
            ->addOption(
                'arg',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'One argument as key=value. Repeatable. Use --json for anything nested.'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_REQUIRED,
                'The whole arguments object as JSON.'
            )
            ->addOption(
                'raw',
                null,
                InputOption::VALUE_NONE,
                'Print the JSON-RPC response as it goes over the wire.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        $app        = $consoleApp instanceof \Pramnos\Console\Application
            ? $consoleApp->internalApplication
            : null;

        $server = $this->server($app);
        $tool   = (string) ($input->getArgument('tool') ?? '');

        if ($tool === '') {
            return $this->listTools($server, $output);
        }

        if (!isset($server->getTools()[$tool])) {
            $output->writeln('<error>No tool named ' . $tool . '.</error>');
            $output->writeln('Registered: ' . implode(', ', array_keys($server->getTools())));

            return Command::FAILURE;
        }

        $arguments = $this->arguments($input, $output);

        if ($arguments === null) {
            return Command::FAILURE;
        }

        // Through dispatch(), deliberately — see the class comment.
        $started  = microtime(true);
        $response = $server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $tool, 'arguments' => $arguments],
        ]);
        $elapsed = microtime(true) - $started;

        return $this->report($tool, $arguments, $response ?? [], $elapsed, $input, $output);
    }

    /**
     * The server, from the container when there is one.
     *
     * Same resolution `mcp:serve` does, so this command sees the application's own tools
     * and not just the framework's — a project that registered a tool in a service
     * provider needs to be able to call it here.
     *
     * Protected because it is the one seam a test needs: everything else in this command
     * is about presenting what a tool returned, and a test cannot assert that against
     * whatever tools happen to be registered.
     */
    protected function server(?\Pramnos\Application\Application $app): McpServer
    {
        if ($app !== null && $app->getContainer()->has('mcp.server')) {
            /** @var McpServer $server */
            $server = $app->getContainer()->get('mcp.server');

            return $server;
        }

        $server = new McpServer(
            defined('TITLE') && TITLE !== '' ? (string) TITLE : 'Pramnos App',
            defined('VERSION') ? VERSION : '1.0.0'
        );

        \Pramnos\Mcp\McpServiceProvider::registerDefaults($server, $app);

        return $server;
    }

    /**
     * Every tool, with the schema a caller has to satisfy.
     *
     * The schema rather than only the name: the first question after "which tools are
     * there" is always "what does this one take", and the answer is otherwise only
     * visible to a client.
     */
    private function listTools(McpServer $server, OutputInterface $output): int
    {
        $tools = $server->getTools();

        if ($tools === []) {
            $output->writeln('<comment>No tools are registered.</comment>');

            return Command::SUCCESS;
        }

        foreach ($tools as $tool) {
            $output->writeln('<info>' . $tool->name() . '</info>');
            $output->writeln('  ' . wordwrap($tool->description(), 76, "\n  "));

            $properties = $tool->inputSchema()['properties'] ?? [];

            if ($properties === []) {
                $output->writeln('  <comment>takes no arguments</comment>');
            }

            foreach ($properties as $name => $spec) {
                $type = (string) ($spec['type'] ?? 'mixed');
                $enum = isset($spec['enum']) ? ' (' . implode('|', (array) $spec['enum']) . ')' : '';
                $output->writeln('  · ' . $name . ': ' . $type . $enum);
            }

            $output->writeln('');
        }

        $output->writeln('Call one with: <info>mcp:call ' . array_key_first($tools)
            . ' --arg key=value</info>');

        return Command::SUCCESS;
    }

    /**
     * The arguments object, from `--json` or repeated `--arg`.
     *
     * `--json` wins when both are given rather than being merged: a half-merged
     * arguments object is a call nobody asked for, and the schemas here are small enough
     * that saying which one lost is more useful than guessing.
     *
     * @return array<string, mixed>|null Null when the input could not be read
     */
    private function arguments(InputInterface $input, OutputInterface $output): ?array
    {
        $json = $input->getOption('json');

        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);

            if (!is_array($decoded)) {
                $output->writeln('<error>--json is not a JSON object: '
                    . json_last_error_msg() . '</error>');

                return null;
            }

            if ($input->getOption('arg') !== []) {
                $output->writeln('<comment>--json given, so --arg is ignored.</comment>');
            }

            return $decoded;
        }

        $arguments = [];

        foreach ((array) $input->getOption('arg') as $pair) {
            $pair = (string) $pair;

            if (!str_contains($pair, '=')) {
                $output->writeln('<error>--arg wants key=value, got: ' . $pair . '</error>');

                return null;
            }

            [$key, $value] = explode('=', $pair, 2);
            $arguments[trim($key)] = $this->coerce($value);
        }

        return $arguments;
    }

    /**
     * `key=5` means the number, `key=true` the boolean, `key=a,b` the list.
     *
     * A shell has only strings, and every schema here that takes a number or an array
     * would otherwise reject the obvious spelling — `--arg limit=5` failing validation
     * for being `"5"` is the sort of thing that makes a debugging tool useless. Quote it
     * or use `--json` when a literal string is meant.
     */
    private function coerce(string $value): mixed
    {
        $trimmed = trim($value);

        return match (true) {
            $trimmed === 'true'   => true,
            $trimmed === 'false'  => false,
            $trimmed === 'null'   => null,
            is_numeric($trimmed)  => $trimmed + 0,
            str_contains($trimmed, ',') => array_map(
                fn (string $part): mixed => $this->coerce($part),
                explode(',', $trimmed)
            ),
            default => $value,
        };
    }

    /**
     * What came back, in the shape a person can read.
     *
     * The text content is printed as text. A tool that returned an array has already
     * been JSON-encoded by the server, and printing that JSON is the whole point — it is
     * literally what the assistant will see, which is not always what the author of the
     * tool intended it to see.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $response
     */
    private function report(
        string $tool,
        array $arguments,
        array $response,
        float $elapsed,
        InputInterface $input,
        OutputInterface $output
    ): int {
        if ($input->getOption('raw')) {
            $output->writeln((string) json_encode(
                $response,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return isset($response['error']) ? Command::FAILURE : Command::SUCCESS;
        }

        $output->writeln('<info>' . $tool . '</info> '
            . ($arguments === [] ? '(no arguments)' : (string) json_encode($arguments))
            . '  <comment>' . round($elapsed * 1000) . ' ms</comment>');
        $output->writeln('');

        if (isset($response['error'])) {
            $output->writeln('<error>JSON-RPC error ' . ($response['error']['code'] ?? '')
                . ': ' . ($response['error']['message'] ?? '') . '</error>');

            return Command::FAILURE;
        }

        $result = $response['result'] ?? [];
        $failed = !empty($result['isError']);

        foreach ((array) ($result['content'] ?? []) as $block) {
            $text = (string) ($block['text'] ?? '');
            $output->writeln($failed ? '<error>' . $text . '</error>' : $text);
        }

        if ($failed) {
            // A tool that threw comes back as a *successful* JSON-RPC response whose
            // content is the exception message. Without this line it reads as output.
            $output->writeln('');
            $output->writeln('<error>The tool reported a failure (isError).</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
