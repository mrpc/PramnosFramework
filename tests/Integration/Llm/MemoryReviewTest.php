<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Console\Commands\LlmEval;
use Pramnos\Console\Commands\LlmMemoryReview;
use Pramnos\Llm\Chat\Memory;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reviewing what memory learning kept, and scoring it (FW-322): `llm:memory-review` reads the
 * learned memories again by the test learning applies now and proposes which fail it — forgetting
 * only those named, only learned ones — and `llm:eval --memory` scores what learning would keep.
 */
#[CoversClass(Memory::class)]
#[CoversClass(LlmMemoryReview::class)]
#[CoversClass(LlmEval::class)]
class MemoryReviewTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->queryBuilder()->table('chat_memory')->whereIn('userid', [7, 8])->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** One memory, by whom and how it came. */
    private function memory(int $userid, string $content, string $source): int
    {
        $stamp = gmdate('Y-m-d H:i:s');
        $this->db->queryBuilder()->table('chat_memory')->insert(['organization_id' => null, 'userid' => $userid, 'kind' => 'fact',
            'content' => $content, 'space' => Scope::CHAT, 'source' => $source, 'created_at' => $stamp, 'updated_at' => $stamp]);

        return (int) $this->db->queryBuilder()->table('chat_memory')->where('content', $content)->first()->fields['memory_id'];
    }

    /**
     * The review reads only learned memories, proposes the ones the model says fail the test, with
     * why, and forgets nothing; `--forget` forgets the learned ones named and leaves one a person
     * asked to keep.
     */
    #[DataProvider('databases')]
    public function testTheReviewProposesAndForgetsOnlyWhatIsNamed(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $task  = $this->memory(7, 'Wants a Jira issue opened for the receipt', 'learned');
        $keeps = $this->memory(8, 'Prefers amounts without VAT', 'learned');
        $asked = $this->memory(8, 'Lives in Patras', 'model');
        $this->answer([[200, self::says('{"forget": [{"id": ' . $task . ', "why": "a one-off request"}, {"id": ' . $asked . ', "why": "not learned"}]}')]]);
        $review = new CommandTester(new LlmMemoryReview());

        // Act
        $review->execute([]);
        $listed = $review->getDisplay();
        $sent   = (string) $this->sent[0]['body']['messages'][1]['content'];
        $forget = new CommandTester(new LlmMemoryReview());
        $exit   = $forget->execute(['--forget' => $task . ',' . $asked]);
        $left   = array_map('intval', array_column($this->db->queryBuilder()->table('chat_memory')->whereIn('userid', [7, 8])->getAllOrFail(), 'memory_id'));

        // Assert
        $this->assertStringContainsString('2 learned memories read; 1 proposed for forgetting.', $listed, 'only learned ones, only ids it was given');
        $this->assertStringContainsString('Wants a Jira issue opened for the receipt — a one-off request', $listed);
        $this->assertStringContainsString('--forget=' . $task, $listed);
        $this->assertStringNotContainsString('Lives in Patras', $sent, 'what a person asked to keep is not reviewed');
        $this->assertStringContainsString('in an unrelated conversation next month', (string) $this->sent[0]['body']['messages'][0]['content']);
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('#' . $asked . ' is not a learned memory; left.', $forget->getDisplay());
        sort($left);
        $this->assertSame([$keeps, $asked], $left, 'the one named and learned is gone; the rest stay');
    }

    /**
     * `llm:eval --memory` asks each case by the learning test and fails when one misses: kept from
     * a one-off request, or nothing kept from a lasting preference.
     */
    #[DataProvider('databases')]
    public function testTheMemoryEvalScoresEachCase(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $file = sys_get_temp_dir() . '/pf-memory-cases-' . bin2hex(random_bytes(3)) . '.json';
        file_put_contents($file, json_encode(['cases' => [
            ['said' => 'Open a Jira issue for this receipt.', 'expect' => 'nothing'],
            ['said' => 'Always give me amounts without VAT.', 'expect' => 'keep'],
        ]]));
        $this->answer([
            [200, self::says('{"changes": []}')],
            [200, self::says('{"changes": [{"op": "keep", "kind": "preference", "memory": "Prefers amounts without VAT"}]}')],
            [200, self::says('{"changes": [{"op": "keep", "kind": "fact", "memory": "Wants a Jira issue"}]}')],
            [200, self::says('{"changes": []}')],
        ]);

        try {
            // Act — as expected, then both missed
            $good = new CommandTester(new LlmEval());
            $pass = $good->execute(['--memory' => $file]);
            $bad  = new CommandTester(new LlmEval());
            $fail = $bad->execute(['--memory' => $file]);
            $none = (new CommandTester(new LlmEval()))->execute(['--memory' => $file . '.missing']);
        } finally {
            unlink($file);
        }

        // Assert
        $this->assertSame(Command::SUCCESS, $pass, $good->getDisplay());
        $this->assertStringContainsString('2 of 2 as expected.', $good->getDisplay());
        $this->assertSame(Command::FAILURE, $fail);
        $this->assertStringContainsString('0 of 2 as expected.', $bad->getDisplay());
        $this->assertStringContainsString('kept: Wants a Jira issue', $bad->getDisplay());
        $this->assertSame(Command::FAILURE, $none, 'no cases is a failure, not a pass');
        $this->assertStringContainsString('Never keep: a request or a task', (string) $this->sent[0]['body']['messages'][0]['content']);
    }

    /** The framework's own cases are there and well formed: each says what it expects. */
    public function testTheFrameworksOwnCasesAreWellFormed(): void
    {
        // Arrange & Act
        $cases = json_decode((string) file_get_contents(LlmEval::MEMORY_CASES), true)['cases'] ?? [];

        // Assert
        $this->assertGreaterThanOrEqual(6, count($cases));
        foreach ($cases as $case) {
            $this->assertNotSame('', (string) ($case['said'] ?? ''));
            $this->assertContains($case['expect'] ?? null, ['nothing', 'keep']);
        }
    }

    /**
     * One person's only (`--user`), nothing learned is nothing to ask about, and a table that
     * cannot be read is a failure that says so.
     */
    #[DataProvider('databases')]
    public function testOnePersonNothingToReviewAndAnUnreadableTable(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->memory(8, 'Prefers amounts without VAT', 'learned');
        $this->answer([[200, self::says('{"forget": []}')]]);

        // Act
        $one = new CommandTester(new LlmMemoryReview());
        $one->execute(['--user' => '8']);
        $nobody = new CommandTester(new LlmMemoryReview());
        $nobody->execute(['--user' => '7']);
        $prefix = $this->db->prefix;
        $this->db->prefix = 'nosuchprefix_';
        try {
            $broken = new CommandTester(new LlmMemoryReview());
            $exit   = $broken->execute([]);
            $unread = new CommandTester(new LlmMemoryReview());
            $unforgot = $unread->execute(['--forget' => '5']);
        } finally {
            $this->db->prefix = $prefix;
        }

        // Assert
        $this->assertStringContainsString('1 learned memories read; 0 proposed for forgetting.', $one->getDisplay());
        $this->assertStringContainsString('No learned memories to review.', $nobody->getDisplay());
        $this->assertCount(1, $this->sent, 'nothing asked when there is nothing to review');
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('could not be read', $broken->getDisplay());
        $this->assertSame(Command::FAILURE, $unforgot, 'nothing forgotten when nothing can be read');
        $this->assertStringContainsString('#5 is not a learned memory; left.', $unread->getDisplay());
    }
}
