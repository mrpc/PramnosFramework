<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Auth\ConnectionAuthorizer;
use Pramnos\Broadcasting\Auth\PresenceAuthorizer;
use Pramnos\Broadcasting\Cluster\ClusterState;
use Pramnos\Broadcasting\Cluster\ClusterTransportInterface;
use Pramnos\Broadcasting\Http\ServerApi;
use Pramnos\Broadcasting\Webhooks\WebhookDispatcherInterface;
use Pramnos\Http\WebSocket\FrameCodec;
use Pramnos\Http\WebSocket\MessageAssembler;
use Pramnos\Http\WebSocket\WebSocketProtocolException;

/**
 * Pure-PHP WebSocket server for local broadcasting (development only).
 *
 * Implements a minimal subset of RFC 6455 (WebSocket) and the Pusher Wire
 * Protocol v7, so existing pramnos-echo.js clients can connect without any
 * configuration change other than pointing to localhost.
 *
 * Features:
 *  - Pusher-compatible handshake + subscription flow
 *  - Channel fan-out: broadcast(channel, event, data)
 *  - JSONL file tail: reads new entries from a LogDriver output file and
 *    pushes them to subscribed clients automatically
 *  - Ping/pong keepalive
 *  - Graceful shutdown on SIGTERM / SIGINT
 *
 * Limitations (intentional — this is a dev tool):
 *  - Single-threaded (stream_select event loop)
 *  - TLS is available via useTls(), but its handshake is synchronous — see there
 *  - Up to ~100 concurrent connections without tuning
 *
 * Auth is pluggable via a {@see \Pramnos\Broadcasting\Auth\ConnectionAuthorizer}
 * (default {@see \Pramnos\Broadcasting\Auth\AllowAllAuthorizer}; pass a
 * {@see \Pramnos\Broadcasting\Auth\PusherAuthorizer} in production to enforce the
 * app key and private/presence channel signatures).
 *
 */
class LocalBroadcastServer
{
    /** @var resource|null Server socket. */
    private $serverSocket = null;

    /** @var array<int, array{socket:resource, state:string, buffer:string, channels:string[], socketId:string, pingAt:int, assembler:?MessageAssembler}> */
    private array $clients = [];

    /** @var array<string, int[]> channel → list of client IDs */
    private array $subscriptions = [];

    /**
     * Presence membership: channel → clientId → member data.
     *
     * Keyed by connection rather than by user, because one user may hold several
     * (two tabs, a phone and a laptop). The member *list* is deduplicated by
     * `user_id` on the way out, and a departure is only announced when the last
     * connection for that user goes — announcing it per connection would report
     * somebody as having left a room they are still sitting in.
     *
     * @var array<string, array<int, array{user_id:string, user_info?:array<string,mixed>}>>
     */
    private array $presence = [];

    /** @var int Next auto-increment socket ID. */
    private int $nextSocketId = 1;

    /** @var string Pusher app-key (for URL path matching). */
    private string $appKey;

    /** @var string|null Path to JSONL log file produced by LogDriver. */
    private ?string $logFile;

    /** @var int File offset for incremental reading of $logFile. */
    protected int $logOffset = 0;

    /** @var bool  Main loop flag. */
    private bool $running = false;

    /** @var callable|null  Callback invoked each tick (for progress output). */
    private $tickCallback = null;

    /** Authorizes connections (app key) and private/presence channel subscriptions. */
    private ConnectionAuthorizer $authorizer;

    /** Optional Redis pub/sub ingest, polled non-blocking inside the select loop. */
    private ?RedisIngestInterface $redisIngest = null;

    /**
     * Whether browsers may publish `client-*` events to a channel.
     *
     * **Default false, and that is a security decision rather than a preference.**
     * Until this existed, a `client-` event from a browser was silently dropped, so
     * no deployment has ever had a client-to-client write path through this server.
     * Enabling it by default would open one on every installation that merely
     * updated the framework — a new write surface nobody asked for, appearing in a
     * patch release.
     */
    private bool $clientEventsEnabled = false;

    /** Per-connection client-event budget: clientId → [windowStart, count]. */
    private array $clientEventBudget = [];

    /** Max client events one connection may send per second. */
    private int $clientEventsPerSecond = 10;

    /**
     * Counters, for the metrics endpoint.
     *
     * Cumulative where a rate is what matters (`connections_total`), instantaneous
     * where a level is (`connections_current`, derived on read). A gauge that only
     * ever reports "now" cannot answer "is this getting worse", and a counter that
     * resets on restart at least says so through `uptime_seconds`.
     *
     * @var array<string,int>
     */
    private array $counters = [
        'connections_total'      => 0,
        'messages_sent'          => 0,
        'client_events_relayed'  => 0,
        'client_events_refused'  => 0,
        'webhook_events_queued'  => 0,
    ];

    /** Unix time this object was constructed, or run() was called. */
    private int $startedAt;

    /**
     * SSL stream-context options, when serving `wss://` directly.
     *
     * @var array<string,mixed>|null
     */
    private ?array $tlsContext = null;

    /** The HTTP API, when one is installed. Absent unless explicitly enabled. */
    private ?ServerApi $httpApi = null;

    /** Gossip transport, when this node is part of a cluster. */
    private ?ClusterTransportInterface $cluster = null;

    /** What the other nodes are believed to hold. */
    private ?ClusterState $clusterState = null;

    /**
     * The gossip channel as the *ingest* delivers it.
     *
     * Not the same string the transport publishes to: a driver prefixes on the way
     * out and the ingest sees the prefixed name. Told to us explicitly rather than
     * guessed, because a wrong guess here does not error — it makes every gossip
     * message look like an application event and fan it out to browsers.
     */
    private string $clusterIngestChannel = '';

    /** Seconds between full-state gossip messages. */
    private int $gossipInterval = 30;

    /** Unix time of the last gossip publication. */
    private int $lastGossipAt = 0;

    /** Where lifecycle notifications go, when anywhere. */
    private ?WebhookDispatcherInterface $webhooks = null;

    /**
     * Webhook events accumulated during this loop iteration.
     *
     * Batched rather than dispatched one at a time because a single action produces
     * several — a client disconnecting from three channels vacates up to three, and
     * a departure is both a `member_removed` and possibly a `channel_vacated`. One
     * hand-off per iteration instead of one per event.
     *
     * @var list<array<string,mixed>>
     */
    private array $pendingWebhooks = [];

    /** @var callable|null Optional router mapping an ingested message to WS deliveries. */
    private $ingestRouter = null;

    public function __construct(
        string $appKey = 'pramnos-local',
        ?string $logFile = null,
        ?ConnectionAuthorizer $authorizer = null,
    ) {
        $this->appKey     = $appKey;
        $this->logFile    = $logFile;
        $this->startedAt  = time();
        // Default is permissive to preserve local-dev behaviour; production wiring
        // passes a PusherAuthorizer built from the configured key + secret.
        $this->authorizer = $authorizer ?? new AllowAllAuthorizer();
    }

