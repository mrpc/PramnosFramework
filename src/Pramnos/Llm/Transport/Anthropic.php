<?php

declare(strict_types=1);

namespace Pramnos\Llm\Transport;

use Pramnos\Http\Client as Http;
use Pramnos\Llm\Connection;

/**
 * Claude, through Anthropic's Messages API (`POST {base_url}/messages`), translated to and from
 * the OpenAI shape the client speaks — so the tool loop, the budgets and the call log work as
 * they do for every other provider.
 *
 * What the translation does:
 *
 * - **The system prompt** is the request's `system`. A system message later in the conversation
 *   (the client's last-round and correction notes) goes as text at the end of the user turn it
 *   follows: the Messages API takes a mid-conversation system message only after a user turn, and
 *   not on every model, where a text block is valid everywhere.
 * - **Tools** go as `{name, description, input_schema}`; a model's calls come back as
 *   `tool_calls`, and their results go back as `tool_result` blocks in one user turn.
 * - **A forced call** (`required`, or one tool by name) goes as `auto`: Claude Opus 5.5 and the
 *   newest models refuse forced tool use with a 400, and the client already tells the model in
 *   its prompt which tool it expects.
 * - **How hard it thinks** (`reasoning_effort`) is `output_config.effort`. Thinking is never sent
 *   back: replaying a thinking block after the client has edited the history — trimming old
 *   results near the window — is a 400, while sending none is always valid.
 * - **The prompt cache** is asked for on every request (top-level `cache_control`), and what it
 *   saved comes back as `prompt_cache_hit_tokens`, which the pricing reads.
 *
 * Neither method throws; an Anthropic error body (`{"error": {"message": …}}`) reads as an
 * OpenAI one.
 */
final class Anthropic implements Transport
{
    /** The API version every request names. */
    public const VERSION = '2026-10-21';

