<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

use Pramnos\Http\Client as Http;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Security\Encrypter;

/**
 * The MCP servers a person connects to themselves — their own GitHub, Notion, calendar — the way
 * a chat product's "connectors" work. On with `'llm' => ['chat' => ['connections' => true]]`.
 *
 * A connection is an address over HTTPS (never a program to run), and either:
 *
 * - **a token** the person pastes, sent as `Authorization: Bearer …`; or
 * - **a sign-in** at the server's own authorization server, when it answers 401 the MCP way:
 *   the framework finds the authorization server (RFC 9728, then RFC 8414), registers itself as a
 *   client (RFC 7591), sends the person there with PKCE, and keeps the tokens it gets back,
 *   refreshing them when they run out.
 *
 * Every request — the server's, its metadata's, the token endpoint's — goes through the SSRF
 * guard: an address on the server's own network is never reached, whoever names it. The
 * addresses and the tokens are the person's setting {@see SETTING}, encrypted, so they go with
 * their account; what tools each server offers is cached, and asked again when the cache lets go.
 *
 * The tools reach the model as `{connection}__{tool}`, in a skill named after the connection,
 * `untrusted` like every MCP tool, and those that change something wait for the person after
 * anything untrusted has been read. They are offered in the person's own conversations only,
 * never in an answer others read: the credentials are theirs.
 */
final class Connections
{
    /** The person's setting that holds them, encrypted. */
    public const SETTING = 'ai_mcp_connections';

    /** Connections a person keeps at most. */
    public const MAX = 5;

    /** Seconds per request to a server or its sign-in. */
    public const TIMEOUT = 20;

    /** @var array<string, Client> Connected this request, by name */
    private array $clients = [];

    /** The address the sign-in comes back to: the application's `chat/connected`, by default. */
    private string $returnTo;

    public function __construct(private readonly Scope $scope, ?string $returnTo = null)
    {
        $this->returnTo = $returnTo ?? (defined('sURL') ? sURL : '/') . 'chat/connected';
    }

