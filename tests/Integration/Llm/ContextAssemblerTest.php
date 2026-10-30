<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\ContextAssembler;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Llm\Client;
use Pramnos\Logs\Logger;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The context in three zones, on MySQL and PostgreSQL: the part that never changes first and
 * byte for byte the same from one question to the next, the time and the page last.
 *
 * What has to hold: two questions in a conversation share their system prompt exactly, which is
 * what lets a provider's cache keep it; what changes every question is just before the question;
 * and a static prompt that changes anyway, or a cache that stops being hit, is logged.
 */
#[CoversClass(ContextAssembler::class)]
class ContextAssemblerTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->log = fopen('php://memory', 'w+');
        Logger::setStreamTarget($this->log);
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
    }

    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        Logger::setOutputMode(Logger::OUTPUT_FILE);
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** What was logged so far. */
    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    /**
     * Zone 1 is the system prompt alone; zone 2 — the memory — comes ahead of the history; zone 3
     * — the time in the assistant's zone, its page, the notice for a document — just before the
     * question. Two questions in a row send the same system prompt, byte for byte.
     */
    #[DataProvider('databases')]
    public function testTheZonesAndAStablePrefix(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('Fine.')]]);
        $chat = (new Conversations(new PagedAssistant()))->as(new Scope(7, null));

        // Act
        $first  = $chat->ask(null, 'How is it?');
        $chat->ask($first['conversation_id'], 'And now?');
        $second = $this->sent[1]['body']['messages'];

        // Assert — zone 1, the same both times
        $this->assertSame($this->sent[0]['body']['messages'][0], $second[0]);
        $this->assertSame(['role' => 'system', 'content' => 'You are steady.'], $second[0]);
        // zone 2, ahead of the history
        $this->assertSame('system', $second[1]['role']);
        $this->assertStringContainsString('You remember nothing about this person yet.', $second[1]['content']);
        $this->assertSame(['How is it?', 'Fine.'], array_column(array_slice($second, 2, 2), 'content'));
        // zone 3, just before the question
        $tail = $second[4];
        $this->assertSame('system', $tail['role']);
        $this->assertMatchesRegularExpression('/^Now: \w+ \d+ \w+ \d{4}, \d\d:\d\d \(Asia\/Tokyo\)\.\n\nThe page: \/orders$/', $tail['content']);
        $this->assertSame(['role' => 'user', 'content' => 'And now?'], $second[5]);
    }

    /** An attached document's notice is in zone 3, with the question it came with. */
    public function testADocumentsNoticeIsInTheTail(): void
    {
        // Arrange
        $chat  = new Conversations(new BaseAssistant());
        $turn  = new Turn(new Scope(7, null), null, false, [], ['a.txt' => 'text'], [], $chat);
        $zones = new ContextAssembler(new BaseAssistant());

        // Act
        $messages = $zones->messages($turn, '', [['role' => 'user', 'content' => 'Read it']]);

        // Assert — no zone 2 when it is empty, and the notice beside the time
        $this->assertCount(2, $messages);
        $this->assertStringEndsWith(Client::DATA_NOTICE, $messages[0]['content']);
        $this->assertSame('Read it', $messages[1]['content']);
    }

    /**
     * A static prompt that changes between two questions of a conversation, while the code and the
     * settings did not, is logged with the first line that differs; a new conversation, or the
     * same prompt, is not.
     */
    public function testAStaticPromptThatChangesIsLogged(): void
    {
        // Arrange
        $zones = new ContextAssembler(new BaseAssistant());
        $id    = random_int(100000, 999999);

        // Act
        $zones->checkPrefix("You are steady.\nRule one.", $id);
        $zones->checkPrefix("You are steady.\nRule one.", $id);
        $quiet = $this->logged();
        $zones->checkPrefix("You are steady.\nRule one at 10:42.", $id);
        $zones->checkPrefix('Anything', null);

        // Assert
        $this->assertSame('', $quiet, 'the same prompt says nothing');
        $this->assertStringContainsString('Cache invalidation: the static prompt of conversation ' . $id, $this->logged());
        $this->assertStringContainsString('line 2 was "Rule one.", is "Rule one at 10:42."', $this->logged());
        $this->assertSame(hash('sha256', 'x'), $zones->checkPrefix('x', null));
    }

    /**
     * The share of an answer's prompt read from the cache: logged when a question that followed
     * others was mostly not; nothing when the provider does not say, or the prompt was too small
     * to cache, or it was the first question.
     */
    public function testTheCacheHitRatio(): void
    {
        // Arrange
        $deepseek = ['prompt_tokens' => 10000, 'prompt_cache_hit_tokens' => 2000, 'prompt_cache_miss_tokens' => 8000];
        $openai   = ['prompt_tokens' => 10000, 'prompt_tokens_details' => ['cached_tokens' => 9500]];

        // Act / Assert
        $this->assertSame(0.2, ContextAssembler::cacheReport($deepseek, false), 'the first question: nothing to compare');
        $this->assertSame('', $this->logged());
        $this->assertSame(0.95, ContextAssembler::cacheReport($openai, true));
        $this->assertSame('', $this->logged(), 'well over the line');
        $this->assertNull(ContextAssembler::cacheReport(['prompt_tokens' => 10000], true), 'the provider does not say');
        $this->assertNull(ContextAssembler::cacheReport(['prompt_tokens' => 500, 'prompt_cache_hit_tokens' => 0], true), 'too small to cache');
        ContextAssembler::cacheReport($deepseek, true, 42);
        $this->assertStringContainsString('Cache hit ratio 20% on 10000 prompt tokens in conversation 42', $this->logged());
    }
}

/** An assistant whose prompt never changes and whose page does. */
final class PagedAssistant extends BaseAssistant
{
    public function systemPrompt(Turn $turn): string
    {
        return 'You are steady.';
    }

    public function volatileContext(Turn $turn): string
    {
        return 'The page: /orders';
    }

    public function timezone(Scope $scope): string
    {
        return 'Asia/Tokyo';
    }
}
