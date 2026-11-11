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
        $this->configure(['chat' => ['memory' => ['learn' => false], 'gists' => [
            // The application's words, naming whose conversations they are and who reads them.
            'prompt' => static fn (Scope $of, Scope $reader): string => 'Gist of partition ' . ($of->partition ?? 0) . ' for reader ' . $reader->userId . '.',
        ]]]);
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
            [200, self::says('The cat story went on.')],                   // its gist, again — one gist is its own summary
            [200, self::says('Hi')], [200, self::says('A greeting.')],     // one's own, through the API
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
        $this->assertStringContainsString('Gist of partition 5 for reader ' . $userId, $asked, 'the application\'s words, told whose and for whom');
        $this->assertStringContainsString('language whose code is \"el\"', $asked, 'in the reader\'s language');
        $this->assertSame([], $nothing['conversations'], 'since last: nothing new');
        $this->assertNull($nothing['summary'], 'nothing to sum up — the caller says so in its own words');
        $this->assertTrue($nothing['since_last']);
        $this->assertSame('The cat story went on.', $later['conversations'][0]['gist'], 'a new message, its gist written again');
        $this->assertSame('The cat story went on.', $later['summary'], 'one gist: its own summary, no call to say it again');
        $this->assertSame(200, $api['status']);
        $this->assertSame('A greeting.', $api['data']['summary'], 'one\'s own, through the API');
        $this->assertSame(7, count($this->sent), 'no summary called for a single gist: seven calls, not nine');
        $this->assertSame(['Hi'], array_column($api['data']['conversations'], 'title'), 'never another partition\'s');
    }

    /**
     * After every answer, the application's `chat.after` hook and the `llm.chat.answered` event
     * hear the scope, the question, the answer and the conversation; one that fails is logged and
     * stops neither the other nor the answer.
     */
    #[DataProvider('databases')]
    public function testEveryAnswerIsHeardAfterIt(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $heard = [];
        $this->configure(['chat' => ['memory' => ['learn' => false], 'after' => static function (Scope $s, string $q, string $a, int $c) use (&$heard): void {
            $heard[] = [$s->stored(), $q, $a];
            throw new \RuntimeException('a hook that fails');
        }]]);
        $events = [];
        \Pramnos\Event\Event::listen('llm.chat.answered', static function (Scope $s, string $q, string $a, int $c) use (&$events): void {
            $events[] = [$q, $a];
        });
        $this->answer([[200, self::says('Hello there')]]);

        // Act
        $answer = (new Conversations(new BaseAssistant()))->as((new Scope(7, null))->withPartition(3))->ask(null, 'Hi');
        \Pramnos\Event\Event::forget('llm.chat.answered');

        // Assert
        $this->assertTrue($answer['ok'], 'a hook that fails never breaks the answer');
        $this->assertSame([['chat@3', 'Hi', 'Hello there']], $heard, 'the scope, the question, the answer');
        $this->assertSame([['Hi', 'Hello there']], $events, 'the event too, though the hook failed');
    }
}
