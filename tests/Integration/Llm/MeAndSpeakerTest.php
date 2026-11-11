<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * Who the person is to the assistant, and who is speaking on a shared account (FW-442), on MySQL
 * and PostgreSQL.
 *
 * What has to hold: `GET {chat}/me` gives the person's name and their first meeting with this
 * persona (none before any); a question may say who is speaking — the model is told, the question
 * keeps it in the history — and a speaker that is not one is ignored.
 */
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
#[CoversClass(\Pramnos\Llm\Chat\ContextAssembler::class)]
class MeAndSpeakerTest extends LlmTestCase
{
    use ChatDatabase;

    private int $person = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['answer_in_request' => true]]);
    }

    protected function tearDown(): void
    {
        if ($this->person > 0) {
            $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->person)->delete();
        }
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A request through the routes. */
    private function call(string $method, string $uri, array $input = []): mixed
    {
        ApiFixture::$input = $input;
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);

        return $router->dispatch(\Pramnos\Http\Request::create($uri, $method));
    }

    /**
     * Before any conversation there is no first meeting; after the first, its start. A child
     * speaking is told to the model and kept with the question; a speaker that is not one is not.
     */
    #[DataProvider('databases')]
    public function testMeAndWhoIsSpeaking(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = new \Pramnos\User\User();
        $user->username  = 'me' . random_int(100000, 999999);
        $user->firstname = 'Μαρία';
        $user->email     = $user->username . '@example.test';
        $user->save();
        $this->person      = (int) $user->userid;
        ApiFixture::$scope = new Scope($this->person);
        ApiFixture::$stop  = null;
        $this->answer([[200, self::says('Γεια σου Ελένη!')], [200, self::says('Ok.')]]);

        // Act
        $before = $this->call('GET', '/chat/me');
        $asked  = $this->call('POST', '/chat', ['content' => 'Γεια', 'speaker' => ['kind' => 'child', 'name' => 'Ελένη']]);
        $after  = $this->call('GET', '/chat/me');
        $this->call('POST', '/chat', ['content' => 'Hi', 'speaker' => ['kind' => '<script>']]);
        $history = (new \Pramnos\Llm\Chat\Conversations(new \Pramnos\Llm\Chat\BaseAssistant()))->as(new Scope($this->person))
            ->find((int) $asked['data']['conversation_id'])['messages'];

        // Assert
        $this->assertSame('Μαρία', $before['data']['name']);
        $this->assertNull($before['data']['first_meeting'], 'not met yet');
        $this->assertNotNull($after['data']['first_meeting']);
        $tail = json_encode($this->sent[0]['body']['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Speaking now on this account: child (Ελένη)', $tail);
        $this->assertSame(['kind' => 'child', 'name' => 'Ελένη'], $history[0]['speaker'], 'kept with the question');
        $this->assertStringNotContainsString('Speaking now', json_encode($this->sent[1]['body']['messages'], JSON_UNESCAPED_UNICODE), 'not a speaker');
    }
}
