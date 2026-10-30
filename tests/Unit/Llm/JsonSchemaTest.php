<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Client;
use Pramnos\Llm\JsonSchema;

/**
 * Arguments and structured answers checked against their JSON schema, and a call whose arguments
 * do not fit sent back to the model instead of run.
 *
 * What has to hold: strict where a call would go wrong — a missing required field, a value outside
 * its enum, a list where an object belongs — and lenient where a model is honestly ambiguous, a
 * number written as text; and a tool never runs on arguments it does not take.
 */
#[CoversClass(JsonSchema::class)]
#[CoversClass(Client::class)]
class JsonSchemaTest extends LlmTestCase
{
    private const SCHEMA = ['type' => 'object', 'required' => ['to', 'kind'], 'properties' => [
        'to'    => ['type' => 'string', 'minLength' => 3, 'maxLength' => 20],
        'kind'  => ['type' => 'string', 'enum' => ['mail', 'sms']],
        'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
        'price' => ['type' => 'number'],
        'tags'  => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 2],
        'meta'  => ['type' => 'object', 'properties' => ['urgent' => ['type' => 'boolean']]],
        'note'  => ['type' => ['string', 'null']],
    ]];

    /** Each way a value breaks a schema, with where; nothing for one that fits. */
    public function testWhatBreaksASchema(): void
    {
        // Act / Assert — fits, numbers written as text included
        $this->assertSame([], JsonSchema::errors(['to' => 'ann@x', 'kind' => 'mail', 'count' => '3', 'price' => '9.5', 'tags' => ['a'], 'meta' => ['urgent' => true], 'note' => null], self::SCHEMA));
        $this->assertSame([], JsonSchema::errors([], ['type' => 'object']), 'an empty object is an object');
        $this->assertSame([], JsonSchema::errors(3.0, ['type' => 'integer']), 'a whole float is an integer');

        // breaks
        $this->assertSame(['to: is required', 'kind: is required'], JsonSchema::errors([], self::SCHEMA));
        $this->assertSame(['must be an object'], JsonSchema::errors([1, 2], self::SCHEMA));
        $this->assertSame(['kind: must be one of "mail", "sms"'], JsonSchema::errors(['to' => 'ann', 'kind' => 'fax'], self::SCHEMA));
        $this->assertSame(['to: needs at least 3 characters'], JsonSchema::errors(['to' => 'a', 'kind' => 'sms'], self::SCHEMA));
        $this->assertSame(['to: takes at most 20 characters'], JsonSchema::errors(['to' => str_repeat('a', 21), 'kind' => 'sms'], self::SCHEMA));
        $this->assertSame(['count: must be at least 1'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'count' => 0], self::SCHEMA));
        $this->assertSame(['count: must be at most 10'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'count' => 11], self::SCHEMA));
        $this->assertSame(['count: must be an integer'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'count' => '1.5'], self::SCHEMA));
        $this->assertSame(['price: must be a number'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'price' => 'cheap'], self::SCHEMA));
        $this->assertSame(['tags: needs at least 1 items'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'tags' => []], self::SCHEMA));
        $this->assertSame(['tags: takes at most 2 items'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'tags' => ['a', 'b', 'c']], self::SCHEMA));
        $this->assertSame(['tags[1]: must be a string'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'tags' => ['a', 2]], self::SCHEMA));
        $this->assertSame(['meta.urgent: must be a boolean'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'meta' => ['urgent' => 'yes']], self::SCHEMA));
        $this->assertSame(['note: must be a string or null'], JsonSchema::errors(['to' => 'ann', 'kind' => 'sms', 'note' => 5], self::SCHEMA));
        $this->assertSame(['must be an array'], JsonSchema::errors(['a' => 1], ['type' => 'array']));
        $this->assertSame([], JsonSchema::errors('anything', ['type' => 'unknown-type']), 'a type it does not know is not checked');
    }

    /**
     * A call whose arguments do not fit its tool — a required one missing, or not JSON at all — is
     * not run: the model is told what is wrong in its place, and the corrected call runs.
     */
    public function testACallThatDoesNotFitIsSentBack(): void
    {
        // Arrange — a call without `to`, one that is not JSON, then the right one
        $this->setting('llm_openai_api_key', 'sk-test');
        $broken = ['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'send', 'arguments' => '{"kind":"sms"}']],
            ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'send', 'arguments' => '{to:']],
        ]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
        $this->answer([
            [200, $broken],
            [200, self::calls([['send', ['to' => 'ann', 'kind' => 'sms']]])],
            [200, self::says('Sent')],
        ]);
        $ran   = [];
        $tools = ['send' => ['description' => 'Send', 'parameters' => self::SCHEMA, 'run' => static function (array $a) use (&$ran): string {
            $ran[] = $a;

            return 'sent';
        }]];

        // Act
        $answer = (new Client(null, self::memoryLog()))->askWithTools('', [['role' => 'user', 'content' => 'Text Ann']], $tools, 4);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame([['to' => 'ann', 'kind' => 'sms']], $ran, 'only the call that fits ran');
        $results = array_values(array_filter($this->sent[1]['body']['messages'], static fn (array $m): bool => $m['role'] === 'tool'));
        $this->assertSame('Error: send was not run: to: is required. Call it again with the arguments corrected.', $results[0]['content']);
        $this->assertSame('Error: send was not run: its arguments were not a JSON object. Call it again with them as one.', $results[1]['content']);
    }

    /** A provider that refuses the request is said, on either way of asking for a shape. */
    public function testARefusedStructuredAnswerIsSaid(): void
    {
        // Arrange
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->answer([[400, ['error' => ['message' => 'bad request']]]]);
        $ask = static fn (): array => (new Client())->askForJson('Read', [['role' => 'user', 'content' => 'x']], ['type' => 'object']);

        // Act
        $native = $ask();
        $this->setting('llm_provider', 'deepseek');
        $tool = $ask();

        // Assert
        $this->assertSame([false, null], [$native['ok'], $native['data']]);
        $this->assertSame([false, null], [$tool['ok'], $tool['data']]);
        $this->assertStringContainsString('bad request', $tool['says']);
    }
}
