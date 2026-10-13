<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use Pramnos\Llm\Client;
use Pramnos\Llm\Connection;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Policy;

/**
 * The client and its tool loop, against a faked provider.
 *
 * Every test reads what was actually sent — the payload a provider would receive — because that
 * is where a wrong parameter name becomes an HTTP 400 in production, and what came back from
 * the client, because that is what an application shows.
 */
class ClientTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_openai_api_key', 'sk-openai');
        $this->setting('llm_provider', 'deepseek');
    }

    /**
     * A plain question: the reply, its usage and cost, one log entry, and a DeepSeek payload.
     *
     * DeepSeek takes `max_tokens` and switches reasoning off with `thinking`; both are named
     * per provider, and the wrong name is a 400.
     */
    public function testAQuestionIsAnsweredLoggedAndCosted(): void
    {
        // Arrange
        $this->answer([[200, self::says('Hello')]]);
        $log    = self::memoryLog();
        $client = (new Client(null, $log))->about(['purpose' => 'test', 'userid' => 7]);

        // Act
        $answer = $client->ask('Be brief.', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame('Hello', $answer['reply']);
        $this->assertSame(15, $answer['usage']['total_tokens']);
        $this->assertIsFloat($answer['cost'], 'deepseek-chat is priced');
        $this->assertSame('deepseek', $answer['provider']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $this->sent[0]['url']);
        $body = $this->sent[0]['body'];
        $this->assertSame('deepseek-chat', $body['model']);
        $this->assertSame(2000, $body['max_tokens']);
        $this->assertSame(['type' => 'disabled'], $body['thinking']);
        $this->assertSame(['role' => 'system', 'content' => 'Be brief.'], $body['messages'][0]);
        $this->assertCount(1, $log->entries);
        $this->assertSame('test', $log->entries[0]['purpose']);
        $this->assertSame(7, $log->entries[0]['userid']);
    }

    /**
     * Without a key nothing is sent, and the answer says where to put one.
     */
    public function testWithoutAKeyNothingIsSent(): void
    {
        // Arrange
        $this->setting('llm_deepseek_api_key', '');
        $this->answer([[200, self::says('never')]]);

        // Act
        $answer = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertFalse($answer['ok']);
        $this->assertStringContainsString('No API key is saved for DeepSeek', $answer['says']);
        $this->assertSame([], $this->sent);
    }

    /**
     * Nothing to ask is refused before anything is sent.
     */
    public function testNothingToAskIsRefused(): void
    {
        // Arrange
        $this->answer([[200, self::says('never')]]);

        // Act
        $answer = (new Client(null, self::memoryLog()))->ask('system', []);

        // Assert
        $this->assertSame('There is nothing to ask.', $answer['says']);
        $this->assertSame([], $this->sent);
    }

    /**
     * Consent and limits refuse before anything is sent, and say which refused.
     */
    public function testConsentAndLimitsRefuseBeforeSending(): void
    {
        // Arrange — a consent policy that refuses DeepSeek
        $this->answer([[200, self::says('never')]]);
        Llm::use('consent', new class implements Policy\Consent {
            public function refusal(array $context, string $provider): ?string
            {
                return $provider === 'deepseek' ? 'Nobody agreed to DeepSeek.' : null;
            }
        });

        // Act
        $consent = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Arrange — consent given, a limit reached
        Llm::reset();
        Llm::use('limits', new class implements Policy\Limits {
            public function refusal(array $context, Connection $connection): ?string
            {
                return 'Over this month\'s tokens.';
            }

            public function record(array $context, Connection $connection, array $usage, ?float $cost): void
            {
            }
        });
        $limited = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertTrue($consent['consent']);
        $this->assertSame('Nobody agreed to DeepSeek.', $consent['says']);
        $this->assertTrue($limited['limited']);
        $this->assertSame('Over this month\'s tokens.', $limited['says']);
        $this->assertSame([], $this->sent);
    }

    /**
     * Limits are told what each round spent, with the connection, and the listeners the entry.
     */
    public function testEachRoundIsMeteredAndAnnounced(): void
    {
        // Arrange
        $this->answer([[200, self::says('Hello')]]);
        $limits = new class implements Policy\Limits {
            /** @var list<array{0: array<string, mixed>, 1: ?float}> */
            public array $recorded = [];

            public function refusal(array $context, Connection $connection): ?string
            {
                return null;
            }

            public function record(array $context, Connection $connection, array $usage, ?float $cost): void
            {
                $this->recorded[] = [$usage, $cost];
            }
        };
        Llm::use('limits', $limits);
        $heard = [];
        Llm::listen(static function (array $entry) use (&$heard): void {
            $heard[] = $entry['purpose'] ?? null;
        });
        Llm::listen(static function (): void {
            throw new \RuntimeException('a listener that fails');
        });

        // Act
        $answer = (new Client(null, self::memoryLog()))->about(['purpose' => 'report'])
            ->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert — a failing listener did not end the answer
        $this->assertTrue($answer['ok']);
        $this->assertCount(1, $limits->recorded);
        $this->assertSame(15, $limits->recorded[0][0]['total_tokens']);
        $this->assertSame(['report'], $heard);
    }

    /**
     * A refused call says why, in words, and is logged with the reason.
     */
    public function testARefusedCallSaysWhy(): void
    {
        // Arrange
        $cases = [
            [401, ['error' => ['message' => 'bad key']], 'DeepSeek refused the key (HTTP 401). It said: bad key'],
            [402, [], 'DeepSeek says the account is out of credit.'],
            [429, ['message' => 'slow down'], 'DeepSeek is rate limiting us. Try again shortly. It said: slow down'],
            [500, [['error' => ['message' => 'boom']]], 'DeepSeek answered HTTP 500: boom'],
            [503, 'not json', 'DeepSeek answered HTTP 503.'],
        ];

        foreach ($cases as [$status, $body, $expected]) {
            $this->answer([[$status, $body]]);
            $log = self::memoryLog();

            // Act
            $answer = (new Client(null, $log))->ask('', [['role' => 'user', 'content' => 'Hi']]);

            // Assert
            $this->assertSame($expected, $answer['says']);
            $this->assertSame($expected, $log->entries[0]['error'], 'the log carries the same sentence');
            $this->assertSame($status, $log->entries[0]['http_status']);
        }
    }

    /**
     * Nothing answering is said as such, with the address that did not answer.
     */
    public function testNothingAnsweringNamesTheAddress(): void
    {
        // Arrange — a transport that never connects
        $this->configure(['providers' => ['deepseek' => ['transport' => DeadTransport::class]]]);

        // Act
        $answer = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertSame('Nothing answered at https://api.deepseek.com. Check the address and that this server can reach it.', $answer['says']);
    }

    /**
     * A 200 with no words is a failure, with the reason a reasoning model usually has.
     */
    public function testAnEmptyReplyIsAFailure(): void
    {
        // Arrange
        $this->answer([[200, self::says('', finish: 'length')]]);

        // Act
        $length = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'Hi']]);
        $this->answer([[200, self::says('')]]);
        $nothing = (new Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertFalse($length['ok']);
        $this->assertStringContainsString('ran out of tokens', $length['says']);
        $this->assertSame(15, $length['usage']['total_tokens'], 'what it spent is still reported');
        $this->assertSame('The model answered with nothing.', $nothing['says']);
    }

    /**
     * Tools are offered, run, their results returned, and the last round forbids tools.
     */
    public function testToolsAreRunAndTheLastRoundForbidsThem(): void
    {
        // Arrange — the model calls `weather`, a tool that throws, and one that does not exist
        $this->answer([
            [200, self::calls([['weather', ['city' => 'Athens']], ['broken', []], ['missing', []]], ['reasoning_content' => 'thinking'])],
            [200, self::says('Sunny')],
        ]);
        $tools = [
            'weather' => ['description' => 'The weather', 'parameters' => ['properties' => ['city' => ['type' => 'string']]],
                'run' => static fn (array $args): array => ['city' => $args['city'], 'sky' => 'clear']],
            'broken'  => ['description' => 'Fails', 'parameters' => [],
                'run' => static fn (): string => throw new \RuntimeException('no service')],
        ];
        $log = self::memoryLog();

        // Act
        $answer = (new Client(null, $log))->askWithTools('', [['role' => 'user', 'content' => 'Weather?']], $tools, 2);

        // Assert — the answer, and every call in order
        $this->assertTrue($answer['ok']);
        $this->assertSame('Sunny', $answer['reply']);
        $this->assertSame(['weather', 'broken', 'missing'], array_column($answer['calls'], 'name'));
        $this->assertSame(39, $answer['usage']['total_tokens'], 'both rounds summed');
        $this->assertSame(10, $answer['context'], 'the last round\'s prompt only');

        // The first request offered the tools, with an empty schema sent as an object
        $first = $this->sent[0]['body'];
        $this->assertSame('auto', $first['tool_choice']);
        $this->assertSame(['weather', 'broken'], array_column(array_column($first['tools'], 'function'), 'name'));
        $this->assertStringContainsString('"name":"broken","description":"Fails","parameters":{"properties":{}', $this->sent[0]['raw']);

        // The second carried the turn verbatim, each result, and forbade tools
        $second   = $this->sent[1]['body'];
        $messages = $second['messages'];
        $this->assertSame('none', $second['tool_choice']);
        $this->assertSame('thinking', $messages[2]['reasoning_content'], 'DeepSeek needs its reasoning back');
        $this->assertSame('{"city":"Athens","sky":"clear"}', $messages[3]['content']);
        $this->assertSame('Error: no service', $messages[4]['content']);
        $this->assertSame('Error: there is no tool called missing.', $messages[5]['content']);
        $this->assertSame(Client::LAST_ROUND, end($messages)['content']);
        $this->assertSame('tool calls: weather, broken, missing', $log->entries[0]['reply']);
    }

    /**
     * A model that keeps asking for tools past the last round gets a refusal, not a loop.
     */
    public function testAModelThatNeverAnswersIsStopped(): void
    {
        // Arrange
        $this->answer([[200, self::calls([['weather', []]])]]);
        $tools = ['weather' => ['description' => '', 'parameters' => [], 'run' => static fn (): string => 'clear']];

        // Act
        $answer = (new Client(null, self::memoryLog()))->askWithTools('', [['role' => 'user', 'content' => 'x']], $tools, 2);

        // Assert
        $this->assertFalse($answer['ok']);
        $this->assertSame('The model kept asking for tools and never answered.', $answer['says']);
        $this->assertCount(2, $answer['calls']);
    }

    /**
     * A due tool is forced on the round before the last, and said so.
     */
    public function testADueToolIsForced(): void
    {
        // Arrange — two rounds of research, then the answer
        $this->answer([
            [200, self::calls([['search', []]])],
            [200, self::calls([['save', []]])],
            [200, self::says('Saved')],
        ]);
        $tools = [
            'search' => ['description' => '', 'parameters' => [], 'run' => static fn (): string => 'found'],
            'save'   => ['description' => '', 'parameters' => [], 'run' => static fn (): string => 'saved'],
        ];
        $due = ['save' => static fn (array $calls): bool => !in_array('save', array_column($calls, 'name'), true)];

        // Act
        $answer = (new Client(null, self::memoryLog()))->askWithTools('', [['role' => 'user', 'content' => 'x']], $tools, 3, $due);

        // Assert — round 2 of 3 forced `save`
        $this->assertTrue($answer['ok']);
        $this->assertSame(['type' => 'function', 'function' => ['name' => 'save']], $this->sent[1]['body']['tool_choice']);
        $this->assertSame(sprintf(Client::DUE, 'save'), end($this->sent[1]['body']['messages'])['content']);
    }

    /**
     * An answer the verifier rejects is sent back once, with a required call; a provider that
     * refuses `required` is asked again with `auto`.
     */
    public function testARejectedAnswerIsCorrectedOnce(): void
    {
        // Arrange — "done", rejected; a 400 for `required`; the call; the honest answer
        $this->answer([
            [200, self::says('I saved it.')],
            [400, ['error' => ['message' => 'required is not supported']]],
            [200, self::calls([['save', []]])],
            [200, self::says('Saved, really.')],
        ]);
        $tools  = ['save' => ['description' => '', 'parameters' => [], 'run' => static fn (): string => 'saved']];
        $verify = static fn (string $reply, array $calls): ?string => $calls === [] ? 'Nothing was saved: call save.' : null;

        // Act
        $answer = (new Client(null, self::memoryLog()))->askWithTools('', [['role' => 'user', 'content' => 'x']], $tools, 1, [], $verify);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame('Saved, really.', $answer['reply']);
        $this->assertSame('required', $this->sent[1]['body']['tool_choice']);
        $this->assertSame('auto', $this->sent[2]['body']['tool_choice'], 'asked again, free to call or not');
        $this->assertSame('Nothing was saved: call save.', end($this->sent[1]['body']['messages'])['content']);
    }

    /**
     * A tagged result moves the answer to a connection that may take it, before it is sent.
     *
     * The moved request goes to the other provider, without the first one's own fields, with
     * the budget the answer started on; the answer reports the tag and the provider.
     */
    public function testATaggedResultMovesTheAnswer(): void
    {
        // Arrange
        $this->setting('llm_deepseek_max_tokens', '6000');
        $this->answer([
            [200, self::calls([['analytics', []]], ['reasoning_content' => 'hmm'])],
            [200, self::says('Traffic is up')],
        ]);
        Llm::use('rules', [new OpenAiOnly(new Connection('openai'))]);
        $tools = ['analytics' => ['description' => '', 'parameters' => [], 'run' => static fn (): string => '1,234 visits', 'tags' => ['google' => true]]];

        // Act
        $client = new Client(null, self::memoryLog());
        $answer = $client->askWithTools('', [['role' => 'user', 'content' => 'x']], $tools, 3);

        // Assert
        $this->assertSame('https://api.openai.com/v1/chat/completions', $this->sent[1]['url']);
        $moved = $this->sent[1]['body'];
        $this->assertSame('gpt-4o-mini', $moved['model']);
        $this->assertSame(6000, $moved['max_completion_tokens'], 'the budget it started on');
        $this->assertArrayNotHasKey('reasoning_content', $moved['messages'][2]);
        $this->assertSame('1,234 visits', $moved['messages'][3]['content']);
        $this->assertSame(['google'], $answer['tags']);
        $this->assertSame(['google'], $client->tags());
        $this->assertSame('openai', $answer['provider']);
    }

    /**
     * With nowhere to go, the model is told the rule's refusal in place of the result.
     */
    public function testATaggedResultWithNowhereToGoIsWithheld(): void
    {
        // Arrange — a tag decided by the result itself
        $this->answer([
            [200, self::calls([['mixed', ['id' => 3]]])],
            [200, self::says('I cannot see that')],
        ]);
        Llm::use('rules', [new OpenAiOnly(null)]);
        $tools = ['mixed' => ['description' => '', 'parameters' => [], 'run' => static fn (): string => 'secret figures',
            'tags' => ['google' => static fn (string $result, array $args): bool => ($args['id'] ?? 0) > 0]]];

        // Act
        $answer = (new Client(null, self::memoryLog()))->askWithTools('', [['role' => 'user', 'content' => 'x']], $tools, 3);

        // Assert
        $this->assertSame(OpenAiOnly::REFUSAL, $this->sent[1]['body']['messages'][3]['content']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $this->sent[1]['url'], 'it stayed');
        $this->assertSame([], $answer['tags'], 'the data never reached the model');
    }

    /**
     * moveFor() moves a conversation before anything is asked, or says it cannot.
     */
    public function testMoveForMovesBeforeAsking(): void
    {
        // Arrange
        Llm::use('rules', [new OpenAiOnly(new Connection('openai'))]);
        $client = new Client(null, self::memoryLog());

        // Act / Assert — moved, and then already where it may be
        $this->assertTrue($client->moveFor('google'));
        $this->assertSame('openai', $client->connection()->provider());
        $this->assertTrue($client->moveFor('google'));
        $this->assertTrue($client->moveFor('unknown-tag'), 'a tag with no rule needs no move');

        Llm::use('rules', [new OpenAiOnly(null)]);
        $this->assertFalse((new Client(null, self::memoryLog()))->moveFor('google'));
    }

    /**
     * The router completes the context and may hand back another connection.
     */
    public function testTheRouterPicksTheConnection(): void
    {
        // Arrange — an organisation's own OpenAI key
        $this->answer([[200, self::says('Hi')]]);
        Llm::use('router', new class extends Policy\DefaultRouter {
            public function context(array $context): array
            {
                return parent::context($context) + ['organization_id' => 42];
            }

            public function route(array $context, Connection $connection): Connection
            {
                return (new Connection('openai'))->with(['api_key' => 'sk-theirs', 'own' => true]);
            }
        });
        $log = self::memoryLog();

        // Act
        $answer = (new Client(null, $log))->ask('', [['role' => 'user', 'content' => 'Hi']]);

        // Assert
        $this->assertSame('openai', $answer['provider']);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $this->sent[0]['url']);
        $this->assertSame(42, $log->entries[0]['organization_id']);
    }

    /**
     * Each provider gets its own parameters: OpenAI's effort with tools, Mistral's cache key,
     * a reasoning model with no temperature.
     */
    public function testEachProviderGetsItsOwnParameters(): void
    {
        // Arrange
        $this->setting('llm_mistral_api_key', 'sk-mistral');
        $this->setting('llm_openai_model', 'gpt-5');
        $this->answer([[200, self::says('ok')]]);
        $tools = ['t' => ['description' => '', 'parameters' => [], 'run' => static fn (): string => '']];

        // Act
        (new Client(new Connection('openai'), self::memoryLog()))->askWithTools('S', [['role' => 'user', 'content' => 'x']], $tools);
        (new Client(new Connection('openai'), self::memoryLog()))->ask('S', [['role' => 'user', 'content' => 'x']]);
        (new Client(new Connection('mistral'), self::memoryLog()))->ask('S', [['role' => 'user', 'content' => 'x']]);

        // Assert
        $this->assertSame('none', $this->sent[0]['body']['reasoning_effort'], 'with tools');
        $this->assertArrayNotHasKey('temperature', $this->sent[0]['body'], 'a reasoning model refuses one');
        $this->assertSame('none', $this->sent[1]['body']['reasoning_effort'], 'reasoning off');
        $this->assertSame(32, strlen($this->sent[2]['body']['prompt_cache_key']));
        $this->assertSame(0.7, $this->sent[2]['body']['temperature']);
    }
}

/** A rule that lets `google` data go to OpenAI only. */
final class OpenAiOnly implements Policy\DataRule
{
    public const REFUSAL = 'Not read: nowhere may take this.';

    public function __construct(private readonly ?Connection $alternative)
    {
    }

    public function tag(): string
    {
        return 'google';
    }

    public function accepts(Connection $connection): bool
    {
        return $connection->provider() === 'openai';
    }

    public function alternative(array $context, Connection $current): ?Connection
    {
        return $this->alternative;
    }

    public function refusalResult(): string
    {
        return self::REFUSAL;
    }
}

/** A transport that never reaches anything. */
final class DeadTransport implements \Pramnos\Llm\Transport\Transport
{
    public function chat(Connection $connection, array $payload): array
    {
        return [0, ['error' => ['message' => 'connection refused']]];
    }

    public function get(Connection $connection, string $path): array
    {
        return [0, null];
    }
}