    /**
     * @param array<string, mixed> $payload OpenAI-shaped
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function chat(Connection $connection, array $payload): array
    {
        try {
            $response = $this->request(Http::post($connection->baseUrl() . '/messages'), $connection)
                ->json(self::toMessages($payload))
                ->timeout($connection->timeout())
                ->send();
        } catch (\Throwable $why) {
            return [0, ['error' => ['message' => $why->getMessage()]]];
        }
        $body = self::decode($response->body());

        return [$response->status(), $response->status() === 200 && is_array($body) ? self::toCompletion($body) : $body];
    }

    /**
     * {@see chat()}, streamed: `$onText` is told each piece of the answer's text as it is written,
     * and the answer comes back whole, in the shape `chat()` returns.
     *
     * @param array<string, mixed>   $payload
     * @param callable(string): void $onText
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function chatStream(Connection $connection, array $payload, callable $onText): array
    {
        $buffer  = '';
        $events  = 0;
        $message = ['content' => [], 'stop_reason' => null, 'usage' => []];
        $partial = [];

        $read = static function (string $line) use (&$events, &$message, &$partial, $onText): void {
            $one = str_starts_with($line, 'data:') ? json_decode(trim(substr($line, 5)), true) : null;
            if (!is_array($one)) {
                return;
            }
            $events++;
            $at = (int) ($one['index'] ?? 0);
            switch ($one['type'] ?? '') {
                case 'message_start':
                    $message['usage'] = (array) ($one['message']['usage'] ?? []);
                    break;
                case 'content_block_start':
                    $message['content'][$at] = (array) ($one['content_block'] ?? []);
                    break;
                case 'content_block_delta':
                    $delta = (array) ($one['delta'] ?? []);
                    if (($delta['type'] ?? '') === 'text_delta' && (string) ($delta['text'] ?? '') !== '') {
                        $message['content'][$at]['text'] = ($message['content'][$at]['text'] ?? '') . $delta['text'];
                        $onText((string) $delta['text']);
                    } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                        // A call's arguments arrive a few characters at a time.
                        $partial[$at] = ($partial[$at] ?? '') . (string) ($delta['partial_json'] ?? '');
                    }
                    break;
                case 'message_delta':
                    $message['stop_reason'] = $one['delta']['stop_reason'] ?? $message['stop_reason'];
                    $message['usage']       = (array) ($one['usage'] ?? []) + $message['usage'];
                    break;
            }
        };

        try {
            $response = $this->request(Http::post($connection->baseUrl() . '/messages'), $connection)
                ->header('Accept', 'text/event-stream')
                ->json(['stream' => true] + self::toMessages($payload))
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
        $read(rtrim($buffer, "\r"));
        if ($response->status() !== 200 || $events === 0) {
            return [$response->status(), self::decode($response->body())];
        }
        foreach ($partial as $at => $json) {
            $input = json_decode($json === '' ? '{}' : $json, true);
            $message['content'][$at]['input'] = is_array($input) ? $input : [];
        }
        ksort($message['content']);
        $message['content'] = array_values($message['content']);

        return [200, self::toCompletion($message)];
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    public function get(Connection $connection, string $path): array
    {
        try {
            $response = $this->request(Http::get($connection->baseUrl() . $path), $connection)
                ->header('Accept', 'application/json')
                ->timeout($connection->timeout())
                ->send();

            return [$response->status(), self::decode($response->body())];
        } catch (\Throwable $why) {
            return [0, ['error' => ['message' => $why->getMessage()]]];
        }
    }

    /**
     * An OpenAI-shaped chat payload as a Messages API request.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function toMessages(array $payload): array
    {
        $system   = [];
        $messages = [];
        // The message being added to: consecutive turns of one role are one turn.
        $add = static function (string $role, array $blocks) use (&$messages): void {
            if ($blocks === []) {
                return;
            }
            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $role) {
                array_push($messages[$last]['content'], ...$blocks);

                return;
            }
            $messages[] = ['role' => $role, 'content' => $blocks];
        };

        foreach ((array) ($payload['messages'] ?? []) as $message) {
            $role = (string) ($message['role'] ?? '');
            if ($role === 'system') {
                $text = self::text($message['content'] ?? '');
                if ($text === '') {
                    continue;
                }
                // Before the conversation: the system prompt. Within it: text in the user's turn.
                $messages === [] ? $system[] = $text : $add('user', [['type' => 'text', 'text' => $text]]);
            } elseif ($role === 'assistant') {
                $blocks = [];
                if (($text = self::text($message['content'] ?? '')) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $text];
                }
                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $input    = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                    $blocks[] = ['type' => 'tool_use', 'id' => (string) ($call['id'] ?? ''), 'name' => (string) ($call['function']['name'] ?? ''),
                        'input' => is_array($input) && $input !== [] ? $input : new \stdClass()];
                }
                $add('assistant', $blocks);
            } elseif ($role === 'tool') {
                $add('user', [['type' => 'tool_result', 'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content' => self::text($message['content'] ?? '')]]);
            } else {
                $add('user', self::userBlocks($message['content'] ?? ''));
            }
        }
        // The conversation starts with the person, whatever the history held first.
        if ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_unshift($messages, ['role' => 'user', 'content' => [['type' => 'text', 'text' => '…']]]);
        }

        $request = [
            'model'         => (string) ($payload['model'] ?? ''),
            'max_tokens'    => (int) ($payload['max_tokens'] ?? $payload['max_completion_tokens'] ?? 16000),
            'messages'      => $messages,
            // The longest prefix that repeats is cached, and read at a fraction of the price.
            'cache_control' => ['type' => 'ephemeral'],
        ];
        if ($system !== []) {
            $request['system'] = implode("\n\n", $system);
        }
        $tools = [];
        foreach ((array) ($payload['tools'] ?? []) as $tool) {
            $function = (array) ($tool['function'] ?? []);
            $tools[]  = ['name' => (string) ($function['name'] ?? ''), 'description' => (string) ($function['description'] ?? ''),
                'input_schema' => (array) ($function['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()])];
        }
        if ($tools !== []) {
            $request['tools'] = $tools;
            // ponytail: a forced call goes as auto — Opus 5.5 and the newest refuse forced tool use; per-model if an older Claude needs it forced.
            $request['tool_choice'] = ['type' => ($payload['tool_choice'] ?? 'auto') === 'none' ? 'none' : 'auto'];
        }
        if (is_string($payload['reasoning_effort'] ?? null) && $payload['reasoning_effort'] !== '') {
            $request['output_config'] = ['effort' => $payload['reasoning_effort']];
        }

        return $request;
    }

    /**
     * A Messages API answer as an OpenAI chat completion.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public static function toCompletion(array $message): array
    {
        $text  = '';
        $calls = [];
        foreach ((array) ($message['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $input   = $block['input'] ?? [];
                $calls[] = ['id' => (string) ($block['id'] ?? ''), 'type' => 'function', 'function' => [
                    'name'      => (string) ($block['name'] ?? ''),
                    'arguments' => (string) json_encode(is_array($input) && $input !== [] ? $input : new \stdClass(), JSON_UNESCAPED_UNICODE),
                ]];
            }
        }
        $usage = (array) ($message['usage'] ?? []);
        $read  = (int) ($usage['cache_read_input_tokens'] ?? 0);
        // ponytail: a cache write is costed as an uncached input token — it costs 1.25×; a cache_write bucket if that matters.
        $miss  = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $out   = (int) ($usage['output_tokens'] ?? 0);

        return [
            'model'   => (string) ($message['model'] ?? ''),
            'choices' => [[
                'message'       => ['role' => 'assistant', 'content' => $text] + ($calls === [] ? [] : ['tool_calls' => $calls]),
                'finish_reason' => match ((string) ($message['stop_reason'] ?? '')) {
                    'max_tokens' => 'length',
                    'tool_use'   => 'tool_calls',
                    'refusal'    => 'content_filter',
                    default      => 'stop',
                },
            ]],
            'usage' => [
                'prompt_tokens'            => $read + $miss,
                'completion_tokens'        => $out,
                'total_tokens'             => $read + $miss + $out,
                'prompt_cache_hit_tokens'  => $read,
                'prompt_cache_miss_tokens' => $miss,
            ],
        ];
    }

    /** The request with Anthropic's headers: the key, and the API version. */
    private function request(\Pramnos\Http\Client $request, Connection $connection): \Pramnos\Http\Client
    {
        return $request->header('x-api-key', $connection->apiKey())->header('anthropic-version', self::VERSION);
    }

