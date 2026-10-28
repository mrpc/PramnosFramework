<?php

declare(strict_types=1);

namespace Pramnos\Llm\Transport;

use Pramnos\Http\Client as Http;
use Pramnos\Llm\Connection;

/**
 * `POST {base_url}/chat/completions` with a bearer key: OpenAI, DeepSeek, Gemini's
 * compatibility endpoint, Mistral, and most others.
 */
final class OpenAiCompatible implements Transport
{
    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function chat(Connection $connection, array $payload): array
    {
        try {
            $response = Http::post($connection->baseUrl() . '/chat/completions')
                ->bearerToken($connection->apiKey())
                ->json($payload)
                ->timeout($connection->timeout())
                ->send();

            return [$response->status(), self::decode($response->body())];
        } catch (\Throwable $why) {
            return [0, ['error' => ['message' => $why->getMessage()]]];
        }
    }

    /**
     * {@see chat()}, streamed: `$onText` is told each piece of the answer's text as the provider
     * writes it, and the answer comes back whole, in the shape `chat()` returns — the tool calls
     * and usage put together from the stream's pieces.
     *
     * A provider that refuses a streamed request (400, 422: `stream_options` unknown to it) is
     * asked again the ordinary way; one that answers with a plain body is read as one.
     *
     * @param array<string, mixed>   $payload
     * @param callable(string): void $onText
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function chatStream(Connection $connection, array $payload, callable $onText): array
    {
        $buffer  = '';
        $events  = 0;
        $message = ['role' => 'assistant', 'content' => ''];
        $calls   = [];
        $finish  = '';
        $usage   = null;

        $read = static function (string $line) use (&$events, &$message, &$calls, &$finish, &$usage, $onText): void {
            $data = str_starts_with($line, 'data:') ? trim(substr($line, 5)) : '';
            $one  = $data === '' || $data === '[DONE]' ? null : json_decode($data, true);
            if (!is_array($one)) {
                return;
            }
            $events++;
            $usage  = is_array($one['usage'] ?? null) ? $one['usage'] : $usage;
            $choice = $one['choices'][0] ?? [];
            $finish = (string) ($choice['finish_reason'] ?? '') ?: $finish;
            $delta  = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            if (($delta['reasoning_content'] ?? '') !== '' && is_string($delta['reasoning_content'])) {
                $message['reasoning_content'] = ($message['reasoning_content'] ?? '') . $delta['reasoning_content'];
            }
            if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                $message['content'] .= $delta['content'];
                $onText($delta['content']);
            }
            // A call arrives in pieces, by index: its id and name once, its arguments a few characters at a time.
            foreach (is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $piece) {
                $at          = (int) ($piece['index'] ?? 0);
                $calls[$at] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                $calls[$at]['id']                     .= (string) ($piece['id'] ?? '');
                $calls[$at]['function']['name']      .= (string) ($piece['function']['name'] ?? '');
                $calls[$at]['function']['arguments'] .= (string) ($piece['function']['arguments'] ?? '');
            }
        };

        try {
            $response = Http::post($connection->baseUrl() . '/chat/completions')
                ->bearerToken($connection->apiKey())
                ->header('Accept', 'text/event-stream')
                ->json(['stream' => true, 'stream_options' => ['include_usage' => true]] + $payload)
                ->timeout($connection->timeout())
                ->onChunk(static function (string $chunk) use (&$buffer, $read): void {
                    $buffer .= $chunk;
                    while (($end = strpos($buffer, "\n")) !== false) {
                        $read(rtrim(substr($buffer, 0, $end), "\r"));
                        $buffer = substr($buffer, $end + 1);
                    }
                })
                ->send();
        } catch (\Throwable $why) {
            return [0, ['error' => ['message' => $why->getMessage()]]];
        }

        if (in_array($response->status(), [400, 422], true)) {
            return $this->chat($connection, $payload);
        }
        $read(rtrim($buffer, "\r"));
        if ($response->status() !== 200 || $events === 0) {
            return [$response->status(), self::decode($response->body())];
        }
        if ($calls !== []) {
            ksort($calls);
            $message['tool_calls'] = array_values($calls);
        }

        return [200, array_filter(['choices' => [['message' => $message, 'finish_reason' => $finish]], 'usage' => $usage], static fn ($v): bool => $v !== null)];
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    public function get(Connection $connection, string $path): array
    {
        try {
            $response = Http::get($connection->baseUrl() . $path)
                ->bearerToken($connection->apiKey())
                ->header('Accept', 'application/json')
                ->timeout($connection->timeout())
                ->send();

            return [$response->status(), self::decode($response->body())];
        } catch (\Throwable $why) {
            return [0, ['error' => ['message' => $why->getMessage()]]];
        }
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
