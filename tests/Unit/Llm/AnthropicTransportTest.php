<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Client;
use Pramnos\Llm\Connection;
use Pramnos\Llm\Pricing;
use Pramnos\Llm\Providers;
use Pramnos\Llm\Transport\Anthropic;

/**
 * Claude, through Anthropic's Messages API: the `anthropic` provider and its transport, which
 * translates the client's OpenAI-shaped requests and answers both ways so the tool loop, the
 * budgets and the costing work unchanged.
 *
 * What holds: a tool loop runs end to end against `/v1/messages` with the key and the API version
 * in the headers; the request carries the system prompt apart, the tools as input schemas, no
 * temperature, the effort, and a cache request; a mid-conversation system note becomes text in the
 * user's turn, a forced call becomes `auto`, consecutive tool results one user turn, and pictures
 * image blocks; a streamed answer is told as it is written and comes back whole, its tool call put
 * together from its pieces; an error and a refusal read as the client expects; the usage is costed
 * with what the cache saved; and the model list and the larger default budget come from the
 * provider's entry.
 */
#[CoversClass(Anthropic::class)]
#[CoversClass(Providers::class)]
#[CoversClass(Connection::class)]
#[CoversClass(Pricing::class)]
final class AnthropicTransportTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_anthropic_api_key', 'sk-ant-test');
        $this->setting('llm_provider', 'anthropic');
    }

    /**
     * A Messages API answer.
     *
     * @param list<array<string, mixed>> $content
     * @param array<string, int>         $usage
     * @return array<string, mixed>
     */
    private static function message(array $content, string $stop = 'end_turn', array $usage = ['input_tokens' => 100, 'output_tokens' => 20]): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stop, 'usage' => $usage];
    }

    /**
     * A tool loop end to end: Claude asks for a tool, the tool runs with its arguments, its result
     * goes back as a `tool_result` in a user turn, and the answer that follows is the reply. Each
     * request goes to `/v1/messages` with the key as `x-api-key` and the API version, carries the
     * system prompt apart, the tools as input schemas, the effort for a call with tools, a cache
     * request and no temperature — which the current models refuse.
     */
    public function testAToolLoopThroughTheMessagesApi(): void
    {
        // Arrange
        $this->answer([
            [200, self::message([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => 'Let me count.'],
                ['type' => 'tool_use', 'id' => 'toolu_7', 'name' => 'parents', 'input' => ['pillar' => 2]]], 'tool_use')],
            [200, self::message([['type' => 'text', 'text' => '212 parents.']], 'end_turn',
                ['input_tokens' => 10, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 50, 'output_tokens' => 30])],
        ]);
        $ran   = [];
        $tools = ['parents' => ['description' => 'Count parents', 'parameters' => ['pillar' => ['type' => 'integer']],
            'run' => static function (array $arguments) use (&$ran): string {
                $ran[] = $arguments;

                return '212';
            }]];

        // Act
        $answer = (new Client(null, self::memoryLog()))->askWithTools('You count.', [['role' => 'user', 'content' => 'How many?']], $tools, 4);
        [$first, $second] = [$this->sent[0], $this->sent[1]];

        // Assert — the answer, and the tool run with Claude's arguments
        $this->assertTrue($answer['ok'], (string) ($answer['says'] ?? ''));
        $this->assertStringEndsWith('212 parents.', $answer['reply']);
        $this->assertSame([['pillar' => 2]], $ran);
        // The request, as the Messages API takes it
        $this->assertSame('https://api.anthropic.com/v1/messages', $first['url']);
        $this->assertSame(['sk-ant-test', '2026-10-21'], [$first['headers']['x-api-key'] ?? null, $first['headers']['anthropic-version'] ?? null]);
        $this->assertSame('claude-opus-5-5', $first['body']['model']);
        $this->assertStringStartsWith('You count.', (string) $first['body']['system']);
        $this->assertSame(16000, $first['body']['max_tokens'], 'the provider\'s larger default: thinking spends from it');
        $this->assertSame(['type' => 'ephemeral'], $first['body']['cache_control']);
        $this->assertSame('low', $first['body']['output_config']['effort'], 'reasoning off: the least thinking');
        $this->assertArrayNotHasKey('temperature', $first['body']);
        $this->assertSame('parents', $first['body']['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $first['body']['tools'][0]);
        $this->assertSame(['type' => 'auto'], $first['body']['tool_choice']);
        // The second round: the call and its result, as blocks; the thinking not sent back
        $roles = array_column($second['body']['messages'], 'role');
        $this->assertSame(['user', 'assistant', 'user'], array_slice($roles, 0, 3));
        $call  = $second['body']['messages'][1]['content'];
        $this->assertSame(['text', 'tool_use'], array_column($call, 'type'), 'no thinking replayed: always valid, whatever the history');
        $this->assertSame(['pillar' => 2], $call[1]['input']);
        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'toolu_7', 'content' => '212'], $second['body']['messages'][2]['content'][0]);
        // What the cache saved is costed as such: 1000 read, 60 at the input price, 50 out (20 + 30)
        $this->assertSame(1000, $answer['usage']['prompt_cache_hit_tokens']);
        $this->assertSame(160, $answer['usage']['prompt_cache_miss_tokens'], '100 + 10 + 50 written to the cache');
        $this->assertEqualsWithDelta((1000 * 0.20 + 160 * 4.00 + 50 * 20.00) / 1e6, $answer['cost'], 1e-9);
    }

    /**
     * The client's conversation as the Messages API takes it: the system prompt apart; a later
     * system note as text in the user's turn — after an assistant's turn, a user turn of its own;
     * consecutive tool results one turn; a picture as an image block; a forced call as `auto`;
     * a conversation that starts with the assistant given a user turn first; and nothing the API
     * refuses (temperature, response_format) carried over.
     */
    public function testTheConversationIsTranslated(): void
    {
        // Act
        $request = Anthropic::toMessages([
            'model' => 'claude-sonnet-5-5', 'max_tokens' => 900, 'temperature' => 0.7, 'reasoning_effort' => 'high',
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'a']],
            'tools' => [['type' => 'function', 'function' => ['name' => 'a', 'description' => 'A', 'parameters' => ['type' => 'object', 'properties' => []]]]],
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'assistant', 'content' => 'Earlier.'],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Look'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://x.test/a.jpg']]]],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                    ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'a', 'arguments' => '{}']],
                    ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'a', 'arguments' => '{"x":1}']]]],
                ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'one'],
                ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => 'two'],
                ['role' => 'system', 'content' => 'Last round: answer now.'],
                ['role' => 'assistant', 'content' => 'Done.'],
                ['role' => 'system', 'content' => 'That was wrong.'],
            ],
        ]);
        $m = $request['messages'];

        // Assert
        $this->assertSame('Be brief.', $request['system']);
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($m, 'role'),
            'a user turn first; one turn per role in a row');
        $this->assertSame(['text', 'image', 'image'], array_column($m[2]['content'], 'type'));
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => 'AAAA'], $m[2]['content'][1]['source']);
        $this->assertSame(['type' => 'url', 'url' => 'https://x.test/a.jpg'], $m[2]['content'][2]['source']);
        $this->assertEquals(new \stdClass(), $m[3]['content'][0]['input'], 'no arguments: an empty object, not a list');
        // Both results in one user turn, the system note after them as text
        $this->assertSame(['tool_result', 'tool_result', 'text'], array_column($m[4]['content'], 'type'));
        $this->assertSame('Last round: answer now.', $m[4]['content'][2]['text']);
        $this->assertSame([['type' => 'text', 'text' => 'That was wrong.']], $m[6]['content'], 'after the assistant: a user turn of its own');
        $this->assertSame(['type' => 'auto'], $request['tool_choice'], 'a forced call as auto: the newest models refuse forced tool use');
        $this->assertSame(['effort' => 'high'], $request['output_config']);
        $this->assertSame(900, $request['max_tokens']);
        $this->assertArrayNotHasKey('temperature', $request);
        $this->assertSame(['type' => 'none'], Anthropic::toMessages(['tool_choice' => 'none', 'tools' => [['function' => ['name' => 'a']]],
            'messages' => [['role' => 'user', 'content' => 'x']]])['tool_choice'], 'no tools wanted: none');
    }

    /**
     * Streamed: the text is told as it is written, the call's arguments that came a few characters
     * at a time are put together, the usage from the start and the end is counted, and the
     * request asked for a stream.
     */
    public function testAStreamedAnswerIsToldAndComesBackWhole(): void
    {
        // Arrange — round one calls a tool in pieces; round two writes the answer in pieces
        $sse = static fn (array $events): string => implode('', array_map(
            static fn (array $e): string => 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n", $events));
        $this->answer([
            [200, $sse([
                ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 40, 'output_tokens' => 1]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'parents', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"pil']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'lar":3}']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 12]],
                ['type' => 'message_stop'],
            ])],
            [200, $sse([
                ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 60, 'output_tokens' => 1]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '7 ']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'parents']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
            ])],
        ]);
        $ran   = [];
        $tools = ['parents' => ['description' => '', 'parameters' => [], 'run' => static function (array $arguments) use (&$ran): string {
            $ran[] = $arguments;

            return '7';
        }]];
        $told = [];

        // Act
        $answer = (new Client(null, self::memoryLog()))
            ->onText(static function (string $text) use (&$told): void { $told[] = $text; })
            ->askWithTools('', [['role' => 'user', 'content' => 'How many?']], $tools, 4);

        // Assert
        $this->assertSame('7 parents', $answer['reply']);
        $this->assertSame([['pillar' => 3]], $ran, 'the arguments put together from their pieces');
        $this->assertNotSame([], $told, 'told while written');
        $this->assertSame('7 parents', end($told));
        $this->assertTrue($this->sent[0]['body']['stream']);
        $this->assertSame(100, $answer['usage']['prompt_tokens'], '40 + 60 in');
        $this->assertSame(17, $answer['usage']['completion_tokens'], '12 + 5 out');
    }

    /**
     * Anthropic's error body reads as the client's error; a refusal and a cut-off answer finish as
     * the client knows them; a network failure is status 0 with its reason, streamed or not, and
     * a stream refused before it starts returns the status and error for the client's retry.
     */
    public function testErrorsRefusalsAndCutOffs(): void
    {
        // Arrange
        $this->answer([[400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'messages.0: bad']]]]);

        // Act
        $refused  = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'x']]);
        $refusal  = Anthropic::toCompletion(self::message([], 'refusal'))['choices'][0]['finish_reason'];
        $cut      = Anthropic::toCompletion(self::message([['type' => 'text', 'text' => 'Half']], 'max_tokens'))['choices'][0]['finish_reason'];
        \Pramnos\Http\Client::fake(['*' => static function (): never {
            throw new \RuntimeException('connection refused');
        }]);
        $payload    = ['model' => 'claude-opus-5-5', 'messages' => [['role' => 'user', 'content' => 'x']]];
        $down       = (new Anthropic())->chat(new Connection('anthropic'), $payload);
        $streamDown = (new Anthropic())->chatStream(new Connection('anthropic'), $payload, static function (): void {});
        $listDown   = (new Anthropic())->get(new Connection('anthropic'), '/models');
        $this->answer([[429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']]]]);
        $streamBusy = (new Anthropic())->chatStream(new Connection('anthropic'), $payload, static function (): void {});

        // Assert
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('messages.0: bad', (string) $refused['says']);
        $this->assertSame(['content_filter', 'length'], [$refusal, $cut]);
        $this->assertSame([0, ['error' => ['message' => 'connection refused']]], $down);
        $this->assertSame([$down, $down], [$streamDown, $listDown], 'never thrown: status 0 with the reason');
        // A stream refused before it starts: the status and the error body, for the client's retry
        $this->assertSame(429, $streamBusy[0]);
        $this->assertSame('slow down', $streamBusy[1]['error']['message']);
    }

    /**
     * The provider's entry: Claude's models from `/v1/models` with the same headers, the larger
     * default budget for Claude alone, and the seeded prices.
     */
    public function testTheModelListTheBudgetAndThePrices(): void
    {
        // Arrange
        $this->answer([[200, ['data' => [['id' => 'claude-opus-5-5', 'type' => 'model'], ['id' => 'claude-haiku-5-5', 'type' => 'model']]]]]);

        // Act
        $models = (new Connection('anthropic'))->models(true);
        $prices = new Pricing();

        // Assert
        $this->assertSame('https://api.anthropic.com/v1/models', $this->sent[0]['url']);
        $this->assertSame('2026-10-21', $this->sent[0]['headers']['anthropic-version'] ?? null);
        $this->assertSame(['claude-haiku-5-5', 'claude-opus-5-5'], array_keys($models));
        $this->assertSame(16000, (new Connection('anthropic'))->maxTokens());
        $this->assertSame(2000, (new Connection('openai'))->maxTokens(), 'another provider keeps its default');
        $this->assertSame(['cache_hit' => 0.20, 'cache_miss' => 4.00, 'output' => 20.00], $prices->forModel('claude-opus-5-5'));
        $this->assertSame('Anthropic (Claude)', Providers::config('anthropic')['label']);
    }
}
