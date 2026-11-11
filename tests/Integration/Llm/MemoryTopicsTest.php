<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Memory;
use Pramnos\Llm\Chat\MemoryAdmin;
use Pramnos\Llm\Chat\Profile;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * Memories by topic, and what a channel reads from the others (FW-466), on MySQL and PostgreSQL.
 *
 * What has to hold: a memory keeps what it is about, `other` for what is none; a channel reads
 * its own in full and from another only the topics the application names — never `other`, never
 * `mood` unless feelings are opened, a persona never another's own unless named — and the profile
 * where the reader is not the companion; the prompt marks each by where it was learned; the
 * person and an administrator correct a memory's topic.
 */
#[CoversClass(Memory::class)]
#[CoversClass(MemoryAdmin::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class MemoryTopicsTest extends LlmTestCase
{
    use ChatDatabase;

    private int $person = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['memory' => ['learn' => false], 'profile' => ['confirm' => false],
            'personas' => ['naya' => ['name' => 'Naya', 'default' => true], 'tesla' => ['name' => 'Tesla']],
            'channels' => [
                'chat'  => ['reads' => ['admin' => ['identity', 'interests', 'mood']]],
                'admin' => ['reads' => ['chat' => ['identity', 'work', 'mood']]],
            ]]]);
    }

    protected function tearDown(): void
    {
        if ($this->person > 0) {
            foreach (['assist.chat_memory', 'assist.chat_conversations', 'assist.chat_dossier'] as $table) {
                $this->db->queryBuilder()->table($table)->where('userid', $this->person)->delete();
            }
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->person)->delete();
        }
        unset($_SERVER['REQUEST_METHOD']);
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * Kept by topic; borrowed by the topics named, each marked by where it was learned; mood kept
     * where it was heard until feelings are opened; the persona's fallback not a door into Naya's;
     * the topic corrected by the person and by an administrator.
     */
    #[DataProvider('databases')]
    public function testEachChannelReadsOnlyTheTopicsItNeeds(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class,
            \Pramnos\Framework\Migrations\Llm\AddTopicToChatMemory::class], $this->db);
        $user = new \Pramnos\User\User();
        $user->username = 'topic' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->person = (int) $user->userid;
        $naya  = new Memory(new Scope($this->person));
        $ask   = new Memory(new Scope($this->person, null, Scope::ADMIN));
        $tesla = new Memory(new Scope($this->person, null, Scope::CHAT, 'tesla'));
        $naya->keepOn('mood', 'fact', 'Often feels lonely');
        $naya->keepOn('work', 'preference', 'Wants reports as tables');
        $naya->keepOn('identity', 'fact', 'Is called Maria');
        $naya->keep('fact', 'Likes the sea');
        $ask->keepOn('interests', 'fact', 'Collects old maps');
        $ask->keepOn('work', 'preference', 'Reads figures without VAT');
        $tesla->keepOn('nonsense', 'fact', 'Tesla knows a secret');
        (new Profile(new Scope($this->person)))->note('identity', 'A teacher in Athens');

        // Act
        $toAsk    = $ask->borrowed();
        $toNaya   = $naya->borrowed();
        $toTesla  = $tesla->borrowed();
        $this->configure(['chat' => ['share_feelings' => true, 'personas' => ['naya' => ['name' => 'Naya', 'default' => true]],
            'channels' => ['admin' => ['reads' => ['chat' => ['mood']]]]]]);
        $feelings = $ask->borrowed();
        $this->configure(['chat' => ['memory' => ['learn' => false], 'personas' => ['naya' => ['name' => 'Naya', 'default' => true]],
            'channels' => ['admin' => ['reads' => ['chat' => ['identity', 'work']]]]]]);
        $this->answer([[200, self::says('Ok.')]]);
        (new Conversations(new BaseAssistant()))->as(new Scope($this->person, null, Scope::ADMIN))->ask(null, 'Τι ξέρεις για μένα;');
        $prompt   = self::systemOf($this->sent[0]);
        $mine     = $naya->all();
        $sea      = array_values(array_filter($mine, static fn (array $m): bool => $m['content'] === 'Likes the sea'))[0];
        ApiFixture::$scope = new Scope($this->person);
        ApiFixture::$stop  = null;
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        ApiFixture::$input = ['topic' => 'interests'];
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);
        $amended  = $router->dispatch(\Pramnos\Http\Request::create('/chat/memory/' . $sea['memory_id'], 'PATCH'));
        $notMine  = $router->dispatch(\Pramnos\Http\Request::create('/chat/memory/999999999', 'PATCH'));
        $admin    = (new MemoryAdmin())->correctOn((object) ['userid' => 1], (int) $sea['memory_id'], 'Loves the sea', 'talk');
        $byAdmin  = (new MemoryAdmin())->of((object) ['userid' => 1], $this->person, null, Scope::CHAT);

        // Assert
        $pairs = static fn (array $list): array => array_map(static fn (array $m): string => $m['from'] . ':' . $m['topic'] . ':' . $m['content'], $list);
        $this->assertEqualsCanonicalizing(['Naya:work:Wants reports as tables', 'Naya:identity:Is called Maria', 'Naya:identity:A teacher in Athens'], $pairs($toAsk),
            'the Ask: identity and work from Naya and the profile — never her mood, never what has no topic');
        $this->assertSame(['Ask:interests:Collects old maps'], $pairs($toNaya), 'Naya: interests from the Ask, not its work');
        $this->assertSame(['Ask:interests:Collects old maps'], $pairs($toTesla), 'Tesla: Naya\'s reads of the Ask, never Naya\'s own');
        $this->assertSame(['Naya:mood:Often feels lonely'], $pairs($feelings), 'feelings only where the application opens them');
        $this->assertStringContainsString('[from Naya, identity', $prompt, 'marked by where it was learned');
        $this->assertStringContainsString('Wants reports as tables', $prompt);
        $this->assertStringNotContainsString('lonely', $prompt, 'the Ask never sees a feeling');
        $this->assertSame('other', $sea['topic'], 'kept without one: other');
        $this->assertSame('other', $tesla->all()[0]['topic'], 'a topic that is none: other');
        $this->assertSame(200, $amended['status']);
        $this->assertSame(404, $notMine['status']);
        $this->assertTrue($admin);
        $corrected = array_values(array_filter($byAdmin, static fn (array $m): bool => $m['memory_id'] === $sea['memory_id']))[0];
        $this->assertSame(['Loves the sea', 'talk'], [$corrected['content'], $corrected['topic']], 'corrected by an administrator, words and topic');
    }
}
