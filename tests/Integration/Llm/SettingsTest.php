<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\People;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Settings;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * A person's companion settings in one place (FW-463), on MySQL and PostgreSQL.
 *
 * What has to hold: the settings read with the application's defaults filled in — its morning,
 * its own declared settings — and each persona's own; a change is all or none, and a wrong field
 * is named; the API reads and changes them; the companion changes one when asked, and the answer
 * carries what changed; resetting the experience takes them back to the defaults.
 */
#[CoversClass(Settings::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
#[CoversClass(Conversations::class)]
class SettingsTest extends LlmTestCase
{
    use ChatDatabase;

    private int $person = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['answer_in_request' => true, 'memory' => ['learn' => false],
            'today' => ['thought' => true, 'morning' => ['at' => '08:30', 'quiet' => ['22:00', '08:00']]],
            'settings' => ['place' => ['values' => ['hill', 'beach'], 'default' => 'hill', 'label' => 'Where she is']],
            'personas' => ['naya' => ['name' => 'Naya', 'default' => true], 'tesla' => ['name' => 'Tesla']]]]);
    }

    protected function tearDown(): void
    {
        if ($this->person > 0) {
            $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->person)->delete();
        }
        unset($_SERVER['REQUEST_METHOD']);
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A request through the routes. */
    private function call(string $method, string $uri, array $input = []): mixed
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        ApiFixture::$input = $input;
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);

        return $router->dispatch(\Pramnos\Http\Request::create($uri, $method));
    }

    /**
     * Read with the defaults; changed through the API, each persona's apart, with a wrong field
     * named and nothing kept; changed by asking, the answer carrying it; reset to the defaults.
     */
    #[DataProvider('databases')]
    public function testOnePlaceForThemAll(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class], $this->db);
        $user = new \Pramnos\User\User();
        $user->username = 'set' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->person      = (int) $user->userid;
        ApiFixture::$scope = new Scope($this->person);
        ApiFixture::$stop  = null;
        $this->answer([[200, self::calls([['change_setting', ['thought_at' => '11:00']]])], [200, self::says('Θα σου γράφω στις 11.')]]);

        // Act
        $before  = $this->call('GET', '/chat/settings');
        $changed = $this->call('PUT', '/chat/settings', ['quiet' => ['from' => '23:00', 'to' => '7:30'], 'notify' => ['diary' => false], 'app' => ['place' => 'beach'], 'persona' => 'tesla']);
        $wrong   = $this->call('PUT', '/chat/settings', ['notify' => ['diary' => true], 'app' => ['place' => 'moon']]);
        $kept    = $this->call('GET', '/chat/settings');
        $asked   = $this->call('POST', '/chat', ['content' => 'θέλω να μου στέλνεις στις 11']);
        $after   = $this->call('GET', '/chat/settings');
        ApiFixture::$scope = new Scope($this->person, null, Scope::CHAT, 'tesla');
        $hers    = $this->call('GET', '/chat/settings');
        $refused = [Settings::change(new \Pramnos\User\User($this->person), null, ['thought_at' => '25:00']), Settings::change(new \Pramnos\User\User($this->person), null, ['colour' => 'red']),
            Settings::change(new \Pramnos\User\User($this->person), null, ['voice' => 'nobody']), Settings::change(new \Pramnos\User\User($this->person), null, ['persona' => 'nobody'])];
        (new People())->reset($this->person, 1);
        ApiFixture::$scope = new Scope($this->person);
        $reset   = $this->call('GET', '/chat/settings');
        $message = (new Conversations(new BaseAssistant()))->as(new Scope($this->person))->find((int) $asked['data']['conversation_id'])['messages'][1];

        // Assert
        $this->assertSame(['persona' => 'naya', 'voice' => null, 'style' => null, 'thought_at' => '08:30', 'quiet' => ['from' => '22:00', 'to' => '08:00'],
            'notify' => ['thought' => true, 'diary' => true, 'reminders' => true], 'app' => ['place' => 'hill']], $before['data'], 'the application\'s defaults');
        $this->assertSame(['from' => '23:00', 'to' => '07:30'], $changed['data']['quiet']);
        $this->assertFalse($changed['data']['notify']['diary']);
        $this->assertSame('beach', $changed['data']['app']['place']);
        $this->assertSame('tesla', $changed['data']['persona']);
        $this->assertSame(422, $wrong['status']);
        $this->assertStringContainsString('app.place', json_encode($wrong, JSON_UNESCAPED_UNICODE), 'the field named');
        $this->assertFalse($kept['data']['notify']['diary'], 'all or none: the right half of a wrong change is not kept');
        $this->assertSame('11:00', $after['data']['thought_at'], 'changed by asking');
        $this->assertSame(['changed' => ['thought_at' => '11:00']], $message['settings'], 'the answer carries it');
        $this->assertSame('08:30', $hers['data']['thought_at'], 'each persona\'s own');
        $this->assertSame('hill', $hers['data']['app']['place']);
        $this->assertSame(['thought_at: a time, H:i, or "off".', 'colour: not a setting.'], array_slice($refused, 0, 2));
        $this->assertStringStartsWith('voice:', $refused[2]);
        $this->assertStringStartsWith('persona:', $refused[3]);
        $this->assertSame($before['data'], $reset['data'], 'the experience reset: every setting back to its default');
        $this->assertTrue(Settings::quietAt(['from' => '22:00', 'to' => '08:00'], '23:15'));
        $this->assertTrue(Settings::quietAt(['from' => '22:00', 'to' => '08:00'], '07:59'));
        $this->assertFalse(Settings::quietAt(['from' => '22:00', 'to' => '08:00'], '08:00'));
        $this->assertTrue(Settings::quietAt(['from' => '13:00', 'to' => '15:00'], '14:00'));
        $this->assertFalse(Settings::quietAt(null, '03:00'));
    }
}