    /**
     * Register a tick callback; called after each event-loop iteration.
     *
     * @param callable $cb  fn(int $clientCount, int $subscriptionCount): void
     */
    public function onTick(callable $cb): void
    {
        $this->tickCallback = $cb;
    }

    /**
     * The channel names that currently have at least one subscriber. Handy from an
     * onTick callback that needs to act on who is connected (e.g. an app refreshing
     * presence for the users behind its per-user private channels). Read-only
     * snapshot; empty channels are pruned on unsubscribe/disconnect.
     *
     * @return string[]
     */
    public function subscribedChannels(): array
    {
        return array_keys($this->subscriptions);
    }

    /**
     * How many connections are subscribed to $channel.
     *
     * Connections, not people — the deduplicated head count is
     * {@see presenceMembers()}, and only a presence channel has one. Conflating
     * them is how a caller ends up reporting three tabs as three users.
     */
    public function subscriberCount(string $channel): int
    {
        return count($this->subscriptions[$channel] ?? []);
    }

    /**
     * A snapshot of what this process is doing.
     *
     * Levels and counters together on purpose: `connections_current` answers "how
     * busy is it now", `connections_total` against `uptime_seconds` answers "is that
     * unusual", and `client_events_refused` beside `client_events_relayed` is the
     * only way to see a rate limit doing its job — refusals are silent on the wire
     * by design, so without a counter a client that has been throttled for an hour
     * looks exactly like one that is quiet.
     *
     * @return array<string,int>
     */
    public function stats(): array
    {
        $subscriptions = 0;
        foreach ($this->subscriptions as $subscribers) {
            $subscriptions += count($subscribers);
        }

        return $this->counters + [
            'connections_current'  => count($this->clients),
            'channels_occupied'    => count($this->subscriptions),
            'subscriptions_current' => $subscriptions,
            'presence_channels'    => count($this->presence),
            'uptime_seconds'       => max(0, time() - $this->startedAt),
        ];
    }

    /**
     * Serve `wss://` directly, with the given SSL stream-context options.
     *
     * ```php
     * $server->useTls([
     *     'local_cert'  => '/etc/ssl/realtime/fullchain.pem',
     *     'local_pk'    => '/etc/ssl/realtime/privkey.pem',
     *     'passphrase'  => null,
     * ]);
     * ```
     *
     * A setter rather than a third parameter on {@see run()}: adding one would be
     * source-compatible for callers and fatal for any subclass overriding `run()`,
     * and this codebase already subclasses this class in its own tests. It also
     * matches the `useX()` idiom the rest of the configuration uses.
     *
     * ## The honest tradeoff
     *
     * **The TLS handshake happens synchronously in `accept()`.** This is a
     * single-threaded loop, so a client that is slow to complete its handshake
     * holds up every other connection for the duration — and a handshake is
     * dramatically more expensive than a TCP accept. On a deploy, when every client
     * reconnects at once, that cost arrives all together.
     *
     * So this is the right choice for a small deployment that would rather not run
     * a proxy, and the wrong one for high connection churn. There, terminate TLS in
     * front (nginx, Caddy, a load balancer) and leave this server on plain TCP
     * behind it — the proxy has a thread pool and this does not. Written down
     * because "the framework supports wss://" reads like a recommendation, and for
     * a busy install it is not one.
     *
     * @param array<string,mixed> $context SSL context options, per PHP's SSL
     *                                     transport. `local_cert` is required.
     */
    public function useTls(array $context): void
    {
        $this->tlsContext = $context;
    }

    /**
     * Serve the Pusher-compatible HTTP API on the same port.
     *
     * **Opt-in, because it opens a publish path.** A signed request can broadcast
     * to any channel, so the API is absent unless a deployment installs it — the
     * same reasoning as client events. Requests are authenticated per app against
     * the registry the api was built with; an unsigned or missigned one gets 401.
     */
    public function useHttpApi(ServerApi $api): void
    {
        $this->httpApi = $api;
    }

    /**
     * Join a cluster: share presence membership and relay client events between
     * nodes.
     *
     * Without this, two daemons behind a load balancer both receive application
     * events from the backplane — so ordinary broadcasts already work — but presence
     * membership and client events are per-process. A user connected to node A does
     * not appear in the member list node B serves, and a whisper on A never reaches
     * B. Neither failure says anything: the counts are simply wrong.
     *
     * ## What this makes true, and what it does not
     *
     * Presence becomes **eventually consistent**. A join reaches the other nodes as
     * fast as the backplane moves a message, which is fast; a node's *full* state is
     * republished every `$intervalSeconds`, and that is what repairs anything a node
     * missed. So a member list is right within one interval in the worst case, not
     * instantly in every case. If a room's count must never be transiently low, this
     * is not the mechanism for it.
     *
     * `member_added` / `member_removed` **webhooks** stay local: each node reports
     * only the members whose connections it owns. That needs no coordination and
     * cannot double-report. `channel_occupied` / `channel_vacated` are also per-node
     * — each node reports its own occupancy — so a receiver counting them across a
     * cluster is counting nodes, not channels.
     *
     * @param ClusterTransportInterface $transport      Must not block; see the interface.
     * @param ClusterState              $state          Holds the peers' membership.
     * @param string                    $ingestChannel  The gossip channel *as the
     *        ingest delivers it* — prefixed, if the driver prefixes. Defaults to the
     *        transport's own name, which is correct only when there is no prefix.
     * @param int                       $intervalSeconds Full-state period. The
     *        state's TTL must be a multiple of this, or one late message evicts a
     *        healthy node.
     */
    public function useCluster(
        ClusterTransportInterface $transport,
        ClusterState $state,
        string $ingestChannel = '',
        int $intervalSeconds = 30
    ): void {
        $this->cluster              = $transport;
        $this->clusterState         = $state;
        $this->clusterIngestChannel = $ingestChannel !== '' ? $ingestChannel : $transport->channel();
        $this->gossipInterval       = max(1, $intervalSeconds);
        $this->lastGossipAt         = 0;
    }

    /**
     * Send lifecycle notifications — channel occupied/vacated, member
     * added/removed, client events — to $dispatcher.
     *
     * Absent by default: with no dispatcher installed nothing is collected and
     * nothing is emitted, which is the behaviour every existing deployment has.
     *
     * The dispatcher must not block. See {@see WebhookDispatcherInterface} for why
     * an outbound HTTP call from inside this loop is a realtime outage waiting to
     * happen.
     */
    public function useWebhooks(WebhookDispatcherInterface $dispatcher): void
    {
        $this->webhooks = $dispatcher;
    }

    /**
     * Who is currently in a presence channel: `user_id` → `user_info`.
     *
     * One entry per distinct user rather than per connection, so a user with three
     * tabs open counts once. Read-only, and the counterpart of
     * {@see subscribedChannels()} for an application that wants to act on the live
     * audience — an onTick callback refreshing presence records, for instance —
     * without patching the server.
     *
     * @return array<string, array<string,mixed>>
     */
    public function presenceMembers(string $channel): array
    {
        return $this->presencePayload($channel)['presence']['hash'];
    }

