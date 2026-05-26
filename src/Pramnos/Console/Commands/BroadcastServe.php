<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Console\CommandBase;

/**
 * Local-dev WebSocket broadcasting server.
 *
 * Starts a pure-PHP WebSocket server that implements the Pusher wire protocol
 * (v7), so pramnos-echo.js clients can connect without any driver change.
 *
 * The server receives broadcasts from the application in one of two ways:
 *
 * 1. **Log-file tail** (default): The application uses `LogDriver` with a
 *    shared log file (default: `var/broadcast.jsonl`). The daemon polls that
 *    file and pushes new entries to subscribed WebSocket clients.
 *
 * 2. **Direct broadcast**: Any code that has access to the same process (e.g.
 *    test helpers) may call `$server->broadcast(channel, event, data)` directly.
 *
 * ## Usage
 *
 * ```bash
 * php ./bin/pramnos broadcast:serve
 * php ./bin/pramnos broadcast:serve --port=6001 --host=127.0.0.1
 * php ./bin/pramnos broadcast:serve --log-file=/tmp/pramnos-broadcast.jsonl
 * php ./bin/pramnos broadcast:serve --verbose   # shows connections/messages
 * ```
 *
 * ## Configuration (app.php)
 *
 * To make pramnos-echo.js connect to the local server, configure the LogDriver
 * and point pramnos-echo.js at localhost:
 *
 * ```js
 * PramnosEcho.configure({
 *     host: 'localhost',
 *     port: 6001,
 *     scheme: 'ws',
 *     appKey: 'pramnos-local',
 * });
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Console
 */
class BroadcastServe extends CommandBase
{
    protected static $defaultName = 'broadcast:serve';

    protected ?LocalBroadcastServer $wsServer = null;

    protected function getJobName(): string
    {
        return 'broadcast-serve';
    }

    protected function configure(): void
    {
        $this
            ->setName('broadcast:serve')
            ->setDescription('Start a local-dev WebSocket broadcasting server (Pusher-compatible, no Ratchet required)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind address', '0.0.0.0')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port', '6001')
            ->addOption(
                'log-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to JSONL broadcast log file produced by LogDriver (tail for incoming broadcasts)',
                ''
            )
            ->addOption(
                'app-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Pusher app key expected in the WebSocket URL',
                'pramnos-local'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host    = (string) ($input->getOption('host') ?? '0.0.0.0');
        $port    = (int)    ($input->getOption('port') ?? 6001);
        $appKey  = (string) ($input->getOption('app-key') ?? 'pramnos-local');
        $logFile = (string) ($input->getOption('log-file') ?? '');

        // Resolve log-file default from app config if not provided explicitly
        if ($logFile === '') {
            $logFile = $this->resolveDefaultLogFile($appKey);
        }

        $output->writeln("<info>Pramnos broadcast:serve</info> — local WebSocket server");
        $output->writeln("  Listening on <comment>ws://{$host}:{$port}</comment>  (app-key: <comment>{$appKey}</comment>)");

        if ($logFile !== '' && file_exists($logFile)) {
            $output->writeln("  Tailing log file: <comment>{$logFile}</comment>");
        } elseif ($logFile !== '') {
            $output->writeln("  Log file (will be watched when created): <comment>{$logFile}</comment>");
        } else {
            $output->writeln("  <comment>No log file configured — only direct broadcast() calls will work.</comment>");
        }

        $output->writeln("  Press <comment>Ctrl+C</comment> to stop.");
        $output->writeln('');

        $this->wsServer = new LocalBroadcastServer($appKey, $logFile !== '' ? $logFile : null);

        // Register SIGTERM / SIGINT handlers so Ctrl-C or systemd stop cleanly
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($output): void {
                $output->writeln('<comment>Caught SIGTERM — shutting down.</comment>');
                $this->wsServer?->stop();
            });
            pcntl_signal(SIGINT, function () use ($output): void {
                $output->writeln('');
                $output->writeln('<comment>Caught SIGINT — shutting down.</comment>');
                $this->wsServer?->stop();
            });
        }

        $verbose   = $output->isVerbose();
        $lastCount = -1;

        $this->wsServer->onTick(function (int $clients, int $channels) use ($output, $verbose, &$lastCount): void {
            if (!$verbose) {
                return;
            }
            if ($clients !== $lastCount) {
                $output->writeln(
                    "  [" . date('H:i:s') . "] clients: <info>{$clients}</info>  channels: <info>{$channels}</info>"
                );
                $lastCount = $clients;
            }
        });

        try {
            $this->wsServer->run($host, $port);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return self::FAILURE;
        }

        $output->writeln('<info>Server stopped.</info>');
        return self::SUCCESS;
    }

    /**
     * Determine the default log file path from the application configuration.
     *
     * Falls back to `var/broadcast.jsonl` relative to the project root.
     */
    private function resolveDefaultLogFile(string $appKey): string
    {
        $app = Application::getInstance();

        // Check if BroadcastingManager is configured with a LogDriver log path
        if ($app instanceof Application) {
            try {
                $container = $app->getContainer();
                if ($container !== null && $container->has('broadcasting')) {
                    $manager = $container->make('broadcasting');
                    if (method_exists($manager, 'getLogPath')) {
                        $path = $manager->getLogPath();
                        if (is_string($path) && $path !== '') {
                            return $path;
                        }
                    }
                }
            } catch (\Throwable) {
                // No broadcasting config — fall through to default
            }
        }

        return defined('ROOT') ? ROOT . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'broadcast.jsonl' : '';
    }
}
