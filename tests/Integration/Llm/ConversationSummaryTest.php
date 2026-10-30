<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ConversationsTest.php';

/**
 * A long conversation folds its older part into a summary — on MySQL and PostgreSQL.
 *
 * What holds: past `turns()` and half as many again, the older messages are folded in one call,
 * never leaving an answer apart from its question; the summary rides with the system prompt and
 * the folded messages are not sent again; a failed summary marks nothing and sends the last
 * `turns()`, as with summaries off. The summary is asked of the cheapest priced model first.
 */
#[CoversClass(Conversations::class)]
class ConversationSummaryTest extends LlmTestCase
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
     * With two turns kept, the third question folds the first two exchanges — question and answer
     * together — into the summary; the fourth sends the summary and what came after; the fifth,
     * whose summary call fails, sends the last two messages and keeps the old summary.
     */
    #[DataProvider('databases')]
    public function testALongConversationIsFoldedIntoASummary(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['summarize' => true]]);
        $this->answer([
            [200, self::says('A1')],
            [200, self::says('A2')],
            [200, self::says('They asked Q1 and Q2.')],
            [200, self::says('A3')],
            [200, self::says('A4')],
            [400, ['error' => ['message' => 'no']]],
            [400, ['error' => ['message' => 'no']]],
            [200, self::says('A5')],
        ]);
        $chat = (new Conversations(new TwoTurnAssistant()))->as(new Scope(7, null));

        // Act
        $id = (int) $chat->ask(null, 'Q1')['conversation_id'];
        $chat->ask($id, 'Q2');
        $third    = $chat->ask($id, 'Q3');
        $folded   = $this->rows('chat_conversations')[0];
        $fourth   = $chat->ask($id, 'Q4');
        $fifth    = $chat->ask($id, 'Q5');
        $answerA2 = (int) $this->rows('chat_messages')[3]['message_id'];

        // Assert
        $this->assertTrue($third['ok'] && $fourth['ok'] && $fifth['ok']);
        $fold = (string) $this->sent[2]['body']['messages'][1]['content'];
        $this->assertStringContainsString("user: Q1\n\nassistant: A1\n\nuser: Q2\n\nassistant: A2", $fold, 'the answer went with its question');
        $this->assertSame('They asked Q1 and Q2.', $folded['summary']);
        $this->assertSame($answerA2, (int) $folded['summary_upto'], 'folded up to A2');
        $this->assertSame(['Q3'], self::said($this->sent[3]), 'nothing folded is sent again');
        $this->assertStringContainsString("Earlier in this conversation, in short (those messages are not shown again):\nThey asked Q1 and Q2.", self::system($this->sent[3]));
        $this->assertSame(['Q3', 'A3', 'Q4'], self::said($this->sent[4]), 'below the line: everything after the summary');
        $this->assertSame('gpt-6-luna', $this->sent[2]['body']['model'], 'folded on the cheapest priced model');
        $this->assertSame('gpt-4o-mini', $this->sent[3]['body']['model'], 'answered on the connection\'s own');
        $this->assertSame(['gpt-6-luna', 'gpt-4o-mini'], [$this->sent[5]['body']['model'], $this->sent[6]['body']['model']], 'a failure there is asked again on the own model');
        $this->assertSame(['Q5'], self::said($this->sent[7]), 'a failed summary: the last turns() only, never starting on an answer');
        $this->assertStringContainsString('They asked Q1 and Q2.', self::system($this->sent[7]), 'the old summary still goes');
        $this->assertSame($answerA2, (int) $this->rows('chat_conversations')[0]['summary_upto'], 'nothing more marked folded');
    }

    /**
     * A conversation of few but long messages is folded too: when the last answer left the window
     * more than {@see Conversations::FULL} full, all but the last exchange go into the summary,
     * however many turns() would allow.
     */
    #[DataProvider('databases')]
    public function testAFullWindowFoldsEvenAFewMessages(string $type, string $host, int $port): void
    {
        // Arrange — thirty turns allowed; the second answer filled 100k of a 128k window
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['summarize' => true]]);
        $this->answer([
            [200, self::says('A1')],
            [200, self::says('A2')],
            [200, self::says('They asked Q1.')],
            [200, self::says('A3')],
        ]);
        $chat = (new Conversations(new RecordingAssistant()))->as(new Scope(7, null));
        $id   = (int) $chat->ask(null, 'Q1')['conversation_id'];
        $chat->ask($id, 'Q2');
        $this->db->queryBuilder()->table('chat_messages')->where('conversation_id', $id)->where('content', 'A2')
            ->update(['context_tokens' => 100_000]);

        // Act
        $third = $chat->ask($id, 'Q3');

        // Assert
        $this->assertTrue($third['ok']);
        $this->assertStringContainsString("user: Q1\n\nassistant: A1", (string) $this->sent[2]['body']['messages'][1]['content']);
        $this->assertStringNotContainsString('Q2', (string) $this->sent[2]['body']['messages'][1]['content'], 'the last exchange is not folded');
        $this->assertSame(['Q2', 'A2', 'Q3'], self::said($this->sent[3]));
        $this->assertSame('They asked Q1.', $this->rows('chat_conversations')[0]['summary']);
    }

    /**
     * A conversation from before the watermarks counts as learned up to its last message, so the
     * first learning pass after upgrading does not read every old conversation again; nothing in it
     * is folded yet.
     */
    #[DataProvider('databases')]
    public function testTheMigrationMarksExistingConversationsLearned(string $type, string $host, int $port): void
    {
        // Arrange — a conversation of two messages, and the table as it was before
        $this->connectDatabase($type, $host, $port);
        $this->llmSchemaChanged();
        $migration = $this->migration(\Pramnos\Framework\Migrations\Llm\AddSummaryAndWatermarksToChatConversations::class);
        $migration->down();
        $this->answer([[200, self::says('A1')]]);
        $id   = (int) (new Conversations(new TwoTurnAssistant()))->as(new Scope(7, null))->ask(null, 'Q1')['conversation_id'];
        $last = max(array_map('intval', array_column($this->rows('chat_messages'), 'message_id')));

        // Act
        $migration->up();
        $migration->up();

        // Assert
        $row = $this->rows('chat_conversations')[0];
        $this->assertSame($id, (int) $row['conversation_id']);
        $this->assertSame($last, (int) $row['learned_upto'], 'learned up to its last message');
        $this->assertSame(0, (int) $row['summary_upto'], 'nothing folded');
        $this->assertNull($row['summary']);
    }

    /**
     * What a request sent, after its system prompt.
     *
     * @param array{body: array<string, mixed>} $request
     * @return list<string>
     */
    private static function said(array $request): array
    {
        return array_map(static fn (array $one): string => (string) $one['content'], self::spoken($request));
    }

    /** @param array{body: array<string, mixed>} $request */
    private static function system(array $request): string
    {
        return self::systemOf($request);
    }
}

/** An assistant that sends two turns back: a short conversation is already a long one. */
final class TwoTurnAssistant extends BaseAssistant
{
    public function turns(): int
    {
        return 2;
    }
}
