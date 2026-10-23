<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\Apps\AppSource;
use Pramnos\Broadcasting\Auth\AppRegistryAuthorizer;
use Pramnos\Broadcasting\Cluster\ClusterState;
use Pramnos\Broadcasting\Cluster\RedisClusterTransport;
use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\Drivers\RedisStreamDriver;
use Pramnos\Broadcasting\RedisStreamSocket;
use Pramnos\Broadcasting\Http\ServerApi;
use Pramnos\Broadcasting\Webhooks\QueueWebhookDispatcher;
use Pramnos\Broadcasting\Webhooks\WebhookSigner;
use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\RealtimeConfig;
use Pramnos\Broadcasting\RedisSubscriberSocket;
use Pramnos\Console\CommandBase;

/**
 * The WebSocket broadcasting server.
 *
 * Starts a pure-PHP WebSocket server implementing the Pusher wire protocol (v7), so
 * pramnos-echo.js and pusher-js clients connect without any driver change.
 *
 * It was written as a development convenience and has not been one for a while: it
 * serves `wss://` directly, clusters across nodes, authorises private and presence
 * channels against AuthServer app keys, dispatches webhooks, and wires the daemon
 * orchestrator's cooperative stop. The old wording is corrected here because a docblock
 * saying "local-dev" is exactly how somebody concludes the framework does not ship one
 * and stands a second WebSocket stack up beside the working one.
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
 * php ./bin/pramnos broadcast:serve                       # everything from app.php
 * php ./bin/pramnos broadcast:serve --port=6001 --host=0.0.0.0
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
 */
class BroadcastServe extends CommandBase
{
    protected static $defaultName = 'broadcast:serve';

    protected ?LocalBroadcastServer $wsServer = null;

    /** Seconds between heartbeats, from the event loop's ticks. */
    public const HEARTBEAT_SECONDS = 10;

    /**
     * The lock's name — the file the orchestrator's entry declares, `var/pramnos-broadcast-serve.lock`,
     * so the server run by hand is found where the Services screen and the supervisor look.
     */
    protected function getJobName(): string
    {
        return 'pramnos-broadcast-serve.lock';
    }

