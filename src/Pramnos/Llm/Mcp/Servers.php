<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

/**
 * The external MCP servers an application connects to, and their tools.
 *
 * ```php
 * // app/app.php
 * 'llm' => ['mcp_servers' => [
 *     'github' => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-github'], 'env' => ['GITHUB_TOKEN' => '…']],
 *     'docs'   => ['url' => 'https://mcp.example.com/mcp', 'headers' => ['Authorization' => 'Bearer …'], 'skills' => ['docs']],
 * ]],
 * // or the file other MCP clients read — {"mcpServers": {…}} in the same shape
 * 'llm' => ['mcp_servers' => ROOT . '/app/mcp.json'],
 * ```
 *
 * A server is `command` (+ `args`, `env`, `cwd`) for one run as a process, or `url` (+ `headers`)
 * for one over HTTP. `timeout` is seconds per call (30). `permissions`, `skills`, `gated` and any
 * other metadata go on each of its tools, as a {@see \Pramnos\Llm\ToolRegistry} reads them.
 *
 * Each server is connected once a process, its tools listed once; a server that cannot be reached
 * is logged and left out, so one service down costs its tools and not the answer.
 */
final class Servers
{
    /** @var array<string, Client> */
    private static array $clients = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $tools = [];

    private static bool $closing = false;

    /**
     * Every configured server's tools, as the framework's.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tools(): array
    {
        $all = [];
        foreach (self::config() as $name => $server) {
            $all += self::toolsOf($name, $server);
        }

        return $all;
    }

    /** Add every configured server's tools to a registry, with their metadata. */
    public static function register(\Pramnos\Llm\ToolRegistry $registry): \Pramnos\Llm\ToolRegistry
    {
        return $registry->add(self::tools());
    }

    /**
     * The servers as configured, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function config(): array
    {
        $config = \Pramnos\Llm\Llm::config('mcp_servers', []);
        if (is_string($config)) {
            $read   = is_file($config) ? json_decode((string) file_get_contents($config), true) : null;
            $config = is_array($read) ? ($read['mcpServers'] ?? $read) : [];
        }

        return array_filter(is_array($config) ? $config : [], static fn ($server, $name): bool => is_array($server) && is_string($name), ARRAY_FILTER_USE_BOTH);
    }

    /** The client of one server, connected on first use. */
    public static function client(string $name): ?Client
    {
        $server = self::config()[$name] ?? null;
        if ($server === null) {
            return null;
        }
        if (!isset(self::$clients[$name])) {
            self::$clients[$name] = new Client(self::transport($server), max(1, (int) ($server['timeout'] ?? 30)));
            if (!self::$closing) {
                self::$closing = true;
                register_shutdown_function([self::class, 'closeAll']);
            }
        }

        return self::$clients[$name];
    }

    /** Let every server go; forget what was listed. */
    public static function closeAll(): void
    {
        foreach (self::$clients as $client) {
            try {
                $client->close();
            } catch (\Throwable) {
                // Going anyway.
            }
        }
        [self::$clients, self::$tools] = [[], []];
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, array<string, mixed>>
     */
    private static function toolsOf(string $name, array $server): array
    {
        if (isset(self::$tools[$name])) {
            return self::$tools[$name];
        }

        $meta = array_diff_key($server, array_flip(['command', 'args', 'env', 'cwd', 'url', 'headers', 'timeout', 'type']));

        try {
            return self::$tools[$name] = (self::client($name) ?? throw new McpException('No server ' . $name))->tools($name, $meta);
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('mcp server ' . $name . ': ' . $why->getMessage(), 'llm');

            return [];
        }
    }

    /** @param array<string, mixed> $server */
    private static function transport(array $server): Transport
    {
        if (isset($server['url'])) {
            return new HttpTransport((string) $server['url'], array_map('strval', (array) ($server['headers'] ?? [])));
        }

        $command = is_array($server['command'] ?? null) ? $server['command'] : [(string) ($server['command'] ?? '')];

        return new StdioTransport(
            array_values(array_map('strval', [...$command, ...(array) ($server['args'] ?? [])])),
            array_map('strval', (array) ($server['env'] ?? [])),
            isset($server['cwd']) ? (string) $server['cwd'] : null
        );
    }
}