    /**
     * Create the listening socket — `tcp://` normally, `ssl://` once
     * {@see useTls()} has been called.
     *
     * Separate from {@see run()} because run() blocks in its event loop, so this is
     * the only way to assert what was bound and with which context. Overridable for
     * the same reason.
     *
     * @return resource
     * @throws \RuntimeException When the socket cannot be created — a port in use,
     *         or a certificate PHP will not load.
     */
    protected function createServerSocket(string $host, int $port)
    {
        if ($this->tlsContext !== null) {
            // PHP does **not** load the certificate when the listener is created —
            // it loads it per accepted connection. So a wrong `local_cert` path
            // produces a server that binds, reports itself healthy, and then fails
            // every single handshake, with the operator looking at a port that is
            // definitely open. Checked here so that becomes a startup failure.
            foreach (['local_cert', 'local_pk'] as $option) {
                $path = (string) ($this->tlsContext[$option] ?? '');
                if ($path !== '' && !is_readable($path)) {
                    throw new \RuntimeException(
                        'TLS is configured but ' . $option . ' "' . $path
                        . '" is not readable; refusing to start a wss:// listener that '
                        . 'would fail every handshake.'
                    );
                }
            }

            if ((string) ($this->tlsContext['local_cert'] ?? '') === '') {
                throw new \RuntimeException(
                    'TLS is configured without a local_cert; refusing to start a wss:// '
                    . 'listener that would fail every handshake.'
                );
            }
        }

        $transport = $this->tlsContext === null ? 'tcp' : 'ssl';
        $context   = stream_context_create(
            $this->tlsContext === null ? [] : ['ssl' => $this->tlsContext]
        );

        $socket = @stream_socket_server(
            "{$transport}://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException(
                "Cannot bind on {$transport}://{$host}:{$port} — {$errstr} ({$errno})"
            );
        }

