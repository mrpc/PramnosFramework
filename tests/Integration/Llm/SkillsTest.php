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
 * Tools in skills, in a conversation — on MySQL and PostgreSQL.
 *
 * What holds: off — the default — every tool is offered as it was; on
 * (`'llm' => ['chat' => ['skills' => true]]`), a tool of a skill is offered only once the model
 * turns its skill on, the framework's and an application's own alike, with what each skill is for
 * in `use_skill`; a tool of no skill is always offered; and a worker rebuilding the tools for a
 * parallel call still finds every one.
 */
#[CoversClass(Conversations::class)]
#[CoversClass(\Pramnos\Llm\ToolRegistry::class)]
class SkillsTest extends LlmTestCase
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

    /** An assistant with a tool of its own skill, one of none, and what its skill is for. */
    private static function assistant(): BaseAssistant
    {
        return new class extends BaseAssistant {
            public function tools(Turn $turn): array
            {
                return [
                    'crm_lookup' => ['description' => 'Find a customer', 'parameters' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                        'run' => static fn (): string => 'Acme, since 2019', 'skills' => ['crm']],
                    'whoami' => ['description' => 'Who is asking', 'parameters' => [], 'run' => static fn (): string => 'Ann'],
                ];
            }

            public function skills(): array
            {
                return ['crm' => 'customers and their orders'];
            }
        };
    }

    /** The names of the tools a request offered. */
    private static function offered(array $request): array
    {
        return array_map(static fn (array $t): string => $t['function']['name'], (array) ($request['body']['tools'] ?? []));
    }

    /**
     * Off: every tool, the application's and the framework's, as before.
     */
    #[DataProvider('databases')]
    public function testOffEveryToolIsOffered(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('Hi')]]);

        // Act
        (new Conversations(self::assistant()))->as(new Scope(7, null))->ask(null, 'Hi');
        $this->configure(['chat' => ['web' => true]]);
        (new Conversations(self::assistant()))->as(new Scope(7, null))->ask(null, 'Hi');

        // Assert
        $names = self::offered($this->sent[0]);
        $this->assertContains('crm_lookup', $names);
        $this->assertContains('pin_artifact', $names);
        $this->assertNotContains('use_skill', $names);
        $this->assertNotContains('fetch_url', $names, 'the web is the application\'s to offer');
        $this->assertContains('fetch_url', self::offered($this->sent[1]));
        $this->assertContains('web_search', self::offered($this->sent[1]));
    }

    /**
     * On: the first request offers the tools of no skill and use_skill, which names each skill
     * and what it is for; once the model turns `crm` on, its tool is offered from the next step,
     * and called.
     */
    #[DataProvider('databases')]
    public function testOnASkillsToolsComeWhenItIsTurnedOn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['skills' => true]]);
        $this->answer([
            [200, self::calls([['use_skill', ['skill' => 'crm']]])],
            [200, self::calls([['crm_lookup', ['name' => 'Acme']]])],
            [200, self::says('Acme has been a customer since 2019.')],
        ]);

        // Act
        $answer = (new Conversations(self::assistant()))->as(new Scope(7, null))->ask(null, 'Who is Acme?');

        // Assert
        $first = self::offered($this->sent[0]);
        $this->assertContains('whoami', $first, 'a tool of no skill is always there');
        $this->assertContains('save_artifact', $first, 'kept out of the artifacts skill');
        $this->assertContains('remember', $first);
        $this->assertNotContains('crm_lookup', $first);
        $this->assertNotContains('pin_artifact', $first, 'the framework\'s artifacts skill');
        $this->assertNotContains('schedule_task', $first, 'the framework\'s tasks skill');
        $description = $this->sent[0]['body']['tools'][array_search('use_skill', $first, true)]['function']['description'];
        $this->assertStringContainsString('crm (customers and their orders)', $description);
        $this->assertStringContainsString('artifacts (reading, changing, finding and pinning artifacts kept earlier)', $description);
        $this->assertContains('crm_lookup', self::offered($this->sent[1]), 'from the next step');
        $this->assertTrue($answer['ok']);
        $this->assertSame('Acme has been a customer since 2019.', $answer['message']['content']);
    }

    /**
     * A worker rebuilding the tools for one parallel call gets every tool, grouped or not: the
     * call it runs was offered once its skill was on.
     */
    #[DataProvider('databases')]
    public function testAWorkerFindsEveryTool(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['skills' => true]]);
        $chat = (new Conversations(self::assistant()))->as(new Scope(7, null));

        // Act
        $tools = $chat->toolsFor(new Turn(new Scope(7, null), null, false, [], [], [], $chat));

        // Assert
        $this->assertArrayHasKey('crm_lookup', $tools);
        $this->assertArrayHasKey('pin_artifact', $tools);
        $this->assertArrayNotHasKey('use_skill', $tools);
        $this->assertSame(['artifacts'], $tools['pin_artifact']['skills']);
        $this->assertArrayNotHasKey('skills', $tools['save_artifact']);
    }

    /**
     * An application's external MCP servers are offered in conversations only with
     * `'llm' => ['chat' => ['mcp' => true]]`: configuring the servers alone does not put a
     * third party's tools in front of every person.
     */
    #[DataProvider('databases')]
    public function testTheMcpServersAreOfferedOnlyWhenAsked(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['mcp_servers' => ['fx' => ['command' => PHP_BINARY, 'args' => [ROOT . '/tests/fixtures/mcp/server.php']]]]);
        $this->answer([[200, self::says('Hi')], [200, self::says('Hi')]]);

        try {
            // Act
            (new Conversations(self::assistant()))->as(new Scope(7, null))->ask(null, 'Hi');
            $this->configure(['mcp_servers' => ['fx' => ['command' => PHP_BINARY, 'args' => [ROOT . '/tests/fixtures/mcp/server.php']]], 'chat' => ['mcp' => true]]);
            (new Conversations(self::assistant()))->as(new Scope(7, null))->ask(null, 'Hi');
        } finally {
            \Pramnos\Llm\Mcp\Servers::closeAll();
        }

        // Assert
        $this->assertNotContains('fx__echo', self::offered($this->sent[0]));
        $this->assertContains('fx__echo', self::offered($this->sent[1]));
    }

    /**
     * An answer cut at its turn budget is remembered as cut: the next question's history names
     * what it had already called, with the arguments, so "go on" starts from there. An answer
     * that finished carries no such note.
     */
    #[DataProvider('databases')]
    public function testACutAnswerTellsTheNextWhereItStopped(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['turn_budget' => ['tokens' => 1]]);
        $this->answer([
            [200, self::calls([['crm_lookup', ['name' => 'Acme']]])],
            [200, self::says('I looked up Acme; I ran out before the rest.')],
            [200, self::says('Going on.')],
        ]);
        $chat = (new Conversations(self::assistant()))->as(new Scope(7, null));

        // Act
        $first = $chat->ask(null, 'Compare every customer.');
        $chat->ask((int) $first['conversation_id'], 'Go on.');

        // Assert
        $history = json_encode($this->sent[2]['body']['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('stopped at its budget after calling: crm_lookup({\\"name\\":\\"Acme\\"})', $history);
        $this->assertSame('', Conversations::whereItStopped(['extra' => [], 'tools' => [['name' => 'x']]]), 'a finished answer');
        $this->assertSame('', Conversations::whereItStopped(['extra' => ['stopped' => 'rounds'], 'tools' => []]), 'nothing called');
        $this->assertStringContainsString('limit of rounds', Conversations::whereItStopped(['extra' => ['stopped' => 'rounds'], 'tools' => [['name' => 'x', 'arguments' => []]]]));
    }
}