    /**
     * A user turn's content as blocks: its text, and its pictures — an OpenAI `image_url`, a
     * `data:` address as base64, any other as a URL.
     *
     * @return list<array<string, mixed>>
     */
    private static function userBlocks(mixed $content): array
    {
        if (!is_array($content)) {
            $text = trim((string) $content) === '' ? '' : (string) $content;

            return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        }
        $blocks = [];
        foreach ($content as $part) {
            $type = (string) ($part['type'] ?? '');
            if ($type === 'text' && (string) ($part['text'] ?? '') !== '') {
                $blocks[] = ['type' => 'text', 'text' => (string) $part['text']];
            } elseif ($type === 'image_url') {
                $url = (string) ($part['image_url']['url'] ?? $part['image_url'] ?? '');
                if (preg_match('~^data:(image/[a-z+.-]+);base64,(.+)$~is', $url, $m) === 1) {
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => strtolower($m[1]), 'data' => $m[2]]];
                } elseif ($url !== '') {
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]];
                }
            }
        }

        return $blocks;
    }

    /** A message's content as text: a string, or the text of its parts. */
    private static function text(mixed $content): string
    {
        if (!is_array($content)) {
            return (string) $content;
        }

        return implode("\n", array_map(static fn ($part): string => (string) ($part['text'] ?? ''),
            array_filter($content, static fn ($part): bool => is_array($part) && ($part['type'] ?? '') === 'text')));
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