        return $socket;
    }

    /**
     * Start the server and block until stop() is called or a fatal error occurs.
     *
     * @param string $host  Bind address (default: 0.0.0.0)
     * @param int    $port  Listen port (default: 6001)
     * @throws \RuntimeException if the socket cannot be created.
     */
    public function run(string $host = '0.0.0.0', int $port = 6001): void
    {
        $this->startedAt    = time();
        $this->serverSocket = $this->createServerSocket($host, $port);

        stream_set_blocking($this->serverSocket, false);

        if ($this->logFile !== null && file_exists($this->logFile)) {
            $this->logOffset = (int) filesize($this->logFile);
        }

        // Connect the Redis ingest (if configured) so its socket is in the loop.
        if ($this->redisIngest !== null && !is_resource($this->redisIngest->getStream())) {
            $this->redisIngest->connect();
        }

        $this->running = true;

        while ($this->running) {
            $this->loopIteration();
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->tickCallback !== null) {
                ($this->tickCallback)(count($this->clients), count($this->subscriptions));
            }
        }

        $this->shutdown();
    }

    /**
     * Signal the main loop to stop cleanly.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Broadcast a message to all clients subscribed to $channel.
     *
     * @param string $channel  Channel name (e.g. "orders", "private-user.42")
     * @param string $event    Event name (e.g. "App\\Events\\OrderCreated")
     * @param mixed  $data     Payload (will be JSON-encoded)
     */
    public function broadcast(string $channel, string $event, $data): void
    {
        $this->fanOut($channel, $event, $data, null);
    }

    /**
     * Broadcast to $channel, skipping the connection whose socket id is
     * $exceptSocketId — what `toOthers()` needs.
     *
     * A separate method rather than a fourth parameter on {@see broadcast()}: this
     * framework's own test suite subclasses this class and overrides `broadcast()`
     * with its exact three-argument signature, and PHP requires an override to stay
     * compatible. Adding a parameter would have been source-compatible for callers
     * and fatal for every subclass — including ours.
     *
     * For the same reason `broadcast()` still holds the fan-out entry point that
     * internal callers use, so a subclass overriding it keeps intercepting
     * everything it used to.
     *
     * @param mixed $data
     */
    public function broadcastExcept(
        string $channel,
        string $event,
        $data,
        ?string $exceptSocketId
    ): void {
        if ($exceptSocketId === null || $exceptSocketId === '') {
            $this->broadcast($channel, $event, $data);
            return;
        }

        $this->fanOut($channel, $event, $data, $exceptSocketId);
    }

    /**
     * @param mixed $data
     */
    private function fanOut(string $channel, string $event, $data, ?string $exceptSocketId): void
    {
        $payload = json_encode([
            'event'   => $event,
            'data'    => is_string($data) ? $data : json_encode($data),
            'channel' => $channel,
        ]);

        foreach ($this->subscriptions[$channel] ?? [] as $id) {
            if (
                $exceptSocketId !== null
                && ($this->clients[$id]['socketId'] ?? null) === $exceptSocketId
            ) {
                continue;
            }

            if (isset($this->clients[$id])) {
                $this->wsSend($this->clients[$id]['socket'], $payload);
                $this->counters['messages_sent']++;
            }
        }
    }

    // =========================================================================
    // Event loop
    // =========================================================================

    /**
     * Feed this server from Redis. The ingest's socket joins the select loop, so
     * events fan out to WS clients with no blocking, file hop or extra process.
     * Call before run().
     *
     * **Which ingest follows from which driver publishes**, and getting it wrong
     * is silent rather than loud:
     *
     * - {@see Drivers\RedisDriver} publishes with `PUBLISH` → {@see RedisSubscriberSocket}
     * - {@see Drivers\RedisStreamDriver} publishes with `XADD` → {@see RedisStreamSocket}
     *
     * A subscriber pointed at a stream is a healthy subscription that is never
     * delivered anything; a stream reader also survives a restart of this daemon,
     * because its position is a cursor rather than a subscription.
     *
     * @param RedisIngestInterface $ingest Either implementation; the parameter was
     *                                    widened from RedisSubscriberSocket, so
     *                                    every existing call still type-checks.
     */
    public function useRedisIngest(RedisIngestInterface $ingest): void
    {
        $this->redisIngest = $ingest;
    }

    /**
     * Install a router that maps each ingested Redis message to zero or more WS
     * deliveries — e.g. strip the key prefix for public channels and fan a direct
     * message out to per-recipient private channels so no client ever receives
     * another user's payload. The router receives (channel, event, payload, id) — the id
     * being the backplane entry id, or null when the ingest has none — and
     * returns a list of [channel, event?, payload?] triples ([] or null drops the
     * message). With no router the message is delivered verbatim on its own
     * channel (unchanged default). Call before run().
     *
     * @param callable(string,string,mixed,?string):(list<array{0:string,1?:string,2?:mixed}>|null) $router
     */
    public function useIngestRouter(callable $router): void
    {
        $this->ingestRouter = $router;
    }

    /**
     * Replace the authorizer after construction (used by the console command,
     * which wires a PusherAuthorizer from config). Call before run().
     */
    public function useAuthorizer(ConnectionAuthorizer $authorizer): void
    {
        $this->authorizer = $authorizer;
    }

    /**
     * Allow browsers to publish `client-*` events to private and presence channels.
     *
     * This is the whole category of typing indicators, cursors and other transient
     * peer-to-peer cues — and the reason to want a WebSocket rather than SSE at all,
     * since it is the only direction SSE cannot carry.
     *
     * **It is off by default and must be turned on deliberately.** It grants every
     * connected browser a write path onto a channel: a client event is relayed to
     * the channel's other subscribers without the server inspecting it, which is
     * what makes it cheap and also what makes it a trust decision. Anything a
     * client must not be able to assert about another user has to travel through the
     * application, not through here.
     *
     * @param bool $enabled       Off by default.
     * @param int  $eventsPerSecond Per-connection budget. Pusher's own limit is 10.
     */
    public function allowClientEvents(bool $enabled = true, int $eventsPerSecond = 10): void
    {
        $this->clientEventsEnabled   = $enabled;
        $this->clientEventsPerSecond = max(1, $eventsPerSecond);
    }

    /**
     * Send one gossip message, stamped with this node's identity and clock.
     *
     * Free when not clustered: the guard means a single-node deployment does no work
     * per presence change.
     *
     * @param array<string,mixed> $message
     */
    private function gossip(array $message): void
    {
        if ($this->cluster === null || $this->clusterState === null) {
            return;
        }

        $this->cluster->publish($message + [
            'node' => $this->clusterState->nodeId(),
            'ts'   => (int) round(microtime(true) * 1000),
        ]);
    }

    /**
     * Apply a gossip message from a peer.
     *
     * @param array<string,mixed> $message
     */
    private function handleClusterMessage(array $message): void
    {
        if ($this->clusterState === null) {
            return;
        }

        $node = (string) ($message['node'] ?? '');
        $ts   = (int) ($message['ts'] ?? 0);

        switch ((string) ($message['type'] ?? '')) {
            case 'state':
                $channels = is_array($message['channels'] ?? null) ? $message['channels'] : [];
                $before   = $this->clusterState->remoteChannels();

                if (!$this->clusterState->applyState($node, $channels, $ts)) {
                    return;
                }

                // A full state can both add and remove members, and reconciling it
                // member-by-member against the previous view would mean holding two
                // copies of every channel. It is the repair mechanism, not the
                // notification mechanism: clients learn from the deltas, and get the
                // corrected list on their next subscribe.
                unset($before);
                break;

            case 'join':
                $channel = (string) ($message['channel'] ?? '');
                $userId  = (string) ($message['user_id'] ?? '');
                if ($channel === '' || $userId === '') {
                    return;
                }

                // Decided before applying: if the user is already visible here, this
                // is a second connection somewhere and not an arrival.
                $announce = !$this->userIsPresentAnywhere($channel, $userId);

                if (!$this->clusterState->applyJoin(
                    $node,
                    $channel,
                    $userId,
                    is_array($message['info'] ?? null) ? $message['info'] : [],
                    $ts
                )) {
                    return;
                }

                if ($announce) {
                    $this->announceMember($channel, 'member_added', $userId, $message['info'] ?? []);
                }
                break;

            case 'leave':
                $channel = (string) ($message['channel'] ?? '');
                $userId  = (string) ($message['user_id'] ?? '');
                if ($channel === '' || $userId === '') {
                    return;
                }

                if (!$this->clusterState->applyLeave($node, $channel, $userId, $ts)) {
                    return;
                }

                // Decided after applying: the user has left only if nothing else,
                // here or on another node, still reports them.
                if (!$this->userIsPresentAnywhere($channel, $userId)) {
                    $this->announceMember($channel, 'member_removed', $userId, []);
                }
                break;

            case 'client_event':
                $channel = (string) ($message['channel'] ?? '');
                $event   = (string) ($message['event'] ?? '');

                // The same guards as a local client event, because a peer's
                // enforcement is not something to take on trust: a compromised or
                // misconfigured node must not be able to publish onto a public
                // channel here.
                if (
                    $event === ''
                    || !str_starts_with($event, 'client-')
                    || (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-'))
                ) {
                    return;
                }

                $this->relayToChannel($channel, $event, (string) ($message['data'] ?? '{}'));
                break;

            case 'heartbeat':
                $this->clusterState->applyHeartbeat($node, $ts);
                break;
        }
    }

    /**
     * Tell local subscribers about a member arriving or leaving on another node.
     *
     * No webhook: those stay with the node that owns the connection, so exactly one
     * node reports each member and no cross-node deduplication is needed.
     *
     * @param array<string,mixed>|mixed $info
     */
    private function announceMember(string $channel, string $name, string $userId, mixed $info): void
    {
        $member = ['user_id' => $userId];

        if (is_array($info) && $info !== []) {
            $member['user_info'] = $info;
        }

        // -1 excludes nothing: every local subscriber should hear about a remote
        // member, since none of them is the one that arrived.
        $this->sendToChannelExcept($channel, 'pusher_internal:' . $name, $member, -1);
    }

    /**
     * Deliver a pre-encoded event body to every local subscriber of $channel.
     */
    private function relayToChannel(string $channel, string $event, string $data): void
    {
        $payload = json_encode([
            'event'   => $event,
            'data'    => $data,
            'channel' => $channel,
        ]);

        foreach ($this->subscriptions[$channel] ?? [] as $clientId) {
            if (isset($this->clients[$clientId])) {
                $this->wsSend($this->clients[$clientId]['socket'], (string) $payload);
                $this->counters['messages_sent']++;
            }
        }
    }

    /**
     * Publish this node's full membership, and drop peers that have gone quiet.
     *
     * The full state is the correctness mechanism: whatever a peer missed, it is
     * right again within one interval, so no individual delta has to be reliable.
     * A heartbeat is sent even with nothing to report, or a node serving only empty
     * channels would look dead and be pruned — then reappear on its next join,
     * churning the member list of every channel it does serve.
     */
    private function gossipState(): void
    {
        if ($this->cluster === null || $this->clusterState === null) {
            return;
        }

        $now = time();
        if ($now - $this->lastGossipAt < $this->gossipInterval) {
            return;
        }
        $this->lastGossipAt = $now;

        $channels = [];
        foreach ($this->presence as $channel => $members) {
            foreach ($members as $member) {
                $channels[$channel][$member['user_id']] = $member['user_info'] ?? [];
            }
        }

        $this->gossip($channels === []
            ? ['type' => 'heartbeat']
            : ['type' => 'state', 'channels' => $channels]);

        foreach ($this->clusterState->pruneExpired() as $node => $byChannel) {
            \Pramnos\Logs\Logger::log(
                'Broadcasting cluster: node ' . $node . ' expired; dropping its members.',
                'broadcasting'
            );

            foreach ($byChannel as $channel => $userIds) {
                foreach ($userIds as $userId) {
                    if (!$this->userIsPresentAnywhere((string) $channel, (string) $userId)) {
                        $this->announceMember((string) $channel, 'member_removed', (string) $userId, []);
                    }
                }
            }
        }
    }

    /**
     * Record a lifecycle event, if anybody is listening.
     *
     * The guard is what keeps this free for the deployments that do not use
     * webhooks: with no dispatcher there is no array to grow and no work per
     * subscribe.
     *
     * @param array<string,mixed> $event
     */
    private function queueWebhook(array $event): void
    {
        if ($this->webhooks !== null) {
            $this->pendingWebhooks[] = $event;
            $this->counters['webhook_events_queued']++;
        }
    }

    /**
     * Hand this iteration's events to the dispatcher.
     *
     * The buffer is cleared **before** dispatching, so a dispatcher that throws
     * cannot make the same batch be re-sent on every subsequent iteration —
     * which would turn one unreachable endpoint into an unbounded resend loop.
     */
    private function flushWebhooks(): void
    {
        if ($this->webhooks === null || $this->pendingWebhooks === []) {
            return;
        }

        $batch                 = $this->pendingWebhooks;
        $this->pendingWebhooks = [];

        try {
            $this->webhooks->dispatch($batch);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log(
                'Broadcasting: webhook dispatch failed: ' . $e->getMessage(),
                'broadcasting'
            );
        }
    }

    private function loopIteration(): void
    {
        $read = [$this->serverSocket];
        foreach ($this->clients as $client) {
            $read[] = $client['socket'];
        }

        $redisStream = $this->redisIngest?->getStream();
        if (is_resource($redisStream)) {
            $read[] = $redisStream;
        }

        $write  = null;
        $except = null;
        // 100 ms select timeout so we can poll the log file frequently enough
        $changed = @stream_select($read, $write, $except, 0, 100_000);

        if ($changed === false || $changed === 0) {
            $this->drainRedisIngest();
            $this->pollLogFile();
            $this->sendKeepalives();
            $this->gossipState();
            $this->flushWebhooks();
            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                $this->acceptClient();
            } elseif ($redisStream !== null && $socket === $redisStream) {
                $this->drainRedisIngest();
            } else {
                $this->readClient($socket);
            }
        }

        $this->drainRedisIngest();
        $this->pollLogFile();
        $this->sendKeepalives();
        $this->gossipState();
        $this->flushWebhooks();
    }

    /**
     * Pull any complete pub/sub messages from the Redis ingest and fan them out
     * to subscribed clients. An enveloped {event,payload} broadcasts as that
     * event; a raw message broadcasts as a "message" event.
     */
    private function drainRedisIngest(): void
    {
        if ($this->redisIngest === null) {
            return;
        }
        foreach ($this->redisIngest->drain() as $msg) {
            $decoded = json_decode($msg['message'], true);
            $except  = null;

            // Gossip is not an application event and must never reach a browser.
            // Checked before anything else, because everything below fans out.
            if (
                $this->cluster !== null
                && $this->clusterIngestChannel !== ''
                && $msg['channel'] === $this->clusterIngestChannel
            ) {
                $body = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : $decoded;
                if (is_array($body)) {
                    $this->handleClusterMessage($body);
                }
                continue;
            }

            if (is_array($decoded) && array_key_exists('event', $decoded)) {
                $event   = (string) $decoded['event'];
                $payload = $decoded['payload'] ?? [];

                // `toOthers()` writes the originating socket id into the envelope,
                // because the publishing process and this one are not the same and
                // anything held in PHP memory is gone by the time the event
                // arrives here. Absent on every envelope that did not ask for it.
                if (isset($decoded['except']) && is_string($decoded['except'])) {
                    $except = $decoded['except'];
                }
            } else {
                $event   = 'message';
                $payload = $decoded ?? $msg['message'];
            }

            if ($this->ingestRouter !== null) {
                // The entry id is passed fourth and defaulted, so a router written before this
                // existed keeps working — PHP does not complain about an argument a closure
                // does not declare. A router that wants it declares it.
                $id = isset($msg['id']) ? (string) $msg['id'] : null;

                foreach ((($this->ingestRouter)($msg['channel'], $event, $payload, $id) ?? []) as $route) {
                    $this->broadcastExcept(
                        (string) $route[0],
                        (string) ($route[1] ?? $event),
                        $route[2] ?? $payload,
                        $except
                    );
                }
            } else {
                $this->broadcastExcept($msg['channel'], $event, $payload, $except);
            }
        }
    }

    private function acceptClient(): void
    {
        $socket = @stream_socket_accept($this->serverSocket, 0);
        if ($socket === false) {
            return;
        }
        stream_set_blocking($socket, false);

        $id = $this->nextSocketId++;
        $this->counters['connections_total']++;
        $this->clients[$id] = [
            'socket'   => $socket,
            'state'    => 'handshaking', // handshaking | connected | closing
            'buffer'   => '',
            'channels' => [],
            'socketId' => "{$id}.{$this->nextSocketId}",
            'pingAt'   => time() + 30,
            // Created once the handshake completes; frames cannot arrive before.
            'assembler' => null,
        ];
    }

    private function readClient(mixed $socket): void
    {
        $id = $this->findClientId($socket);
        if ($id === null) {
            return;
        }

        $client = &$this->clients[$id];
        $data   = @fread($socket, 8192);

        if ($data === false || ($data === '' && feof($socket))) {
            $this->disconnectClient($id);
            return;
        }

        if ($client['state'] === 'handshaking') {
            $client['buffer'] .= $data;
            $this->processHandshake($id);
        } else {
            // The assembler owns the partial-frame buffer from here on.
            $this->ingestFrames($id, $data);
        }
    }

    // =========================================================================
    // WebSocket handshake (RFC 6455 §4.2)
    // =========================================================================

    private function processHandshake(int $id): void
    {
        $client = &$this->clients[$id];
        $buf    = $client['buffer'];

        // Wait until we have the full HTTP request headers
        if (strpos($buf, "\r\n\r\n") === false) {
            return;
        }

        $headers   = $this->parseHttpHeaders($buf);
        $wsKey     = $headers['sec-websocket-key'] ?? '';
        $upgrade   = strtolower($headers['upgrade'] ?? '');
        $conn      = strtolower($headers['connection'] ?? '');

        if ($upgrade !== 'websocket' || strpos($conn, 'upgrade') === false || $wsKey === '') {
            // Not an upgrade. It may still be an API call — those arrive on the
            // same port, because a second listener would need its own address,
            // its own firewall rule and its own supervisor entry to carry
            // requests the process is already able to answer.
            if ($this->httpApi !== null && $this->serveApiRequest($id, $buf)) {
                return;
            }

            $this->sendHttpError($client['socket'], 400, 'Bad Request');
            $this->disconnectClient($id);
            return;
        }

        // Authorize the connection by the app key in the request target
        // (Pusher clients connect to /app/<app_key>?protocol=7&...).
        [$appKey, $params] = $this->parseRequestTarget($buf);
        if (!$this->authorizer->authorizeConnection($appKey, $params)) {
            $this->sendHttpError($client['socket'], 401, 'Unauthorized');
            $this->disconnectClient($id);
            return;
        }

        // RFC 6455 §4.2.2 — compute accept key
        $acceptKey = base64_encode(sha1($wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
            . "\r\n";

        fwrite($client['socket'], $response);

        $client['state'] = 'connected';

        // Everything after the header terminator is already a WebSocket frame.
        // Discarding the buffer here lost the first frame of any client that
        // pipelined it into the same segment as the handshake.
        $remainder = substr($buf, strpos($buf, "\r\n\r\n") + 4);

        $client['buffer']    = '';
        $client['assembler'] = new MessageAssembler();

        // Pusher protocol: send pusher:connection_established event
        $this->wsSend($client['socket'], json_encode([
            'event' => 'pusher:connection_established',
            'data'  => json_encode([
                'socket_id'        => $client['socketId'],
                'activity_timeout' => 120,
            ]),
        ]));

        if ($remainder !== '') {
            $this->ingestFrames($id, $remainder);
        }
    }

    /**
     * Parse the HTTP request line for the Pusher-style app key and query params.
     *
     * Target looks like "/app/<app_key>?protocol=7&client=js&version=8.4.0".
     * Returns [appKey, params]; appKey is '' when the path has no /app/ segment.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private function parseRequestTarget(string $request): array
    {
        $firstLine = strtok($request, "\r\n") ?: '';
        $parts     = explode(' ', $firstLine);
        $target    = $parts[1] ?? '';

        $path  = parse_url($target, PHP_URL_PATH) ?: '';
        $query = parse_url($target, PHP_URL_QUERY) ?: '';

        $appKey = '';
        if (preg_match('#/app/([^/?]+)#', $path, $m)) {
            $appKey = $m[1];
        }

        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }

        return [$appKey, array_map('strval', $params)];
    }

    private function parseHttpHeaders(string $request): array
    {
        $headers = [];
        $lines   = explode("\r\n", $request);
        foreach (array_slice($lines, 1) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    // =========================================================================
    // WebSocket framing (RFC 6455 §5)
    // =========================================================================

    /**
     * Feed received bytes to this client's assembler and act on every complete
     * message it yields.
     *
     * Framing itself lives in {@see FrameCodec} / {@see MessageAssembler}, shared
     * with {@see \Pramnos\Http\WebSocketClient}. Before that extraction this
     * method read the opcode but never the FIN bit, so a fragmented text message
     * reached handleTextMessage() as separate halves — each an invalid JSON
     * document, from a sender that had done nothing wrong.
     */
    private function ingestFrames(int $id, string $bytes): void
    {
        $client = &$this->clients[$id];

        // Created here when absent rather than assumed: a connection reaching
        // this point without having gone through acceptClient() would otherwise
        // read every frame into a no-op, which is silence rather than an error.
        if (!($client['assembler'] ?? null) instanceof MessageAssembler) {
            $client['assembler'] = new MessageAssembler();
        }
        $assembler = $client['assembler'];

        try {
            $messages = $assembler->feed($bytes);
        } catch (WebSocketProtocolException) {
            // A framing violation cannot be recovered from mid-stream: the byte
            // offsets are lost, so every later frame is misread too.
            $this->wsSendClose($client['socket']);
            $this->disconnectClient($id);
            return;
        }

        foreach ($messages as $message) {
            switch ($message['opcode']) {
                case FrameCodec::OP_TEXT:
                    $this->handleTextMessage($id, $message['payload']);
                    break;
                case FrameCodec::OP_CLOSE:
                    $this->wsSendClose($client['socket']);
                    $this->disconnectClient($id);
                    return;
                case FrameCodec::OP_PING:
                    $this->wsSend($client['socket'], $message['payload'], FrameCodec::OP_PONG);
                    break;
                case FrameCodec::OP_PONG:
                    break;
            }
        }
    }

    /**
     * Send a WebSocket text frame (or specified opcode) to $socket.
     */
    private function wsSend(mixed $socket, string $payload, int $opcode = FrameCodec::OP_TEXT): void
    {
        // A server MUST NOT mask (RFC 6455 §5.3), hence mask: false.
        @fwrite($socket, FrameCodec::encode($payload, $opcode, false));
    }

    private function wsSendClose(mixed $socket): void
    {
        @fwrite($socket, FrameCodec::encode('', FrameCodec::OP_CLOSE, false));
    }

    // =========================================================================
    // Pusher protocol messages
    // =========================================================================

    private function handleTextMessage(int $id, string $payload): void
    {
        $msg = @json_decode($payload, true);
        if (!is_array($msg) || !isset($msg['event'])) {
            return;
        }

        switch ($msg['event']) {
            case 'pusher:subscribe':
                $this->handleSubscribe($id, $msg['data'] ?? []);
                break;
            case 'pusher:unsubscribe':
                $this->handleUnsubscribe($id, ($msg['data'] ?? [])['channel'] ?? '');
                break;
            case 'pusher:ping':
                $client = &$this->clients[$id];
                $this->wsSend($client['socket'], json_encode(['event' => 'pusher:pong', 'data' => '{}']));
                break;
            default:
                if (str_starts_with((string) $msg['event'], 'client-')) {
                    $this->handleClientEvent($id, (string) $msg['event'], $msg);
                }
                break;
        }
    }

    /**
     * Relay a `client-*` event from one browser to the rest of a channel.
     *
     * Every refusal below is silent — no error frame is sent back. That is
     * deliberate: a client event is fire-and-forget by design, and answering each
     * rejected one would hand a browser a cheap way to make the server talk, which
     * is the opposite of what a rate limit is for.
     *
     * @param array<string,mixed> $msg The decoded client frame.
     */
    private function handleClientEvent(int $id, string $event, array $msg): void
    {
        if (!$this->clientEventsEnabled) {
            $this->counters['client_events_refused']++;
            return;
        }

        $channel = (string) ($msg['channel'] ?? '');

        // Private and presence channels only. A public channel has no membership
        // test at all, so relaying client events on one would let any connection
        // publish to every listener — an open write path dressed as a feature.
        if (
            !str_starts_with($channel, 'private-')
            && !str_starts_with($channel, 'presence-')
        ) {
            $this->counters['client_events_refused']++;
            return;
        }

        // The sender must itself be subscribed. Without this check a connection
        // could publish into any channel it can name, having never been authorized
        // for it — the subscription is the only proof of authorization the daemon
        // holds.
        if (!isset($this->subscriptions[$channel][$id])) {
            $this->counters['client_events_refused']++;
            return;
        }

        if (!$this->consumeClientEventBudget($id)) {
            $this->counters['client_events_refused']++;
            return;
        }

        $this->counters['client_events_relayed']++;

        $data = $msg['data'] ?? [];

        $this->queueWebhook([
            'name'      => 'client_event',
            'channel'   => $channel,
            'event'     => $event,
            'data'      => is_string($data) ? $data : json_encode($data),
            'socket_id' => $this->clients[$id]['socketId'] ?? '',
        ]);

        $this->gossip([
            'type'      => 'client_event',
            'channel'   => $channel,
            'event'     => $event,
            'data'      => is_string($data) ? $data : json_encode($data),
            'socket_id' => $this->clients[$id]['socketId'] ?? '',
        ]);

        $payload = json_encode([
            'event'   => $event,
            'data'    => is_string($data) ? $data : json_encode($data),
            'channel' => $channel,
        ]);

        // Not echoed to the sender: it already knows what it typed, and echoing is
        // how a client ends up rendering its own cursor twice.
        foreach ($this->subscriptions[$channel] as $clientId) {
            if ($clientId === $id || !isset($this->clients[$clientId])) {
                continue;
            }
            $this->wsSend($this->clients[$clientId]['socket'], (string) $payload);
        }
    }

    /**
     * Take one unit of this connection's per-second client-event budget.
     *
     * A fixed window rather than a sliding one: the failure mode of a fixed window
     * is that a sender can burst twice the limit across a boundary, and for typing
     * indicators that is not worth the bookkeeping a sliding window costs on every
     * message.
     */
    private function consumeClientEventBudget(int $id): bool
    {
        $now = time();
        [$windowStart, $count] = $this->clientEventBudget[$id] ?? [$now, 0];

        if ($windowStart !== $now) {
            $windowStart = $now;
            $count       = 0;
        }

        if ($count >= $this->clientEventsPerSecond) {
            return false;
        }

        $this->clientEventBudget[$id] = [$windowStart, $count + 1];

        return true;
    }

    private function handleSubscribe(int $id, mixed $data): void
    {
        $data    = is_string($data) ? (json_decode($data, true) ?? []) : (array) $data;
        $channel = $data['channel'] ?? '';

        if ($channel === '') {
            return;
        }

        $client = &$this->clients[$id];

        // Authorize private-/presence- channel subscriptions (public channels pass).
        $auth        = (string) ($data['auth'] ?? '');
        $channelData = isset($data['channel_data']) ? (string) $data['channel_data'] : null;
        if (!$this->authorizer->authorizeChannel($channel, $client['socketId'], $auth, $channelData)) {
            $this->wsSend($client['socket'], json_encode([
                'event'   => 'pusher_internal:subscription_error',
                'data'    => json_encode(['type' => 'AuthError', 'status' => 401]),
                'channel' => $channel,
            ]));
            return;
        }

        if (!in_array($channel, $client['channels'], true)) {
            $client['channels'][] = $channel;
        }

        // Read before the subscription is recorded: occupancy is a transition, and
        // asking afterwards would report every subscribe as an arrival into an
        // occupied channel.
        $wasEmpty = ($this->subscriptions[$channel] ?? []) === [];

        $this->subscriptions[$channel][$id] = $id;

        if ($wasEmpty) {
            $this->queueWebhook(['name' => 'channel_occupied', 'channel' => $channel]);
        }

        // A presence channel is one that knows who is in it. Membership is opted
        // into by the authorizer implementing PresenceAuthorizer — see that
        // interface for why it is not a method on ConnectionAuthorizer. Without
        // it the branch below never runs and subscription_succeeded carries '{}',
        // exactly as it did before presence existed.
        $member = null;
        if (
            str_starts_with($channel, 'presence-')
            && $this->authorizer instanceof PresenceAuthorizer
        ) {
            $member = $this->authorizer->presenceMember($channel, $client['socketId'], $channelData);
        }

        if ($member === null) {
            $this->wsSend($client['socket'], json_encode([
                'event'   => 'pusher_internal:subscription_succeeded',
                'data'    => '{}',
                'channel' => $channel,
            ]));
            return;
        }

        // Whether this user is newly present has to be decided *before* adding
        // this connection, or a second tab would announce its own arrival.
        $alreadyPresent = $this->userIsPresentAnywhere($channel, $member['user_id']);

        $this->presence[$channel][$id] = $member;

        $this->gossip([
            'type'    => 'join',
            'channel' => $channel,
            'user_id' => $member['user_id'],
            'info'    => $member['user_info'] ?? [],
        ]);

        // The subscriber's own list includes itself, per the Pusher protocol: a
        // client that had to add itself would show a different room to the person
        // who just joined than to everyone already there.
        $this->wsSend($client['socket'], json_encode([
            'event'   => 'pusher_internal:subscription_succeeded',
            'data'    => json_encode($this->presencePayload($channel)),
            'channel' => $channel,
        ]));

        if (!$alreadyPresent) {
            $this->sendToChannelExcept($channel, 'pusher_internal:member_added', $member, $id);
            $this->queueWebhook([
                'name'    => 'member_added',
                'channel' => $channel,
                'user_id' => $member['user_id'],
            ]);
        }
    }

    /**
     * The presence payload for $channel: one entry per distinct user, not per
     * connection.
     *
     * @return array{presence:array{ids:list<string>, hash:array<string,mixed>, count:int}}
     */
    private function presencePayload(string $channel): array
    {
        $hash = [];
        $ids  = [];

        // Remote members first, so a locally-connected copy of the same person wins
        // on info — the local view is the fresher of the two by construction.
        foreach ($this->clusterState?->remoteMembers($channel) ?? [] as $userId => $info) {
            $hash[$userId] = $info;
            $ids[$userId]  = (string) $userId;
        }

        foreach ($this->presence[$channel] ?? [] as $member) {
            // Last write wins for a user with several connections. They carry the
            // same identity by construction — the auth endpoint derived it from
            // one session — so which one lands is not a meaningful difference.
            $hash[$member['user_id']] = $member['user_info'] ?? [];

            // Collected separately, and deliberately not as array_keys($hash):
            // PHP casts a numeric string array key to an integer, so a member id
            // of "7" would come back out as int 7 and serialise to [7] instead of
            // ["7"]. Clients compare member ids as strings — pusher-js does — so
            // that reads as a member who is in the room but is never recognised
            // as anybody, including as yourself.
            $ids[$member['user_id']] = (string) $member['user_id'];
        }

        return [
            'presence' => [
                'ids'   => array_values($ids),
                'hash'  => $hash,
                'count' => count($hash),
            ],
        ];
    }

    /**
     * True when $userId is present in $channel anywhere in the cluster.
     *
     * Used to decide whether an arrival or a departure is worth announcing. Locally
     * only would announce a member every node already knows about, once per node.
     */
    private function userIsPresentAnywhere(string $channel, string $userId, ?int $ignoreClient = null): bool
    {
        return $this->userIsPresent($channel, $userId, $ignoreClient)
            || ($this->clusterState?->hasRemoteMember($channel, $userId) ?? false);
    }

    /** True when some connection on $channel already carries $userId. */
    private function userIsPresent(string $channel, string $userId, ?int $ignoreClient = null): bool
    {
        foreach ($this->presence[$channel] ?? [] as $clientId => $member) {
            if ($clientId !== $ignoreClient && $member['user_id'] === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop a connection from a channel's membership, announcing the departure only
     * when it was that user's last connection.
     */
    private function leavePresence(int $id, string $channel): void
    {
        $member = $this->presence[$channel][$id] ?? null;

        if ($member === null) {
            return;
        }

        unset($this->presence[$channel][$id]);

        if ($this->presence[$channel] === []) {
            unset($this->presence[$channel]);
        }

        // Gossiped before the announcement is decided, so a peer learns of the
        // departure even if this node still has another connection for that user and
        // therefore announces nothing.
        $this->gossip([
            'type'    => 'leave',
            'channel' => $channel,
            'user_id' => $member['user_id'],
        ]);

        if (!$this->userIsPresentAnywhere($channel, $member['user_id'])) {
            $this->sendToChannelExcept($channel, 'pusher_internal:member_removed', $member, $id);
            // Webhook only for a member this node owned: each node reports its own,
            // which needs no coordination and cannot double-report.
            $this->queueWebhook([
                'name'    => 'member_removed',
                'channel' => $channel,
                'user_id' => $member['user_id'],
            ]);
        }
    }

    /**
     * Send an event to every subscriber of $channel except one connection.
     *
     * The exclusion is what makes an arrival announcement an announcement: the
     * joining client already has itself in the list it was just sent, and would
     * otherwise be told it arrived.
     *
     * @param array<string,mixed> $data
     */
    private function sendToChannelExcept(string $channel, string $event, array $data, int $exceptId): void
    {
        $payload = json_encode([
            'event'   => $event,
            'data'    => json_encode($data),
            'channel' => $channel,
        ]);

        foreach ($this->subscriptions[$channel] ?? [] as $clientId) {
            if ($clientId === $exceptId || !isset($this->clients[$clientId])) {
                continue;
            }
            $this->wsSend($this->clients[$clientId]['socket'], (string) $payload);
        }
    }

    private function handleUnsubscribe(int $id, string $channel): void
    {
        $this->leavePresence($id, $channel);

        $client   = &$this->clients[$id];
        $client['channels'] = array_filter(
            $client['channels'],
            fn($c) => $c !== $channel
        );

        unset($this->subscriptions[$channel][$id]);

        if (($this->subscriptions[$channel] ?? []) === []) {
            unset($this->subscriptions[$channel]);
            $this->queueWebhook(['name' => 'channel_vacated', 'channel' => $channel]);
        }
    }

    // =========================================================================
    // Log-file tail (integration with LogDriver)
    // =========================================================================

    /**
     * Read any new lines appended to the log file since the last poll.
     *
     * Each line must be a JSON object with keys channel, event, data
     * (the format written by LogDriver).
     */
    protected function pollLogFile(): void
    {
        if ($this->logFile === null || !file_exists($this->logFile)) {
            return;
        }

        clearstatcache(true, $this->logFile);
        $size = filesize($this->logFile);

        if ($size <= $this->logOffset) {
            // Handle log rotation: file shrank
            if ($size < $this->logOffset) {
                $this->logOffset = 0;
            }
            return;
        }

        $fp = @fopen($this->logFile, 'r');
        if ($fp === false) {
            return;
        }

        fseek($fp, $this->logOffset);
        while (($line = fgets($fp)) !== false) {
            $entry = @json_decode(trim($line), true);
            if (is_array($entry) && isset($entry['channel'], $entry['event'])) {
                // Support both LogDriver format ('payload') and generic format ('data')
                $data = $entry['payload'] ?? $entry['data'] ?? [];
                $this->broadcast($entry['channel'], $entry['event'], $data);
            }
        }
        $this->logOffset = (int) ftell($fp);
        fclose($fp);
    }

    // =========================================================================
    // Keepalives
    // =========================================================================

    private function sendKeepalives(): void
    {
        $now = time();
        foreach ($this->clients as $id => $client) {
            if ($client['state'] !== 'connected') {
                continue;
            }
            if ($now >= $client['pingAt']) {
                $this->wsSend(
                    $client['socket'],
                    json_encode(['event' => 'pusher:ping', 'data' => '{}']),
                    0x1
                );
                $this->clients[$id]['pingAt'] = $now + 30;
            }
        }
    }

    // =========================================================================
    // Connection management
    // =========================================================================

    private function disconnectClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $client = $this->clients[$id];

        foreach ($client['channels'] as $channel) {
            // Before the subscription is dropped, so the departure reaches the
            // people still in the room.
            unset($this->subscriptions[$channel][$id]);
            $this->leavePresence($id, $channel);

            if (empty($this->subscriptions[$channel])) {
                unset($this->subscriptions[$channel]);
                $this->queueWebhook(['name' => 'channel_vacated', 'channel' => $channel]);
            }
        }

        @fclose($client['socket']);
        unset($this->clients[$id], $this->clientEventBudget[$id]);
    }

    private function findClientId(mixed $socket): ?int
    {
        foreach ($this->clients as $id => $client) {
            if ($client['socket'] === $socket) {
                return $id;
            }
        }
        return null;
    }

    private function shutdown(): void
    {
        foreach ($this->clients as $id => $client) {
            $this->wsSendClose($client['socket']);
            @fclose($client['socket']);
        }
        $this->clients       = [];
        $this->subscriptions = [];

        if ($this->serverSocket !== null) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    /**
     * Try to answer $request through the HTTP API.
     *
     * @return bool True when the request was handled (and the connection closed).
     */
    private function serveApiRequest(int $id, string $request): bool
    {
        $client    = &$this->clients[$id];
        $firstLine = strtok($request, "\r\n") ?: '';
        $parts     = explode(' ', $firstLine);
        $method    = strtoupper($parts[0] ?? '');
        $target    = $parts[1] ?? '';
        $path      = (string) (parse_url($target, PHP_URL_PATH) ?: '');

        if (!str_starts_with($path, '/apps/')) {
            return false;
        }

        $query = [];
        parse_str((string) (parse_url($target, PHP_URL_QUERY) ?: ''), $query);

        $separator = strpos($request, "\r\n\r\n");
        $body      = $separator === false ? '' : substr($request, $separator + 4);

        // A body that has not fully arrived would be signed-but-truncated, and
        // body_md5 would reject it as tampering. Wait for the rest instead: the
        // buffer keeps accumulating and this runs again on the next read.
        $headers = $this->parseHttpHeaders($request);
        if (isset($headers['content-length'])) {
            $expected = (int) $headers['content-length'];
            if (strlen($body) < $expected) {
                return true;    // handled in the sense of "not an error yet"
            }
            $body = substr($body, 0, $expected);
        }

        $result = $this->httpApi->handle(
            $method,
            $path,
            array_map('strval', $query),
            $body
        );

        $this->sendJsonResponse($client['socket'], $result['status'], $result['body']);
        $this->disconnectClient($id);

        return true;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function sendJsonResponse(mixed $socket, int $status, array $body): void
    {
        $encoded = (string) json_encode($body);
        $reason  = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            default => 'Error',
        };

        fwrite($socket, "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($encoded) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $encoded);
    }

    private function sendHttpError(mixed $socket, int $code, string $message): void
    {
        fwrite($socket, "HTTP/1.1 {$code} {$message}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
}
