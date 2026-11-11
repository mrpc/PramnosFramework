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
        // Off said outright: the web's two tools would take the count past SKILLS_FROM, which turns skills on by itself.
        $this->configure(['chat' => ['web' => true, 'skills' => false]]);
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
        $this->assertContains('schedule_task', $first, 'kept out of the tasks skill on the conversation page: "remind me at ten" (FW-431)');
        $this->assertNotContains('cancel_scheduled_task', $first, 'the framework\'s tasks skill');
        $description = $this->sent[0]['body']['tools'][array_search('use_skill', $first, true)]['function']['description'];
        $this->assertStringContainsString('crm (customers and their orders)', $description);
        $this->assertStringContainsString('artifacts (reading, changing, finding, pinning, sharing and assigning artifacts kept earlier — tables, lists, texts, saved queries)', $description);
        $this->assertContains('crm_lookup', self::offered($this->sent[1]), 'from the next step');
        $this->assertTrue($answer['ok']);
        $this->assertSame('Acme has been a customer since 2019.', $answer['message']['content']);
    }

    /**
     * A skill installed from a catalogue and turned on is offered to Ask as `catalog:<name>`,
     * beside the application's, and its reference is read from the database; one still off is not.
     */
    #[DataProvider('databases')]
    public function testACatalogueSkillTurnedOnIsOffered(string $type, string $host, int $port): void
    {
        // Arrange — a reader, one skill on and one off
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Llm\CreateChatSkillsTable::class,
            \Pramnos\Framework\Migrations\Llm\AddCatalogueColumnsToChatSkills::class], $this->db);
        $this->db->queryBuilder()->table(\Pramnos\Llm\Chat\StoredSkills::TABLE)->delete();
        $reader = new \Pramnos\User\User();
        $reader->username = 'catalog_reader_' . bin2hex(random_bytes(3));
        $reader->email    = $reader->username . '@example.com';
        $reader->usertype = 10;
        $reader->save();
        \Pramnos\User\User::clearUserCache();
        foreach (['seo' => 'on', 'idle' => 'off'] as $name => $status) {
            $this->db->queryBuilder()->table(\Pramnos\Llm\Chat\StoredSkills::TABLE)->insert(['userid' => 1, 'level' => 'catalog', 'name' => $name,
                'description' => 'An ' . $name . ' audit', 'tools' => '["crm_lookup"]', 'body' => 'Audit.', 'status' => $status, 'source' => 'acme/skills/' . $name,
                'source_commit' => str_repeat('a', 40), 'content_hash' => 'h', 'licence' => 'MIT', 'created_at' => time(), 'updated_at' => time(),
                'files' => json_encode(['skill' => 'Audit the pages.', 'references' => ['list.md' => "# The list\nTitles."], 'evals' => []])]);
        }
        $this->configure(['chat' => ['skills' => true]]);
        $this->answer([
            [200, self::calls([['use_skill', ['skill' => 'catalog:seo']]])],
            [200, self::calls([['read_skill_reference', ['skill' => 'catalog:seo', 'file' => 'list.md']]])],
            [200, self::says('Done.')],
        ]);

        // Act
        (new Conversations(self::assistant()))->as(new Scope((int) $reader->userid, null))->ask(null, 'Audit the site');

        // Assert
        $first       = self::offered($this->sent[0]);
        $description = $this->sent[0]['body']['tools'][array_search('use_skill', $first, true)]['function']['description'];
        $this->assertStringContainsString('catalog:seo (An seo audit)', $description);
        $this->assertStringNotContainsString('catalog:idle', $description, 'off: not offered');
        $this->assertStringContainsString('Titles.', json_encode($this->sent[2]['body']['messages'], JSON_UNESCAPED_UNICODE), 'its reference, read from the database');
        $this->db->queryBuilder()->table(\Pramnos\Llm\Chat\StoredSkills::TABLE)->delete();
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', (int) $reader->userid)->delete();
    }

    /**
     * A skill the application wrote as a file: its presence turns skills on, the model sees it by
     * its description, and turning it on hands the model the file's method with the tools it lists
     * — the method in the context only from then on.
     */
    #[DataProvider('databases')]
    public function testASkillFileHandsItsMethodToTheModelWhenTurnedOn(string $type, string $host, int $port): void
    {
        // Arrange — a skill file opening the assistant's crm tool, and no `skills` setting
        $dir = sys_get_temp_dir() . '/pf-skills-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/crm-loyalty.md', "---\nname: crm-loyalty\ndescription: Retention and loyalty\ntools: [crm_lookup]\n---\nStart from the cohort, not the campaign.");
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['skills_path' => $dir]]);
        $this->answer([
            [200, self::calls([['use_skill', ['skill' => 'crm-loyalty']]])],
            [200, self::says('Done.')],
        ]);

        try {
            // Act
            (new Conversations(self::assistant()))->as(new Scope(7, null))->ask(null, 'Who is drifting away?');
        } finally {
            unlink($dir . '/crm-loyalty.md');
            rmdir($dir);
        }

        // Assert — grouped because of the file; the method only after use_skill
        $first = self::offered($this->sent[0]);
        $this->assertContains('use_skill', $first);
        $this->assertNotContains('crm_lookup', $first);
        $description = $this->sent[0]['body']['tools'][array_search('use_skill', $first, true)]['function']['description'];
        $this->assertStringContainsString('crm-loyalty (Retention and loyalty)', $description);
        $this->assertStringNotContainsString('Start from the cohort', (string) json_encode($this->sent[0]['body']['messages']), 'not in the context before');
        $toolAnswer = array_values(array_filter($this->sent[1]['body']['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        $this->assertStringContainsString("Turned on crm-loyalty: crm_lookup.\n\nStart from the cohort, not the campaign.", (string) end($toolAnswer)['content']);
        $this->assertContains('crm_lookup', self::offered($this->sent[1]));
    }

    /**
     * A person's own skill kept in the database (StoredSkills) is offered as `mine:<name>`, hands
     * over its method marked as theirs, and opens only the tools the answer already has — one it
     * lists that the person may not use is left out: a skill gives no right.
     */
    #[DataProvider('databases')]
    public function testAStoredSkillOpensOnlyToolsTheAnswerAlreadyHas(string $type, string $host, int $port): void
    {
        // Arrange — a person, and their skill listing a tool the assistant has and one it has not
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Llm\CreateChatSkillsTable::class], $this->db);
        $this->configure(['chat' => ['stored_skills' => true]]);
        $name = 'stored_' . bin2hex(random_bytes(3));
        $user = new \Pramnos\User\User();
        $user->username = $name;
        $user->email    = $name . '@example.com';
        $user->save();
        $id = (int) $user->userid;
        (new \Pramnos\Llm\Chat\StoredSkills($this->db))->save($user, ['name' => 'churn', 'description' => 'Who is drifting away',
            'tools' => ['crm_lookup', 'sql'], 'body' => 'Start from the cohort.']);
        $this->answer([
            [200, self::calls([['use_skill', ['skill' => 'mine:churn']]])],
            [200, self::says('Done.')],
        ]);

        try {
            // Act
            (new Conversations(self::assistant()))->as(new Scope($id, null))->ask(null, 'Who is drifting away?');
        } finally {
            $this->db->queryBuilder()->table(\Pramnos\Llm\Chat\StoredSkills::TABLE)->where('userid', $id)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->delete();
            \Pramnos\User\User::clearUserCache();
        }

        // Assert
        $first       = self::offered($this->sent[0]);
        $description = $this->sent[0]['body']['tools'][array_search('use_skill', $first, true)]['function']['description'];
        $this->assertStringContainsString('mine:churn (Who is drifting away)', $description, 'offered under its own prefix');
        $tools      = array_values(array_filter($this->sent[1]['body']['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        $toolAnswer = (string) end($tools)['content'];
        $this->assertStringContainsString('Turned on mine:churn: crm_lookup.', $toolAnswer, 'only the tool the answer has');
        $this->assertStringContainsString('wrote for themselves', $toolAnswer, 'its method marked as the person\'s own');
        $this->assertContains('crm_lookup', self::offered($this->sent[1]));
        $this->assertNotContains('sql', self::offered($this->sent[1]), 'a skill gives no right');
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

    /**
     * Unsaid either way, an assistant with more than `Conversations::SKILLS_FROM` tools gets them
     * grouped — every round carries every description — and `'skills' => false` keeps them as
     * they are however many there are.
     */
    #[DataProvider('databases')]
    public function testManyToolsAreGroupedUnlessTheApplicationSaysNo(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $many = new class extends BaseAssistant {
            public function tools(Turn $turn): array
            {
                $tools = [];
                for ($i = 0; $i < 25; $i++) {
                    $tools['crm_' . $i] = ['description' => 'CRM ' . $i, 'parameters' => [], 'run' => static fn (): string => 'ok', 'skills' => ['crm']];
                }

                return $tools;
            }
        };
        $this->answer([[200, self::says('Hi')], [200, self::says('Hi')]]);

        // Act
        (new Conversations($many))->as(new Scope(7, null))->ask(null, 'Hi');
        $this->configure(['chat' => ['skills' => false]]);
        (new Conversations($many))->as(new Scope(7, null))->ask(null, 'Hi');

        // Assert
        $this->assertContains('use_skill', self::offered($this->sent[0]));
        $this->assertNotContains('crm_0', self::offered($this->sent[0]), 'until the model turns crm on');
        $this->assertContains('crm_0', self::offered($this->sent[1]));
        $this->assertNotContains('use_skill', self::offered($this->sent[1]));
    }
}
