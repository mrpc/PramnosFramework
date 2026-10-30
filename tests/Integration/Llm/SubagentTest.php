<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Client;
use Pramnos\Llm\Subagent;
use Pramnos\Llm\SubagentResult;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * A task handed to a model of its own, and the result it always gives back — on MySQL and
 * PostgreSQL, where its artifacts are kept.
 *
 * What holds: the subagent starts from its instructions and the task alone; whatever happens,
 * the caller gets a SubagentResult and never an exception; the summary is never empty; and an
 * artifact is kept only when the task ends in success — one that ran out of rounds or budget
 * leaves it a draft, in nobody's list.
 */
#[CoversClass(Subagent::class)]
#[CoversClass(SubagentResult::class)]
class SubagentTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->setting('llm_provider', 'openai');
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A price tool, and whether it ran. */
    private static function priceTool(bool &$ran): array
    {
        return ['prices' => [
            'description' => 'Prices of an item',
            'parameters'  => ['type' => 'object', 'properties' => ['item' => ['type' => 'string']]],
            'run'         => static function (array $a) use (&$ran): string {
                $ran = true;

                return 'cable: 1.20/m';
            },
        ]];
    }

    /** How many artifacts this scope has kept. */
    private static function kept(Artifacts $artifacts): int
    {
        return count($artifacts->list());
    }

    /**
     * A task that finishes: the tool runs, the drafted artifact is kept, and the summary is the
     * answer. The model saw only its instructions and the task — nothing of a conversation.
     */
    #[DataProvider('databases')]
    public function testAFinishedTaskKeepsItsArtifacts(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(31, null));
        $before    = self::kept($artifacts);
        $ran       = false;
        $this->answer([
            [200, self::calls([['prices', ['item' => 'cable']], ['save_artifact', ['kind' => 'text', 'title' => 'Cable', 'text' => '1.20/m']]])],
            [200, self::says('Cable is 1.20 a metre.')],
        ]);
        $agent = new Subagent('You compare prices.', self::priceTool($ran), 6, new Client(), $artifacts);

        // Act
        $result = $agent->run('Price of cable');

        // Assert
        $this->assertTrue($result->succeeded());
        $this->assertSame(['success', 'completed'], [$result->status, $result->reason]);
        $this->assertSame('Cable is 1.20 a metre.', $result->summary);
        $this->assertTrue($ran);
        $this->assertCount(1, $result->committedArtifacts);
        $this->assertSame([], $result->draftArtifacts);
        $this->assertSame($before + 1, self::kept($artifacts), 'the artifact is in the database');
        $this->assertSame(2, $result->roundsUsed);
        // 20+4 for the tool round, 10+5 for the answer.
        $this->assertSame(39, $result->tokensSpent);
        // Isolation: the first request holds the instructions and the task, nothing else.
        $first = $this->sent[0]['body']['messages'];
        $this->assertSame('system', $first[0]['role']);
        $this->assertStringStartsWith('You compare prices.', $first[0]['content']);
        $this->assertSame([['role' => 'user', 'content' => 'Price of cable']], self::spoken($this->sent[0]));
    }

    /**
     * Out of rounds: the model is made to answer on the last one, the task is partial, and what it
     * drafted is handed back — not kept.
     */
    #[DataProvider('databases')]
    public function testOutOfRoundsLeavesDrafts(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(32, null));
        $before    = self::kept($artifacts);
        $ran       = false;
        $this->answer([
            [200, self::calls([['save_artifact', ['kind' => 'text', 'title' => 'Half', 'text' => 'so far']]])],
            [200, self::says('I got as far as the cable.')],
        ]);
        $agent = new Subagent('You compare prices.', self::priceTool($ran), 2, new Client(), $artifacts);

        // Act
        $result = $agent->run('Prices for everything');

        // Assert
        $this->assertSame(['partial', 'max_rounds_reached'], [$result->status, $result->reason]);
        $this->assertSame('I got as far as the cable.', $result->summary, 'the answer it gave from what it had');
        $this->assertSame([], $result->committedArtifacts);
        $this->assertSame('Half', $result->draftArtifacts[0]['title']);
        $this->assertSame($before, self::kept($artifacts), 'a draft is in nobody\'s list');
    }

    /** Over its turn budget: partial, with the reason, and the draft not kept. */
    #[DataProvider('databases')]
    public function testOverBudgetIsPartial(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(33, null));
        $ran       = false;
        $this->answer([
            [200, self::calls([['prices', ['item' => 'cable']], ['save_artifact', ['kind' => 'text', 'title' => 'Draft', 'text' => 'x']]])],
            [200, self::says('Cable only: 1.20.')],
        ]);
        $agent = new Subagent('You compare prices.', self::priceTool($ran), 6, (new Client())->withTurnBudget(maxTokens: 10), $artifacts);

        // Act
        $result = $agent->run('Prices');

        // Assert
        $this->assertSame(['partial', 'budget_exceeded'], [$result->status, $result->reason]);
        $this->assertCount(1, $result->draftArtifacts);
        $this->assertSame([], $result->committedArtifacts);
    }

    /**
     * An artifact the framework would not take is refused when it is drafted, so the model can
     * put it right — and nothing wrong is handed back as a draft.
     */
    #[DataProvider('databases')]
    public function testABadDraftIsRefusedAtOnce(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(34, null));
        $this->answer([
            [200, self::calls([['save_artifact', ['kind' => 'text', 'title' => '   ']]])],
            [200, self::says('Done.')],
        ]);
        $agent = new Subagent('Write things.', [], 6, new Client(), $artifacts);

        // Act
        $result = $agent->run('Write');

        // Assert
        $toolResult = self::spoken($this->sent[1])[2]['content'];
        $this->assertSame('Error: An artifact needs a title.', $toolResult);
        $this->assertSame([], $result->draftArtifacts);
        $this->assertSame([], $result->committedArtifacts);
    }

    /**
     * An artifact that cannot be kept when the task finishes stays a draft, and the result is
     * still a result.
     */
    #[DataProvider('databases')]
    public function testAnArtifactThatCannotBeKeptStaysADraft(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(35, null));
        // A tool that leaves the application without a working database after the draft, so
        // keeping it fails at the end.
        $away = ['away' => ['description' => 'a', 'parameters' => [], 'run' => static function (): string {
            $singleton = &\Pramnos\Framework\Factory::getDatabase();
            $singleton = new \Pramnos\Database\Database();

            return 'ok';
        }]];
        $this->answer([
            [200, self::calls([['save_artifact', ['kind' => 'text', 'title' => 'Kept?', 'text' => 'x']], ['away', []]])],
            [200, self::says('Done.')],
        ]);

        // Act
        try {
            $result = (new Subagent('Write things.', $away, 6, new Client(), $artifacts))->run('Write');
        } finally {
            $singleton = &\Pramnos\Framework\Factory::getDatabase();
            $singleton = $this->db;
        }

        // Assert
        $this->assertSame('success', $result->status);
        $this->assertSame([], $result->committedArtifacts);
        $this->assertSame('Kept?', $result->draftArtifacts[0]['title']);
    }

    /**
     * The provider refusing: failed, and the summary says why and what had been called.
     */
    public function testAProviderErrorIsAFailureWithASummary(): void
    {
        // Arrange
        $ran = false;
        $this->answer([
            [200, self::calls([['prices', ['item' => 'cable']]])],
            [400, ['error' => ['message' => 'bad request']]],
        ]);

        // Act
        $result = (new Subagent('You compare prices.', self::priceTool($ran), 6, new Client()))->run('Prices');

        // Assert
        $this->assertSame(['failed', 'provider_error'], [$result->status, $result->reason]);
        $this->assertNotSame('', $result->summary);
        $this->assertStringEndsWith('It had called: prices.', $result->summary);
    }

    /** Stopped before it began: failed, cancelled, and it says it had called nothing. */
    public function testACancelledTaskFails(): void
    {
        // Arrange
        $this->answer([[200, self::says('never')]]);
        $client = (new Client())->cancelWhen(static fn (): bool => true);

        // Act
        $result = (new Subagent('x', [], 6, $client))->run('task');

        // Assert
        $this->assertSame(['failed', 'cancelled'], [$result->status, $result->reason]);
        $this->assertStringEndsWith('It had called no tool.', $result->summary);
        $this->assertSame([], $this->sent, 'nothing was asked');
    }

    /**
     * Zero-crash: an exception out of the client itself is a failed result, not an exception —
     * and an answer that was empty still has a summary.
     */
    public function testAnExceptionIsAResultNotAThrow(): void
    {
        // Arrange
        $client = new class extends Client {
            public function askWithTools(string $systemPrompt, array $messages, array $tools, int $rounds = 6, array $due = [], ?callable $verify = null): array
            {
                throw new \LogicException('transport exploded');
            }
        };

        // Act
        $result = (new Subagent('x', [], 6, $client))->run('task');

        // Assert
        $this->assertSame(['failed', 'tool_error'], [$result->status, $result->reason]);
        $this->assertStringContainsString('transport exploded', $result->summary);
        $this->assertSame(0, $result->roundsUsed);
    }

    /**
     * As a tool of a parent's: the parent says the task and reads back a short JSON — the summary
     * and titles — and the tool carries the flags of the tools inside it, so the parent's gate
     * and held writes see a writing subagent as writing.
     */
    #[DataProvider('databases')]
    public function testAsAToolOfTheParent(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(36, null));
        $this->answer([
            [200, self::calls([['save_artifact', ['kind' => 'text', 'title' => 'Report', 'text' => 'long body here']]])],
            [200, self::says('Report written.')],
        ]);
        $writer = ['send' => ['description' => 's', 'parameters' => [], 'run' => static fn (): string => 'sent', 'writes' => true]];
        $tool   = (new Subagent('Write reports.', $writer, 6, new Client(), $artifacts))->asTool('Write a report');

        // Act
        $read = json_decode(($tool['run'])(['task' => 'the report']), true);

        // Assert
        $this->assertTrue($tool['writes']);
        $this->assertArrayNotHasKey('untrusted', $tool);
        $this->assertSame(['task'], $tool['parameters']['required']);
        $this->assertSame('success', $read['status']);
        $this->assertSame('Report written.', $read['summary']);
        $this->assertSame('Report', $read['committed'][0]['title']);
        // The parent reads titles, never the content.
        $this->assertStringNotContainsString('long body here', (string) json_encode($read));
        $this->assertArrayNotHasKey('drafts', $read);
    }

    /** Drafts in the parent's view: titles, marked as not saved. Without artifacts, no tool to make one. */
    public function testDraftsForTheParentAndNoArtifactTool(): void
    {
        // Arrange
        $result = new SubagentResult('partial', 'max_rounds_reached', 'half', [], [['kind' => 'text', 'title' => 'T', 'text' => 'body']]);
        $this->answer([[200, self::says('ok')]]);

        // Act
        $read = json_decode($result->forParent(), true);
        (new Subagent('x', [], 6, new Client()))->run('task');

        // Assert
        $this->assertSame([['kind' => 'text', 'title' => 'T', 'not_saved' => true]], $read['drafts']);
        $this->assertFalse($result->succeeded());
        $this->assertArrayNotHasKey('tools', $this->sent[0]['body'], 'no artifacts, no save_artifact');
    }
}
