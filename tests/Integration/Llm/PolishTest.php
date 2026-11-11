<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * An assistant's optional `polish(Turn, string)`, on MySQL and PostgreSQL: the answer corrected
 * in its text before it is stored.
 *
 * What has to hold: what is stored — and so what the history replays and the memory learns from —
 * is the polished text, with the framework's own marks still taken out after; and a polish that
 * throws or answers nothing never costs the answer.
 */
#[CoversClass(Conversations::class)]
class PolishTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * The polished answer is the one stored and the one the next question replays; the mark the
     * model left is still taken out, after the polish has seen it.
     */
    #[DataProvider('databases')]
    public function testThePolishedAnswerIsTheOneKept(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('[mood:calm] How is your day going? ⟦x⟧')], [200, self::says('Fine.')]]);
        $assistant = new PolishingAssistant();
        $chat      = (new Conversations($assistant))->as(new Scope(7, null));

        // Act
        $first = $chat->ask(null, 'Hi');
        $saw   = $assistant->saw;
        $chat->ask($first['conversation_id'], 'And?');
        $replayed = array_column($this->sent[1]['body']['messages'], 'content');
        $stored   = $this->db->queryBuilder()->table('assist.chat_messages')
            ->where('conversation_id', (int) $first['conversation_id'])->where('role', 'assistant')->orderBy('message_id')->first()->fields['content'];

        // Assert
        $this->assertSame('How did your day go? [mood:calm]', $first['message']['content']);
        $this->assertSame('How did your day go? [mood:calm]', $stored, 'stored corrected');
        $this->assertContains('How did your day go? [mood:calm]', $replayed, 'the history replays the correction');
        $this->assertStringContainsString('⟦x⟧', $saw, 'the polish saw the answer as the model wrote it');
    }

    /** A polish that throws, or answers nothing, leaves the answer as the model wrote it. */
    #[DataProvider('databases')]
    public function testAFailedPolishKeepsTheAnswer(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('As written.')], [200, self::says('As written too.')]]);

        // Act
        $thrown = (new Conversations(new PolishingAssistant('throw')))->as(new Scope(7, null))->ask(null, 'Hi');
        $empty  = (new Conversations(new PolishingAssistant('empty')))->as(new Scope(7, null))->ask(null, 'Hi');

        // Assert
        $this->assertSame('As written.', $thrown['message']['content']);
        $this->assertSame('As written too.', $empty['message']['content']);
    }
}

/** An assistant that moves a mood tag to the end and puts the day in the past. */
final class PolishingAssistant extends BaseAssistant
{
    /** The answer as polish() was given it. */
    public string $saw = '';

    /** @param string $how '' to polish, 'throw' or 'empty' to fail */
    public function __construct(private readonly string $how = '')
    {
    }

    /** Correct the answer in its text. */
    public function polish(Turn $turn, string $answer): string
    {
        $this->saw = $answer;
        if ($this->how === 'throw') {
            throw new \RuntimeException('broken');
        }
        if ($this->how === 'empty') {
            return '';
        }
        preg_match('/\[mood:[^\]]*\]/u', $answer, $mood);
        $text = trim((string) preg_replace('/\s*\[mood:[^\]]*\]\s*/u', ' ', $answer));

        return str_replace('How is your day going?', 'How did your day go?', $text) . (isset($mood[0]) ? ' ' . $mood[0] : '');
    }
}