    protected function configure(): void
    {
        $this
            ->setName('broadcast:serve')
            ->setDescription('Start the WebSocket broadcasting server (Pusher-compatible, no Ratchet required)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind address (broadcasting.websocket.listen_host, else 127.0.0.1)')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port (broadcasting.websocket.listen_port, else 6001)')
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
                'Pusher app key expected in the WebSocket URL (broadcasting.websocket.app_key, else pramnos-local)'
            )
            ->addOption(
                'channels',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated Redis channels to ingest, `*` for a pattern (broadcasting.ingest); the notifications channel joins on its own'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // app.php first, an option over it: the orchestrator starts this daemon with no options
        // at all. The bind address is not `websocket.host` — that is what the browser reaches,
        // usually a proxy in front — and it stays on loopback unless somebody says otherwise.
        $config    = $this->broadcastingConfig();
        $websocket = is_array($config['websocket'] ?? null) ? $config['websocket'] : [];
        $host      = (string) ($input->getOption('host') ?? $websocket['listen_host'] ?? '127.0.0.1');
        $port      = (int)    ($input->getOption('port') ?? $websocket['listen_port'] ?? 6001);
        $appKey    = (string) ($input->getOption('app-key') ?? $websocket['app_key'] ?? 'pramnos-local');
        $logFile   = (string) ($input->getOption('log-file') ?? '');

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

        $this->wsServer = $this->createServer($appKey, $logFile !== '' ? $logFile : null);

        // Production wiring from app.php['broadcasting'] config: enforce app-key +
        // private/presence signatures when a Pusher secret is configured, and feed
        // the server straight from Redis pub/sub when channels are requested.

        $secret   = (string) ($config['pusher']['app_secret'] ?? '');
        $features = $this->applicationFeatures();

        // A registry-backed authorizer resolves the signing secret per connection
        // from the app key in the token, which is what makes more than one app
        // possible at all. The single-pair PusherAuthorizer below is the fallback,
        // and stays the behaviour of every deployment that has not moved its app
        // keys into the AuthServer.
        $appSource = null;
        try {
            $appSource = AppSource::resolve($config, $features);
        } catch (\RuntimeException $e) {
            // A misconfigured source must stop the daemon rather than silently
            // authorize against a different secret than the operator asked for.
            $output->writeln('  <error>Broadcasting apps: ' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        if ($appSource === AppSource::AUTHSERVER) {
            // A TTL, unlike the web binding's zero: this process is a
            // single-threaded select loop, so a query per handshake blocks every
            // other connection — and after a deploy every client reconnects at once.
            $this->wsServer->useAuthorizer(
                new AppRegistryAuthorizer(AppSource::registry($config, $features, 60))
            );
            $output->writeln(
                '  Auth: <info>Pusher signatures enforced, app keys from the AuthServer '
                . 'applications table</info>'
            );
        } elseif ($secret !== '') {
            $this->wsServer->useAuthorizer(new PusherAuthorizer($appKey, $secret));
            $output->writeln('  Auth: <info>Pusher signatures enforced</info>');
        } else {
            // Saying nothing here is how a server ends up in production with
            // private and presence channels open to anyone: the default
            // authorizer accepts every connection, and silence looks the same
            // as a configured one.
            $output->writeln(
                '  Auth: <comment>none — every connection and channel is accepted</comment>'
            );
            $output->writeln(
                '        Set broadcasting.pusher.app_secret in app.php before '
                . 'using private-* or presence-* channels outside development.'
            );
        }

        // Direct wss://, when a certificate is configured. Reported either way:
        // an operator needs to know whether the port they are about to point
        // clients at speaks ws:// or wss://, and getting it wrong looks like a
        // network fault rather than a scheme mismatch.
        $tls = is_array($config['websocket']['tls'] ?? null) ? $config['websocket']['tls'] : [];
        if (($tls['local_cert'] ?? '') !== '') {
            $this->wsServer->useTls($tls);
            $output->writeln('  Transport: <info>wss:// (TLS terminated here)</info>');
            $output->writeln(
                '             <comment>the TLS handshake is synchronous — put a proxy in '
                . 'front if connection churn is high</comment>'
            );
        } else {
            $output->writeln('  Transport: <comment>ws:// (plain TCP)</comment>');
        }

        // The HTTP API and the webhooks both hang off the app registry: the API
        // authenticates each request against it, and the webhook signer needs one
        // app's secret. Built once here so the daemon does not resolve them per
        // request inside its select loop.
        $appRegistry = AppSource::registry($config, $features, 60);

        if ((bool) ($config['http_api']['enabled'] ?? false)) {
            $this->wsServer->useHttpApi(new ServerApi($this->wsServer, $appRegistry));
            $output->writeln(
                '  HTTP API: <info>enabled</info> on the same port '
                . '(signed /apps/{id}/events, /channels)'
            );
        } else {
            $output->writeln('  HTTP API: <comment>disabled</comment>');
        }

        $webhookUrl = (string) ($config['webhooks']['url'] ?? '');
        if ($webhookUrl !== '') {
            $webhookApp = $appRegistry->defaultApp() ?? $appRegistry->findByKey($appKey);

            if ($webhookApp === null || !$webhookApp->canSign()) {
                // Unsigned webhooks are worse than none: a receiver cannot tell
                // them from anybody else's POST, so it either trusts every caller
                // or rejects ours. Saying so beats sending them.
                $output->writeln(
                    '  Webhooks: <error>configured but no app secret is available to sign '
                    . 'them — not sending</error>'
                );
            } else {
                $this->wsServer->useWebhooks(new QueueWebhookDispatcher(
                    $webhookUrl,
                    new WebhookSigner($webhookApp),
                    (string) ($config['webhooks']['queue'] ?? 'broadcasting')
                ));
                $output->writeln('  Webhooks: <info>queued for ' . $webhookUrl . '</info>');
            }
        }

        // Client events (typing indicators and other browser-to-browser cues) are
        // off unless app.php asks for them. Reported either way: an operator who
        // enabled a write path onto every private channel should see it in the
        // startup banner, and one who did not should be able to tell.
        $clientEvents = (bool) ($config['websocket']['client_events'] ?? false);
        $perSecond    = (int) ($config['websocket']['client_events_per_second'] ?? 10);

        if ($clientEvents) {
            $this->wsServer->allowClientEvents(true, $perSecond);
            $output->writeln(
                '  Client events: <info>enabled</info>, ' . max(1, $perSecond)
                . '/s per connection (private/presence channels only)'
            );
        } else {
            $output->writeln('  Client events: <comment>disabled</comment>');
        }

        $ingest   = $input->getOption('channels') ?? $config['ingest'] ?? [];
        $channels = array_values(array_filter(array_map(
            'trim',
            is_array($ingest) ? array_map('strval', $ingest) : explode(',', (string) $ingest)
        )));

        // **The ingest follows the driver, rather than being chosen independently of
        // it.** This is the pairing rule the docs state twice, applied here instead of
        // left to the operator: a `SUBSCRIBE` on a key that only ever receives `XADD`
        // is a perfectly healthy subscription that is never delivered anything — no
        // error, no warning, no events. Hard-coding pub/sub meant an installation that
        // wanted SSE replay (which needs the stream driver) could not use this command
        // at all, and one consumer wrote its own daemon for exactly that reason.
        $backplane = (string) ($config['default'] ?? 'null');
        $usesStream = $backplane === 'redis-stream';

        // The key each driver writes: RedisDriver publishes on prefix + channel,
        // RedisStreamDriver appends to prefix + `stream:` + channel. The same string is taken
        // off again on delivery, so a browser subscribes to the channel's own name.
        $redisConfig = is_array($config['redis'] ?? null) ? $config['redis'] : [];
        $prefix      = (string) ($redisConfig['prefix'] ?? '') . ($usesStream ? 'stream:' : '');
        $prefixed    = array_map(static fn (string $c): string => $prefix . $c, $channels);

        // Every user's notifications channel, as one pattern: `private-users.{id}` is
        // `private-users.*`, so a notification reaches the open page without anybody listing
        // users. Pub/sub only — a stream is read by its exact key, and there is one per user.
        $notifications = str_replace('{id}', '*', (string) ($config['notifications_channel'] ?? '') ?: RealtimeConfig::USER_CHANNEL);
        if ($backplane === 'redis' && !in_array($prefix . $notifications, $prefixed, true)) {
            $prefixed[] = $prefix . $notifications;
        } elseif ($usesStream) {
            $output->writeln(
                '  Notifications: <comment>not ingested — a stream cannot be read by pattern, '
                . 'and each user has a stream of their own</comment>'
            );
            $output->writeln(
                '                 <comment>Use broadcasting.default = redis for notifications '
                . 'on open pages; their next poll finds them meanwhile.</comment>'
            );
        }

        // The conversations' channels, the same way, where the application has them:
        // `private-conversation.{id}` is `private-conversation.*`, so what a worker writes there —
        // an answer, "… is thinking" — reaches the open dock without anybody listing them.
        if (in_array('conversations', $this->applicationFeatures(), true)) {
            $conversations = str_replace('0', '*', \Pramnos\Conversations\Realtime::channel(0));
            if ($backplane === 'redis' && !in_array($prefix . $conversations, $prefixed, true)) {
                $prefixed[] = $prefix . $conversations;
            } elseif ($usesStream) {
                $output->writeln('  Conversations: <comment>not ingested — a stream cannot be read by pattern; '
                    . 'use broadcasting.default = redis for the conversations to be live</comment>');
            }
        }

        // The pairing rule above settles *which* Redis primitive to read with. It cannot
        // help when the application publishes somewhere else entirely — a `log`, `pusher`
        // or `null` backplane with `--channels` asked for here opens a healthy
        // subscription to a Redis nobody writes to.
        //
        // The symptom is identical to the mismatch above and identical to a working
        // daemon with no traffic: connections succeed, channels exist, and nothing ever
        // arrives. Said out loud at startup, where somebody is looking, rather than
        // discovered from an empty browser hours later.
        if ($channels !== [] && !str_starts_with($backplane, 'redis')) {
            $output->writeln(
                '<error>  Ingest: --channels reads from Redis, but broadcasting.default '
                . 'is "' . $backplane . '" — nothing publishes there.</error>'
            );
            $output->writeln(
                '          <comment>Set broadcasting.default to redis or redis-stream, '
                . 'or drop --channels and tail the log file instead.</comment>'
            );
        }

        // Clustering: share presence membership and relay client events between
        // nodes. Its gossip travels on the backplane, so the cluster channel joins
        // the ingest's subscription list — and the transport publishes with a
        // RedisDriver (PUBLISH) because the ingest reads with SUBSCRIBE. Mixing the
        // two primitives produces a cluster where every node believes it is alone,
        // with nothing in any log.
        $clusterConfig = is_array($config['cluster'] ?? null) ? $config['cluster'] : [];
        if ((bool) ($clusterConfig['enabled'] ?? false)) {
            $clusterChannel = (string) ($clusterConfig['channel'] ?? '__pramnos_cluster');
            $interval       = max(1, (int) ($clusterConfig['interval'] ?? 30));
            $nodeId         = (string) ($clusterConfig['node_id'] ?? '') ?: bin2hex(random_bytes(6));

            // Three intervals of silence before a node is written off, so one late
            // message cannot evict a healthy peer.
            $state = new ClusterState($nodeId, $interval * 3 * 1000);

            $this->wsServer->useCluster(
                new RedisClusterTransport(
                    // The same primitive as the ingest below, for the same reason:
                    // gossip published with XADD and read with SUBSCRIBE gives a
                    // cluster where every node believes it is alone.
                    $usesStream ? new RedisStreamDriver($redisConfig) : new RedisDriver($redisConfig),
                    $clusterChannel
                ),
                $state,
                $prefix . $clusterChannel,
                $interval
            );

            $prefixed[] = $prefix . $clusterChannel;

            $output->writeln(
                '  Cluster: <info>node ' . $nodeId . '</info>, gossip on '
                . $prefix . $clusterChannel . ' every ' . $interval . 's'
            );
            $output->writeln(
                '           <comment>presence is eventually consistent; member webhooks '
                . 'are per-node</comment>'
            );
        }

        if ($prefixed !== []) {
            $this->wsServer->useIngestPrefix($prefix);
            $this->wsServer->useRedisIngest($usesStream
                ? new RedisStreamSocket($redisConfig, $prefixed)
                : new RedisSubscriberSocket($redisConfig, $prefixed));

            $output->writeln(
                '  Redis ingest: <info>' . implode(', ', $prefixed) . '</info>'
                . ' via <info>' . ($usesStream ? 'XREAD' : 'SUBSCRIBE') . '</info>'
                . ' (backplane: ' . $backplane . ')'
            );
        }

        // Cooperative stop on SIGTERM (systemd stop / deploy) or SIGINT (Ctrl+C):
        // break the blocking server loop by stopping the WebSocket server. One
        // reusable primitive (SignalStop) instead of a hand-rolled pcntl pair.
        // The cooperative half of the stop protocol. installStopSignals() below
        // covers SIGTERM/SIGINT; this covers the supervisor's `.stop` sentinel, which
        // this daemon previously had no way to observe — so it was reported
        // [stop-timeout] on every deploy, and on an installation whose orchestrator
        // skipped the sentinel check it was never stopped at all.
        $this->wsServer->shouldStopUsing(fn (): bool => $this->shouldStop());

        $this->installStopSignals(function () use ($output): void {
            $output->writeln('');
            $output->writeln('<comment>Caught stop signal — shutting down.</comment>');
            $this->wsServer?->stop();
        });

        $verbose   = $output->isVerbose();
        $lastCount = -1;
        $lastBeat  = 0;

        /*
         * The lock and its heartbeat, as every supervised worker keeps them. Without them the
         * orchestrator found no healthy lock, called the server unverified and restarted it
         * every cycle — every open page dropped each time — and the Services screen showed a
         * running server as stopped, offering to start a second.
         */
        if ($this->checkIfRunning()) {
            $output->writeln('<error>Another broadcast:serve holds ' . $this->resolvedJobLockFilePath() . '.</error>');
            return self::FAILURE;
        }
        $this->startJob();

        $this->wsServer->onTick(function (int $clients, int $channels) use ($output, $verbose, &$lastCount, &$lastBeat): void {
            if (time() - $lastBeat >= self::HEARTBEAT_SECONDS) {
                $lastBeat = time();
                // A lock taken over by a replacement is a second server on this port's
                // channels: this one stops.
                if (!$this->heartbeat(['clients' => $clients, 'channels' => $channels])) {
                    $output->writeln('<comment>Lock taken over — shutting down.</comment>');
                    $this->wsServer?->stop();
                }
            }
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
        } finally {
            $this->endJob();
        }

        $output->writeln('<info>Server stopped.</info>');
        return self::SUCCESS;
    }

    /**
     * Factory method for the WebSocket server.
     *
     * Extracted to a protected method so tests can override it to inject a
     * stub instead of creating a real TCP socket.
     */
    protected function createServer(string $appKey, ?string $logFile): LocalBroadcastServer
    {
        return new LocalBroadcastServer($appKey, $logFile);
    }

    /**
     * The app.php['features'] array, or [].
     *
     * Read from applicationInfo rather than from FeatureRegistry: the registry is
     * populated at bootstrap, and a security decision should not depend on which
     * entry point ran first. See {@see AppSource} for what that cost once.
     *
     * @return string[]
     */
    protected function applicationFeatures(): array
    {
        $app = Application::getInstance();

        if ($app instanceof Application && is_array($app->applicationInfo['features'] ?? null)) {
            return $app->applicationInfo['features'];
        }

        return [];
    }

    /**
     * The app.php['broadcasting'] config array (redis / pusher / etc.), or [].
     *
     * @return array<string,mixed>
     */
    protected function broadcastingConfig(): array
    {
        $app = Application::getInstance();
        if ($app instanceof Application && is_array($app->applicationInfo['broadcasting'] ?? null)) {
            return $app->applicationInfo['broadcasting'];
        }
        return [];
    }

    /**
     * Determine the default log file path from the application configuration.
     *
     * Falls back to `var/broadcast.jsonl` relative to the project root.
     */
    protected function resolveDefaultLogFile(string $appKey): string
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
