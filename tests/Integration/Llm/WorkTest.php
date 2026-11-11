<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Asks;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Work;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Work on the conversation page (FW-451), on MySQL and PostgreSQL.
 *
 * What has to hold: only somebody the application's gate lets through has the analyst's tools,
 * in the skill `work`, each tagged `work`; nobody else has any; once a work tool is used the
 * conversation is a work conversation — its answers and itself say `mode: work`, the next answer
 * is told so, and nothing in it is learned about the person; `doing` says `mode: work` while a
 * work tool runs.
 */
#[CoversClass(Work::class)]
#[CoversClass(Conversations::class)]
class WorkTest extends LlmTestCase
{
    use ChatDatabase;

    /** The person the gate lets work, and the one it does not. */
    private int $boss = 0;

    private int $other = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        foreach ([$this->boss, $this->other] as $id) {
            if ($id > 0) {
                $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $id)->delete();
                $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->delete();
            }
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Two saved people, and the gate letting the first work. */
    private function people(): void
    {
        foreach (['boss', 'other'] as $who) {
            $user = new \Pramnos\User\User();
            $user->username = 'work' . $who . random_int(100000, 999999);
            $user->email    = $user->username . '@example.test';
            $user->save();
            $this->$who = (int) $user->userid;
        }
        $boss = $this->boss;
        $this->configure(['chat' => ['skills' => false, 'memory' => ['learn' => true, 'learn_every' => 1],
            'work' => ['gate' => static fn (object $user): bool => (int) $user->userid === $boss, 'mood' => 'focused']]]);
    }

    /** The names of the tools a request offered. */
    private static function offered(array $sent): array
    {
        return array_map(static fn (array $t): string => (string) $t['function']['name'], (array) ($sent['body']['tools'] ?? []));
    }

    /**
     * The gate decides; the analyst's space and an application without a gate have none; the
     * tools carry the skill and the tag; `doing` shows the mode.
     */
    #[DataProvider('databases')]
    public function testWhoMayWork(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $turnOf = static function (Scope $scope): \Pramnos\Llm\Chat\Turn {
            $chat = (new Conversations(new BaseAssistant()))->as($scope);

            return new \Pramnos\Llm\Chat\Turn($scope, null, false, [], [], [], $chat);
        };

        // Act
        $tools = Work::tools($turnOf(new Scope($this->boss)));

        // Assert
        $this->assertTrue(Work::allowed(new Scope($this->boss)));
        $this->assertFalse(Work::allowed(new Scope($this->other)), 'the gate refuses');
        $this->assertFalse(Work::allowed(new Scope($this->boss, null, Scope::ADMIN)), 'the analyst is the analyst already');
        $this->assertArrayHasKey('status', $tools);
        $this->assertSame([Work::MODE], $tools['status']['skills']);
        $this->assertTrue($tools['status']['tags'][Work::MODE]);
        $this->assertSame(['tool' => 'status', 'mode' => 'work'], Asks::doingOf('status', [], $tools['status']));
        $this->assertStringContainsString('A focused mood suits it.', Work::instruction());
        $this->configure(['chat' => ['work' => ['mood' => 'x']]]);
        $this->assertFalse(Work::on(), 'no gate, no work');
    }

    /**
     * Somebody the gate lets through is offered the work tools, and somebody else is not; a work
     * tool used makes the conversation work — its answer and itself say so, the next answer is
     * told so, and nothing is learned from it.
     */
    #[DataProvider('databases')]
    public function testAWorkConversation(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $other = $this->other;
        $this->answer([
            [200, self::calls([['status', []]])],
            [200, self::says('Everything is up.')],
            [200, self::says('Still up.')],
            [200, self::says('Hello.')],
        ]);
        $chat = (new Conversations(new BaseAssistant()))->as(new Scope($this->boss));

        // Act
        $first  = $chat->ask(null, 'How is the site today');
        $second = $chat->ask((int) $first['conversation_id'], 'And now');
        $calls  = count($this->sent);
        (new Conversations(new BaseAssistant()))->as(new Scope($other))->ask(null, 'Hello');
        $learned = $this->db->queryBuilder()->table('assist.chat_memory')->where('userid', $this->boss)->count();

        // Assert
        $this->assertContains('status', self::offered($this->sent[0]), 'the gate let them through');
        $this->assertNotContains('status', self::offered($this->sent[3]), 'nobody else has them');
        $this->assertSame('work', $first['message']['mode']);
        $this->assertSame('work', $second['message']['mode'], 'for the whole conversation');
        $this->assertSame('work', $chat->find((int) $first['conversation_id'])['mode']);
        $this->assertStringContainsString('This conversation is work', json_encode($this->sent[2]['body']['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $learned, 'nothing learned about the person from the business\'s data');
        $this->assertSame(3, $calls, 'and no call made to learn — only the answers');
        $this->assertCount(5, $this->sent, 'while an ordinary conversation is learned from');
        $this->db->queryBuilder()->table('assist.chat_conversations')->whereIn('userid', [$this->boss, $other])->delete();
    }
}
