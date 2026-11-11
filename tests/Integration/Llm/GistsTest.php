<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Gists;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * What conversations were about (gists), on MySQL and PostgreSQL.
 *
 * What has to hold: a conversation's gist is written once and again only when it has new
 * messages; what a scope's conversations come to is summed up from their gists, in the reader's
 * language, narrowed to an `about`; "since last" is the reader's own, and with nothing new it is
 * said so; any scope's conversations — another partition's — are read for a reader the caller
 * lets; and the same through the API for one's own.
 */
#[CoversClass(Gists::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class GistsTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['memory' => ['learn' => false]]]);
    }

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        unset($_SERVER['REQUEST_METHOD']);
        $_GET = [];
        ApiFixture::$scope = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Somebody whose last looks can be kept. */
    private function user(): int
    {
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class, \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $mark = 'gi' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time(), 'language' => 'el']);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();

        return $id;
    }

    /**
     * A partition's conversations read by the account's holder: each gist written once, the
     * summary from them in the reader's language and about what was asked; since last, nothing
     * new said so; a new message, its gist written again; and one's own through the API.
     */
    #[DataProvider('databases')]
    public function testGistsWrittenOnceAndSummedUpSinceTheReaderLastLooked(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $userId = $this->user();
        $holder = new Scope($userId, null);
        $other  = $holder->withPartition(5);
        $this->answer([
            [200, self::says('Once upon a time…')],                       // the partition's answer
            [200, self::says('They told a story about a cat.')],           // its gist
            [200, self::says('A story was told.')],                        // the summary
            [200, self::says('And then?')],                                 // a new answer
            [200, self::says('The cat story went on.')],                   // its gist, again
            [200, self::says('The story went on.')],                       // the summary
            [200, self::says('Hi')], [200, self::says('A greeting.')], [200, self::says('You said hi.')], // one's own, through the API
        ]);
        $talk = (new Conversations(new BaseAssistant()))->as($other)->ask(null, 'Tell me a story');

        // Act
        $first   = Gists::digest($other, $holder, 'last', 'the cat');
        $asked   = (string) json_encode($this->sent[1]['body'], JSON_UNESCAPED_UNICODE) . json_encode($this->sent[2]['body'], JSON_UNESCAPED_UNICODE);
        $nothing = Gists::digest($other, $holder);
        sleep(1);
        (new Conversations(new BaseAssistant()))->as($other)->ask($talk['conversation_id'], 'Go on');
        $later   = Gists::digest($other, $holder, 'today');
        (new Conversations(new BaseAssistant()))->as($holder)->ask(null, 'Hi');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['since' => 'today'];
        ApiFixture::$scope = $holder;
        ApiFixture::$stop  = null;
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);
        $api = $router->dispatch(\Pramnos\Http\Request::create('/chat/digest', 'GET'));

        // Assert
        $this->assertSame('A story was told.', $first['summary']);
        $this->assertSame('They told a story about a cat.', $first['conversations'][0]['gist']);
        $this->assertStringContainsString('Only what touches this: the cat', $asked, 'narrowed to what was asked');
        $this->assertStringContainsString('language whose code is \"el\"', $asked, 'in the reader\'s language');
        $this->assertSame([], $nothing['conversations'], 'since last: nothing new');
        $this->assertNull($nothing['summary'], 'nothing to sum up — the caller says so in its own words');
        $this->assertTrue($nothing['since_last']);
        $this->assertSame('The cat story went on.', $later['conversations'][0]['gist'], 'a new message, its gist written again');
        $this->assertSame(200, $api['status']);
        $this->assertSame('You said hi.', $api['data']['summary'], 'one\'s own, through the API');
        $this->assertSame(['Hi'], array_column($api['data']['conversations'], 'title'), 'never another partition\'s');
    }
}
