<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Diary;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * The assistant's diary (FW-441), on MySQL and PostgreSQL.
 *
 * What has to hold: in the evening, a page is written for a day with talk, from that day's
 * conversations and never from a work one, once; it is a journal page of a second kind — an
 * artifact titled `Diary Y-m-d`, the persona's own; the assistant remembers it when it answers;
 * the person lists the pages with what is unread, and reading one marks it.
 */
#[CoversClass(Diary::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class DiaryTest extends LlmTestCase
{
    use ChatDatabase;

    /** The person, saved. */
    private int $person = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['journal' => ['diary' => ['at' => '00:00']], 'memory' => ['learn' => false],
            'personas' => ['naya' => ['name' => 'Naya', 'default' => true], 'tesla' => ['character' => 'tesla']]]]);
    }

    protected function tearDown(): void
    {
        if ($this->person > 0) {
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->person)->delete();
        }
        ApiFixture::$scope = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A saved person. */
    private function person(): int
    {
        $user = new \Pramnos\User\User();
        $user->username = 'diary' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();

        return $this->person = (int) $user->userid;
    }

    /**
     * Written once, from the day's talk and not from work; kept as `Diary Y-m-d`, the persona's;
     * remembered when the assistant next answers; listed, read and marked through the API.
     */
    #[DataProvider('databases')]
    public function testTheEveningsPage(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = $this->person();
        $scope  = new Scope($person);
        $this->answer([
            [200, self::says('Χαίρομαι που πέρασες καλά!')],
            [200, self::says('Figures.')],
            [200, self::says('Σήμερα μου είπε ότι πέρασε όμορφα. Χάρηκα.')],
            [200, self::says('Καλησπέρα!')],
        ]);
        $assistant = new class extends BaseAssistant {
            /** The application's rules for a diary page. */
            public function diaryRules(Scope $scope): string
            {
                return 'Spell the company acme, lower case.';
            }
        };
        $chat = (new Conversations($assistant))->as($scope);
        $chat->ask(null, 'Είχα πολύ καλή μέρα σήμερα');
        $work = $chat->ask(null, 'SECRET revenue numbers');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', (int) $work['conversation_id'])->update(['tags' => '["work"]']);

        // Act
        $written = $chat->writeDiaries(time());
        $again   = $chat->writeDiaries(time());
        $chat->ask(null, 'Καλησπέρα');
        ApiFixture::$scope = $scope;
        ApiFixture::$stop  = null;
        $list    = (new ApiFixture())->diary();
        $page    = (new ApiFixture())->diary($list['data']['pages'][0]['artifact_id']);
        $after   = (new ApiFixture())->diary();
        ApiFixture::$scope = new Scope($person, null, Scope::CHAT, 'tesla');
        $hers    = (new ApiFixture())->diary();

        // Assert
        $this->assertSame(1, $written);
        $this->assertSame(0, $again, 'once a day');
        $asked = json_encode($this->sent[2]['body']['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('You are Naya, and you keep a diary', $asked);
        $this->assertStringContainsString('of them in the third', $asked, 'about the person, not to them');
        $this->assertStringContainsString('Spell the company acme, lower case.', $asked, 'the assistant\'s own rules');
        $this->assertStringContainsString('Them: Είχα πολύ καλή μέρα σήμερα', $asked);
        $this->assertStringNotContainsString('SECRET', $asked, 'never from a work conversation');
        $this->assertStringContainsString('Your own diary', json_encode($this->sent[3]['body']['messages'], JSON_UNESCAPED_UNICODE), 'remembered');
        $this->assertSame(1, $list['data']['unread']);
        $this->assertSame('Σήμερα μου είπε ότι πέρασε όμορφα. Χάρηκα.', $page['data']['text']);
        $this->assertSame(0, $after['data']['unread'], 'read');
        $this->assertSame([], $hers['data']['pages'], 'Tesla keeps her own');
        $this->assertSame(404, (new ApiFixture())->diary(999999999)['status']);
        $this->db->queryBuilder()->table('assist.chat_artifacts')->where('userid', $person)->delete();
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /** Without the diary turned on, nothing is written and the API says so. */
    public function testOffWithoutIt(): void
    {
        // Arrange
        $this->configure(['chat' => ['journal' => true]]);

        // Act / Assert
        $this->assertFalse(Diary::on());
        $this->assertSame(0, (new Conversations(new BaseAssistant()))->writeDiaries(time()));
        ApiFixture::$scope = new Scope(5);
        ApiFixture::$stop  = null;
        $this->assertSame(501, (new ApiFixture())->diary()['status']);
        $this->assertSame(Diary::AT, Diary::at());
    }
}
