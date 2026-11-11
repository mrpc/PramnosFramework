<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Ending;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * The assistant ends a conversation in which the person keeps insulting it, on MySQL and
 * PostgreSQL.
 *
 * What has to hold: off unless the application turns it on, and only in the conversation page's
 * space; the model is told to warn first and end with a private tag; the tag never reaches the
 * person; the conversation is marked ended and refuses further questions — in the request, before
 * anything is queued — while a new conversation is open to the same person.
 */
#[CoversClass(Ending::class)]
#[CoversClass(Conversations::class)]
class EndingTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['end_on_abuse' => true]]);
    }

    protected function tearDown(): void
    {
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        ApiFixture::$queue = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The tail of what was sent: the line just before the question. */
    private static function tail(array $sent): string
    {
        return (string) $sent['body']['messages'][count($sent['body']['messages']) - 2]['content'];
    }

    /**
     * On in the conversation page only, unless spaces are named; off by default. The tag is
     * found and stripped, with spaces inside it.
     */
    public function testTheSwitchAndTheTag(): void
    {
        // Arrange
        $chat  = new Scope(1, null);
        $admin = new Scope(1, null, Scope::ADMIN);

        // Act
        $byDefault = [Ending::on($chat), Ending::on($admin)];
        $this->configure(['chat' => ['end_on_abuse' => ['spaces' => ['admin']]]]);
        $named = [Ending::on($chat), Ending::on($admin)];
        $this->configure(['chat' => []]);

        // Assert
        $this->assertSame([true, false], $byDefault, 'the conversation page only');
        $this->assertSame([false, true], $named);
        $this->assertFalse(Ending::on($chat), 'off unless turned on');
        $this->assertTrue(Ending::ends("Goodbye.\n[[ end ]]"));
        $this->assertFalse(Ending::ends('Please stop.'));
        $this->assertSame('Goodbye.', Ending::strip("Goodbye.\n[[end]]"));
        $this->assertStringContainsString('ask them once, calmly, to stop', Ending::instruction());
    }

    /**
     * The model warns, then ends: the tag is out of the answer and the stream, the answer and
     * the conversation say ended, a further question there is refused — by the conversations and,
     * through the API, with 409 before anything is queued — and a new conversation still answers.
     */
    #[DataProvider('databases')]
    public function testTheAssistantEndsTheConversation(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person   = random_int(100000, 999999);
        $streamed = [];
        $this->answer([[200, self::says('Please don\'t talk to me like that.')], [200, self::says("I'm ending this conversation. Take care.\n[[end]]")], [200, self::says('Hello again.')]]);
        $chat = (new Conversations(new BaseAssistant()))->as(new Scope($person, null))->streaming(function (string $soFar) use (&$streamed): void {
            $streamed[] = $soFar;
        });

        // Act
        $warned = $chat->ask(null, 'You are useless');
        $id     = (int) $warned['conversation_id'];
        $ended  = $chat->ask($id, 'Still useless');
        $again  = $chat->ask($id, 'Hello?');
        ApiFixture::$scope = new Scope($person, null);
        ApiFixture::$stop  = null;
        ApiFixture::$input = ['content' => 'Hello?', 'conversation_id' => $id];
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);
        $api    = $router->dispatch(\Pramnos\Http\Request::create('/chat', 'POST'));
        $fresh  = $chat->ask(null, 'Sorry. Hello.');

        // Assert
        $this->assertStringContainsString('[[end]]', self::tail($this->sent[0]), 'told how to end');
        $this->assertArrayNotHasKey('ended', $warned['message'], 'a warning does not end it');
        $this->assertSame("I'm ending this conversation. Take care.", $ended['message']['content']);
        foreach ($streamed as $piece) {
            $this->assertStringNotContainsString('[[', $piece, 'never streamed');
        }
        $this->assertTrue($ended['message']['ended']);
        $this->assertTrue($chat->find($id)['ended'], 'the conversation says so');
        $this->assertFalse($again['ok']);
        $this->assertSame('ended', $again['why']);
        $this->assertSame(Ending::SAYS, $again['says']);
        $this->assertCount(3, $this->sent, 'nothing asked of the model for an ended conversation');
        $this->assertSame(409, $api['status']);
        $this->assertSame('ended', $api['why']);
        $this->assertTrue($fresh['ok'], 'a new conversation is open to them');
        $this->assertFalse($chat->find((int) $fresh['conversation_id'])['ended']);
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }
}
