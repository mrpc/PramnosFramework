<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

use Pramnos\Http\Client as Http;

/**
 * An MCP server over HTTP — the Streamable HTTP transport. Each message is a POST; the response
 * comes back as JSON, or as a stream of server-sent events of which one is the response. The
 * session the server names on `initialize` (`Mcp-Session-Id`) goes with every later message.
 */
final class HttpTransport implements Transport
{
    private ?string $session = null;

    private ?string $version = null;

    /**
     * @param array<string, string> $headers      Sent with every message: an `Authorization`, say
     * @param bool                  $userSupplied A person gave the address: every request goes through
     *                                            the SSRF guard ({@see Http::forUserSuppliedUrl()})
     */
    public function __construct(private readonly string $url, private readonly array $headers = [], private readonly bool $userSupplied = false)
    {
    }

    public function request(array $message, int $timeout): array
    {
        $response = $this->post($message, $timeout);

        if ($response->status() >= 400) {
            throw new McpException('The MCP server at ' . $this->url . ' answered HTTP ' . $response->status() . '.', 0, null,
                $response->status(), $response->header('WWW-Authenticate'));
        }
        if (($session = $response->header('Mcp-Session-Id')) !== '') {
            $this->session = $session;
        }
        if (($message['method'] ?? '') === 'initialize') {
            $this->version = (string) ($message['params']['protocolVersion'] ?? '');
        }

        foreach (self::messages($response->body(), $response->header('Content-Type')) as $answer) {
            if (($answer['id'] ?? null) === $message['id'] && (isset($answer['result']) || isset($answer['error']))) {
                return $answer;
            }
        }

        throw new McpException('The MCP server at ' . $this->url . ' sent no answer to ' . ($message['method'] ?? '') . '.');
    }

    public function notify(array $message): void
    {
        $this->post($message, 10);
    }

    public function close(): void
    {
        $this->session = null;
    }

    /** @param array<string, mixed> $message */
    private function post(array $message, int $timeout): \Pramnos\Http\ClientResponse
    {
        try {
            $request = Http::post($this->url)
                ->headers($this->headers + array_filter([
                    'Accept'               => 'application/json, text/event-stream',
                    'Mcp-Session-Id'       => $this->session,
                    'MCP-Protocol-Version' => $this->version,
                ]))
                ->json($message)
                ->timeout($timeout);
            if ($this->userSupplied) {
                $request = $request->forUserSuppliedUrl();
            }

            return $request->send();
        } catch (\Throwable $why) {
            throw new McpException('The MCP server at ' . $this->url . ' could not be reached: ' . $why->getMessage(), 0, $why);
        }
    }

    /**
     * The JSON-RPC messages in a body: the body itself, or each event's data in a stream.
     *
     * @return list<array<string, mixed>>
     */
    private static function messages(string $body, string $type): array
    {
        if (!str_contains(strtolower($type), 'text/event-stream')) {
            $one = json_decode($body, true);

            return is_array($one) ? (array_is_list($one) ? $one : [$one]) : [];
        }

        $out = [];
        foreach (preg_split('/\r?\n\r?\n/', $body) ?: [] as $event) {
            $data = [];
            foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            $one = json_decode(implode("\n", $data), true);
            if (is_array($one)) {
                $out[] = $one;
            }
        }

        return $out;
    }
}
