<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Console\Commands\LlmAsk;
use Pramnos\Llm\Analyst\ConsoleAsk;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Mcp\Tools\AnalystAskTool;
use Pramnos\Tests\Unit\Llm\LlmTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The analyst asked from the console (FW-396) — `llm:ask` and the `ask` tool of `mcp:call` — on
 * MySQL and PostgreSQL.
 *
 * What holds: the answer comes back with the tools the analyst called and its conversation, which
 * is kept in the administrator's analyst space and can be continued; it is marked as asked from
 * the console and nothing in it is learned about the person; nobody, an account that is none, an
 * empty question and an unconnected AI are refused; and the tool asks only as root or a super
 * administrator, since every call is a paid answer.
 */
#[CoversClass(ConsoleAsk::class)]
#[CoversClass(LlmAsk::class)]
#[CoversClass(AnalystAskTool::class)]
#[CoversClass(Conversations::class)]
class ConsoleAskTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> The accounts this test made */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        if ($this->accounts !== []) {
            $this->db->queryBuilder()->table('users')->whereIn('userid', $this->accounts)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** An account of this usertype. */
    private function account(int $id, int $usertype): void
    {
        $this->accounts[] = $id;
        $this->db->queryBuilder()->table('users')->where('userid', $id)->delete();
        $this->db->queryBuilder()->table('users')->insert(['userid' => $id, 'username' => 'u' . $id, 'email' => 'u' . $id . '@example.com',
            'lastname' => 'U', 'password' => 'x', 'usertype' => $usertype, 'active' => 1]);
    }

    /**
     * `llm:ask` answers as the administrator: the answer, the tool it called with its arguments,
     * the conversation and where it opens — then continues it with `--conversation`, as JSON. The
     * conversation is the administrator's analyst's; its answers are marked as from the console;
     * and with learning on, nothing is learned (no call beyond the answers) and the conversation
     * is marked learned, so the quiet pass leaves it alone.
     */
    #[DataProvider('databases')]
    public function testAQuestionFromTheConsole(string $type, string $host, int $port): void
    {
        // Arrange — learning on, every message: a learning call would show as a fourth request
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['memory' => ['learn' => true, 'learn_every' => 1]]]);
        $this->account(7, 99);
        $this->answer([
            [200, self::calls([['remember', ['content' => 'Counts by month']]])],
            [200, self::says('Two parents paid.')],
            [200, self::says('Three, in September.')],
        ]);

        // Act
        $first = new CommandTester(new LlmAsk());
        $first->execute(['question' => 'How many paid in October?', '--as' => '7']);
        $id    = (int) $this->rows('assist.chat_conversations')[0]['conversation_id'];
        $again = new CommandTester(new LlmAsk());
        $again->execute(['question' => 'And September?', '--as' => '7', '--conversation' => (string) $id, '--json' => true]);
        $json     = json_decode($again->getDisplay(), true);
        $messages = $this->db->queryBuilder()->table('assist.chat_messages')->where('conversation_id', $id)->orderBy('message_id')->getAllOrFail();
        $row      = $this->rows('assist.chat_conversations')[0];

        // Assert
        $this->assertSame(0, $first->getStatusCode());
        $this->assertStringContainsString('Two parents paid.', $first->getDisplay());
        $this->assertStringContainsString('tool remember {"content":"Counts by month"}', $first->getDisplay(), 'the tool it called, with its arguments');
        $this->assertStringContainsString('conversation ' . $id . ' ', $first->getDisplay());
        $this->assertStringContainsString('Ai/analyst?c=' . $id, $first->getDisplay(), 'where it opens');
        $this->assertSame([true, $id, 'Three, in September.'], [$json['ok'], $json['conversation_id'], $json['answer']], 'continued, as JSON');
        $this->assertCount(1, $this->rows('assist.chat_conversations'), 'one conversation');
        $this->assertSame(['admin', 7], [(string) $row['space'], (int) $row['userid']], 'the administrator\'s analyst');
        $answers = array_values(array_filter($messages, static fn (array $m): bool => $m['role'] === 'assistant'));
        foreach ($answers as $answer) {
            $this->assertSame('console', json_decode((string) $answer['extra'], true)['source'] ?? null, 'marked as from the console');
        }
        $this->assertCount(3, $this->sent, 'the answers alone: nothing learned');
        $this->assertSame((int) end($answers)['message_id'], (int) $row['learned_upto'], 'marked learned, so the quiet pass leaves it');
    }

    /**
     * Refused, saying why, before any model is asked: no `--as`, an account that is none, an empty
     * question, an AI not connected; and the `ask` tool as anybody below a super administrator.
     */
    #[DataProvider('databases')]
    public function testRefusals(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->account(7, 99);
        $this->account(8, 30);
        $this->answer([[200, self::says('Should not be asked.')]]);
        $tool = new AnalystAskTool();

        // Act
        $nobody = new CommandTester(new LlmAsk());
        $nobody->execute(['question' => 'Hi']);
        $none = new CommandTester(new LlmAsk());
        $none->execute(['question' => 'Hi', '--as' => '424242', '--json' => true]);
        $empty    = ConsoleAsk::ask(7, '   ');
        $lowly    = $tool->execute(['question' => 'Hi', 'as' => 8]);
        $this->setting('llm_openai_api_key', '');
        $unwired  = ConsoleAsk::ask(7, 'Hi');

        // Assert
        $this->assertSame(2, $nobody->getStatusCode(), 'invalid: who asks is not said');
        $this->assertStringContainsString('--as=', $nobody->getDisplay());
        $this->assertSame(1, $none->getStatusCode());
        $this->assertSame([false, 'There is no account 424242.'], [json_decode($none->getDisplay(), true)['ok'], json_decode($none->getDisplay(), true)['says']]);
        $this->assertSame('Ask it something.', $empty['says']);
        $this->assertStringContainsString('is not super', $lowly['says'], 'the tool asks only as root or a super administrator');
        $this->assertStringContainsString('not connected', $unwired['says']);
        $this->assertSame([], $this->sent, 'no model asked');
        $this->assertSame([], $this->rows('assist.chat_conversations'));
        $this->assertSame(['ask', ['question', 'as']], [$tool->name(), $tool->inputSchema()['required']]);
    }
}