    /** Whether the application lets its people connect servers of their own. */
    public static function on(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat) && !empty($chat['connections']) && Encrypter::isAvailable();
    }

    /**
     * The person's connections, without their secrets: name, url, how they sign in, how many
     * tools, and when they were added.
     *
     * @return list<array{name: string, url: string, auth: string, tools: ?int, added_at: int}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->stored()['servers'] as $name => $one) {
            $out[] = ['name' => (string) $name, 'url' => (string) $one['url'], 'auth' => (string) $one['auth'],
                'tools' => ($listed = $this->cached((string) $name)) === null ? null : count($listed), 'added_at' => (int) ($one['added_at'] ?? 0)];
        }

        return $out;
    }

    /**
     * Connect a server. Answers `['connected' => name]`; `['authorize' => url]` when the server
     * wants the person to sign in there first — send them to it, and {@see finish()} takes them
     * back; or `['error' => why]`.
     *
     * @return array{connected?: string, authorize?: string, error?: string}
     */
    public function add(string $name, string $url, string $token = ''): array
    {
        $name  = strtolower(trim($name));
        $url   = trim($url);
        $token = trim($token);
        $saved = $this->stored();
        if (preg_match('/^[a-z][a-z0-9_]{0,19}$/', $name) !== 1) {
            return ['error' => 'A name is a letter, then up to 19 letters, digits or _.'];
        }
        if (preg_match('#^https://#i', $url) !== 1) {
            return ['error' => 'A server is an https:// address.'];
        }
        if (!isset($saved['servers'][$name]) && count($saved['servers']) >= self::MAX) {
            return ['error' => 'Up to ' . self::MAX . ' connections; remove one first.'];
        }

        $server = ['url' => $url, 'auth' => $token === '' ? 'none' : 'token', 'token' => $token, 'added_at' => time()];
        try {
            $this->remember($name, $this->client($name, $server)->listTools());
        } catch (McpException $why) {
            if ($why->status === 401 && $token === '') {
                return $this->authorize($name, $url, $why->authenticate);
            }

            return ['error' => $why->status === 401 ? 'The server did not take that token.' : $why->getMessage()];
        }
        $saved['servers'][$name] = $server;

        return $this->store($saved) ? ['connected' => $name] : ['error' => 'This account cannot keep a connection.'];
    }

    /**
     * Back from signing in: the code for the tokens, then the tools. Answers as {@see add()}.
     *
     * @return array{connected?: string, error?: string}
     */
    public function finish(string $state, string $code): array
    {
        $saved   = $this->stored();
        $pending = $saved['pending'][$state] ?? null;
        unset($saved['pending'][$state]);
        $this->store($saved);
        if (!is_array($pending) || (int) $pending['until'] < time() || $code === '') {
            return ['error' => 'That sign-in is not one this page started, or it took too long. Connect again.'];
        }

        $tokens = $this->tokens((string) $pending['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->returnTo,
            'client_id' => $pending['client_id'], 'code_verifier' => $pending['verifier'], 'resource' => $pending['url'],
        ]);
        if (isset($tokens['error'])) {
            return $tokens;
        }
        $server = ['url' => $pending['url'], 'auth' => 'oauth', 'token' => $tokens['access_token'], 'added_at' => time(),
            'oauth' => ['client_id' => $pending['client_id'], 'token_endpoint' => $pending['token_endpoint']] + array_intersect_key($tokens, ['refresh_token' => 1, 'expires_at' => 1])];
        try {
            $this->remember((string) $pending['name'], $this->client((string) $pending['name'], $server)->listTools());
        } catch (McpException $why) {
            return ['error' => $why->getMessage()];
        }
        $saved = $this->stored();
        $saved['servers'][(string) $pending['name']] = $server;

        return $this->store($saved) ? ['connected' => (string) $pending['name']] : ['error' => 'This account cannot keep a connection.'];
    }

    /** Remove a connection. Answers whether there was one. */
    public function remove(string $name): bool
    {
        $saved = $this->stored();
        if (!isset($saved['servers'][$name])) {
            return false;
        }
        unset($saved['servers'][$name]);
        $this->store($saved);
        try {
            \Pramnos\Cache\Cache::getInstance('llm')->delete($this->cacheKey($name));
        } catch (\Throwable) {
            // A cache that cannot let it go keeps tools no connection will run.
        }

        return true;
    }

    /**
     * Every connection's tools, from what each offered when last asked — the cache's, so a server
     * is asked only when the cache has let it go, or when the model calls one of its tools. A
     * server that cannot be reached costs its own tools, not the answer.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        $out = [];
        foreach ($this->stored()['servers'] as $name => $server) {
            $out += Client::toolsFrom($this->listed((string) $name, $server), (string) $name,
                fn (string $tool, array $arguments): string => $this->call((string) $name, $tool, $arguments), ['skills' => [(string) $name]]);
        }

        return $out;
    }

    /** Ask the server again what it offers, and keep that. Answers an error, or null. */
    public function refresh(string $name): ?string
    {
        $server = $this->stored()['servers'][$name] ?? null;
        if ($server === null) {
            return 'There is no such connection.';
        }
        try {
            $this->remember($name, $this->client($name, $this->fresh($name, $server))->listTools());
        } catch (McpException $why) {
            return $why->getMessage();
        }

        return null;
    }

    /**
     * A connection's tools: the cache's, or asked of the server now and cached.
     *
     * @param array<string, mixed> $server
     * @return list<array<string, mixed>>
     */
    private function listed(string $name, array $server): array
    {
        $cached = $this->cached($name);
        if ($cached !== null) {
            return $cached;
        }
        try {
            $listed = $this->client($name, $this->fresh($name, $server))->listTools();
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('MCP connection ' . $name . ' of user ' . $this->scope->userId . ': ' . $why->getMessage(), 'llm');

            return [];
        }
        $this->remember($name, $listed);

        return $listed;
    }

    /** @return list<array<string, mixed>>|null */
    private function cached(string $name): ?array
    {
        try {
            $raw = \Pramnos\Cache\Cache::getInstance('llm')->load($this->cacheKey($name));
        } catch (\Throwable) {
            return null;
        }
        $listed = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($listed) ? $listed : null;
    }

    /** @param list<array<string, mixed>> $listed */
    private function remember(string $name, array $listed): void
    {
        try {
            \Pramnos\Cache\Cache::getInstance('llm')->save((string) json_encode($listed), $this->cacheKey($name));
        } catch (\Throwable) {
            // Not cached: the server is asked again next time.
        }
    }

    private function cacheKey(string $name): string
    {
        return 'mcp-tools-' . $this->scope->userId . '-' . $name;
    }

    /** One tool, on its server, with a token that is still good. */
    private function call(string $name, string $tool, array $arguments): string
    {
        $server = $this->stored()['servers'][$name] ?? null;
        if ($server === null) {
            return 'Error: the connection ' . $name . ' was removed.';
        }

        return $this->client($name, $this->fresh($name, $server))->callTool($tool, $arguments);
    }

    /**
     * A signed-in connection with a token that runs out within the minute is refreshed first, and
     * the new tokens kept. A refresh that fails leaves the old token: the server says why.
     *
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function fresh(string $name, array $server): array
    {
        $oauth = $server['oauth'] ?? null;
        if (!is_array($oauth) || (int) ($oauth['expires_at'] ?? PHP_INT_MAX) > time() + 60 || empty($oauth['refresh_token'])) {
            return $server;
        }
        $tokens = $this->tokens((string) $oauth['token_endpoint'], [
            'grant_type' => 'refresh_token', 'refresh_token' => $oauth['refresh_token'], 'client_id' => $oauth['client_id'], 'resource' => $server['url'],
        ]);
        if (isset($tokens['error'])) {
            return $server;
        }
        $server['token'] = $tokens['access_token'];
        $server['oauth'] = array_intersect_key($tokens, ['refresh_token' => 1, 'expires_at' => 1]) + $oauth;
        $saved = $this->stored();
        $saved['servers'][$name] = $server;
        $this->store($saved);

        return $server;
    }

    /**
     * Start signing in: where the server's authorization server is, a client registered there, and
     * the address to send the person to — with PKCE, and a state this person's setting keeps for
     * ten minutes.
     *
     * @return array{authorize?: string, error?: string}
     */
    private function authorize(string $name, string $url, string $authenticate): array
    {
        try {
            $resource = preg_match('/resource_metadata="([^"]+)"/', $authenticate, $m) === 1
                ? $m[1]
                : self::origin($url) . '/.well-known/oauth-protected-resource';
            $issuer   = (string) (($this->json(Http::get($resource))['authorization_servers'] ?? [])[0] ?? self::origin($url));
            $meta     = $this->json(Http::get(rtrim($issuer, '/') . '/.well-known/oauth-authorization-server'));
            if (!isset($meta['authorization_endpoint'], $meta['token_endpoint'], $meta['registration_endpoint'])) {
                return ['error' => 'The server asks for a sign-in this application cannot do: it offers no client registration. Paste a token instead.'];
            }
            $client = $this->json(Http::post((string) $meta['registration_endpoint'])->json([
                'client_name' => 'Pramnos', 'redirect_uris' => [$this->returnTo], 'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
            ]));
        } catch (\Throwable $why) {
            return ['error' => 'The server\'s sign-in could not be found: ' . $why->getMessage()];
        }
        if (empty($client['client_id'])) {
            return ['error' => 'The server\'s sign-in did not register this application.'];
        }

        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $state    = bin2hex(random_bytes(16));
        $saved    = $this->stored();
        $saved['pending'] = array_filter((array) $saved['pending'], static fn (array $p): bool => (int) $p['until'] >= time());
        $saved['pending'][$state] = ['name' => $name, 'url' => $url, 'verifier' => $verifier, 'client_id' => (string) $client['client_id'],
            'token_endpoint' => (string) $meta['token_endpoint'], 'until' => time() + 600];
        if (!$this->store($saved)) {
            return ['error' => 'This account cannot keep a connection.'];
        }

        return ['authorize' => (string) $meta['authorization_endpoint'] . (str_contains((string) $meta['authorization_endpoint'], '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $this->returnTo, 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'resource' => $url,
        ])];
    }

    /**
     * The token endpoint's answer: `access_token`, and `refresh_token` and `expires_at` where it
     * gave them; or `error`.
     *
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function tokens(string $endpoint, array $form): array
    {
        try {
            $answer = $this->json(Http::post($endpoint)->form($form));
        } catch (\Throwable $why) {
            return ['error' => 'The sign-in could not be finished: ' . $why->getMessage()];
        }
        if (empty($answer['access_token'])) {
            return ['error' => 'The sign-in gave no token' . (isset($answer['error']) ? ' (' . $answer['error'] . ')' : '') . '.'];
        }

        return ['access_token' => (string) $answer['access_token']]
            + (isset($answer['refresh_token']) ? ['refresh_token' => (string) $answer['refresh_token']] : [])
            + (isset($answer['expires_in']) ? ['expires_at' => time() + (int) $answer['expires_in']] : []);
    }

    /**
     * A request through the SSRF guard, answered in JSON.
     *
     * @return array<string, mixed>
     */
    private function json(Http $request): array
    {
        $response = $request->forUserSuppliedUrl()->timeout(self::TIMEOUT)->header('Accept', 'application/json')->send();
        if (!$response->successful()) {
            throw new McpException('It answered HTTP ' . $response->status() . '.');
        }

        return (array) ($response->json() ?? []);
    }

    /** @param array<string, mixed> $server */
    private function client(string $name, array $server): Client
    {
        $headers = (string) ($server['token'] ?? '') === '' ? [] : ['Authorization' => 'Bearer ' . $server['token']];

        // By the token too: a refused one, or one just refreshed, is not the session to reuse.
        return $this->clients[$name . ':' . hash('sha256', (string) $server['url'] . ($server['token'] ?? ''))]
            ??= new Client(new HttpTransport((string) $server['url'], $headers, true), self::TIMEOUT);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * What the person's setting holds, decrypted.
     *
     * @return array{servers: array<string, array<string, mixed>>, pending: array<string, array<string, mixed>>}
     */
    private function stored(): array
    {
        $raw  = (string) (new \Pramnos\User\User($this->scope->userId))->getSetting(self::SETTING, '');
        $data = $raw === '' ? [] : json_decode(Encrypter::maybeDecrypt($raw), true);

        return ['servers' => (array) ($data['servers'] ?? []), 'pending' => (array) ($data['pending'] ?? [])];
    }

    /**
     * Keep them in the person's setting, encrypted. Answers whether it was written: an account
     * that cannot keep settings keeps no connection.
     *
     * @param array<string, mixed> $data
     */
    private function store(array $data): bool
    {
        $kept = (new \Pramnos\User\User($this->scope->userId))->setSetting(self::SETTING, Encrypter::encrypt((string) json_encode($data, JSON_UNESCAPED_SLASHES)));
        \Pramnos\User\User::clearUserCache();

        return $kept;
    }
}
