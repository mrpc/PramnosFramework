<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientException;
use Pramnos\Llm\Client;

/**
 * A streamed answer: `Client::onText()` is told the text while the model writes it, and the
 * answer returned is the same as an unstreamed one — its tool calls and usage put together from
 * the stream's pieces by `OpenAiCompatible::chatStream()`.
 */
final class StreamingTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_provider', 'deepseek');
    }

    /**
     * A server-sent event stream as a provider writes it: one `data:` line per piece, `[DONE]`
     * last.
     *
     * @param list<array<string, mixed>> $events
     */
    private static function stream(array $events): string
    {
        return implode('', array_map(static fn (array $e): string => 'data: ' . json_encode($e) . "\n\n", $events)) . "data: [DONE]\n\n";
    }

    /** One piece of the answer's text. */
    private static function piece(string $text, ?string $finish = null): array
    {
        return ['choices' => [['delta' => ['content' => $text], 'finish_reason' => $finish]]];
    }

    /**
     * The text arrives in pieces and the listener is told it whole so far, each time; the tool
     * call that came in pieces (id and name once, arguments a few characters at a time) is run
     * with its arguments put together; usage from the last event is counted; and the request
     * asked for a stream with its usage.
     *
     * This is the whole point of streaming: the person sees the answer being written instead of
     * a minute of "thinking", and nothing about the answer itself changes.
     */
    public function testAStreamedAnswerIsToldAsItIsWrittenAndComesBackWhole(): void
    {
        // Arrange — round one calls a tool in pieces; round two writes the answer in pieces
        $this->answer([
            [200, self::stream([
                ['choices' => [['delta' => ['role' => 'assistant', 'reasoning_content' => 'Let me ', 'tool_calls' => [['index' => 0, 'id' => 'call_7', 'function' => ['name' => 'parents', 'arguments' => '{"pil']]]]]]],
                ['choices' => [['delta' => ['reasoning_content' => 'count.', 'tool_calls' => [['index' => 0, 'function' => ['arguments' => 'lar":2}']]]], 'finish_reason' => 'tool_calls']]],
                ['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 4, 'total_tokens' => 24]],
            ])],
            [200, self::stream([
                self::piece(''),
                self::piece('212 parents'),
                self::piece(' search more', 'stop'),
                ['choices' => [], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 6, 'total_tokens' => 36]],
            ])],
        ]);
        $ran   = [];
        $tools = ['parents' => ['description' => '', 'parameters' => [], 'run' => static function (array $arguments) use (&$ran): string {
            $ran[] = $arguments;

            return '212';
        }]];
        $told = [];

        // Act
        $answer = (new Client(null, self::memoryLog()))
            ->onText(static function (string $text) use (&$told): void { $told[] = $text; })
            ->askWithTools('', [['role' => 'user', 'content' => 'How many?']], $tools, 4);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame('212 parents search more', $answer['reply']);
        $this->assertSame(['212 parents', '212 parents search more'], $told, 'the text so far, each time more arrives; an empty piece tells nothing');
        $this->assertSame([['pillar' => 2]], $ran, 'the arguments put together from their pieces');
        $this->assertSame(60, $answer['usage']['total_tokens'], 'usage from each round\'s last event');
        $this->assertTrue($this->sent[0]['body']['stream']);
        $this->assertSame(['include_usage' => true], $this->sent[0]['body']['stream_options']);
        // The model's own turn goes back as it came, its reasoning included (DeepSeek needs it).
        $turn = $this->sent[1]['body']['messages'][2];
        $this->assertSame(['Let me count.', 'call_7', 'function'], [$turn['reasoning_content'], $turn['tool_calls'][0]['id'], $turn['tool_calls'][0]['type']]);
    }

    /**
     * Nobody listening, nothing streamed: the request is the plain one it always was.
     *
     * A stream costs a connection held open and a parser; a caller that does not show the text
     * — a scheduled report, a summary — gains nothing from it.
     */
    public function testWithoutAListenerNothingIsStreamed(): void
    {
        // Arrange
        $this->answer([[200, self::says('Plain')]]);

        // Act
        $answer = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertSame('Plain', $answer['reply']);
        $this->assertFalse($this->sent[0]['body']['stream']);
        $this->assertArrayNotHasKey('stream_options', $this->sent[0]['body']);
    }

    /**
     * A provider that refuses the streamed request (400 or 422 — `stream_options` unknown to it)
     * is asked again the ordinary way; one that answers a stream with a plain body is read as
     * one; an error is the error, decoded; a network failure is status 0 with its reason.
     *
     * Streaming is for the screen. A provider that cannot do it must still answer, never fail
     * the question for it.
     */
    public function testAProviderThatCannotStreamStillAnswers(): void
    {
        // Arrange / Act — refused, then plain
        $this->answer([[422, ['error' => ['message' => 'Unknown field stream_options']]], [200, self::says('Asked again')]]);
        $refused = (new Client(null, self::memoryLog()))->onText(static function (): void {})->ask('', [['role' => 'user', 'content' => 'Hi']]);
        $refusedSent = $this->sent;
        $this->sent  = [];
        $this->answer([[200, self::says('A plain body')]]);
        $plain = (new Client(null, self::memoryLog()))->onText(static function (): void {})->ask('', [['role' => 'user', 'content' => 'Hi']]);
        $this->answer([[401, ['error' => ['message' => 'Bad key']]]]);
        $error = (new Client(null, self::memoryLog()))->onText(static function (): void {})->ask('', [['role' => 'user', 'content' => 'Hi']]);
        Http::fake(['*' => static function (): never { throw new ClientException('Connection refused'); }]);
        Client::$sleep = static function (): void {};
        $down = (new Client(null, self::memoryLog()))->onText(static function (): void {})->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertSame('Asked again', $refused['reply']);
        $this->assertFalse($refusedSent[1]['body']['stream'], 'the second time, not streamed');
        $this->assertSame('A plain body', $plain['reply']);
        $this->assertFalse($error['ok']);
        $this->assertStringContainsString('Bad key', $error['says']);
        $this->assertFalse($down['ok']);
        $this->assertStringContainsString('Nothing answered', $down['says'], 'status 0, said as the client says a network failure');
    }

    /**
     * An answer cut off at the length limit and asked to go on is told as one text: the
     * continuation goes on from what was already shown, not from nothing. The last event, with
     * no newline after it, is read too.
     *
     * Otherwise the screen would empty itself mid-answer and start again from the middle.
     */
    public function testAContinuedAnswerIsToldFromWhereItWas(): void
    {
        // Arrange
        $this->answer([
            [200, self::stream([self::piece('The first half', 'length')])],
            [200, 'data: ' . json_encode(self::piece(' and the rest.', 'stop'))],
        ]);
        $told = [];

        // Act
        $answer = (new Client(null, self::memoryLog()))
            ->onText(static function (string $text) use (&$told): void { $told[] = $text; })
            ->ask('', [['role' => 'user', 'content' => 'Tell me']]);

        // Assert
        $this->assertSame('The first half and the rest.', $answer['reply']);
        $this->assertSame(['The first half', 'The first half and the rest.'], $told);
    }
}
