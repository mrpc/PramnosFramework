<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Speech\Speech;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * The person's own voice (FW-443), on MySQL and PostgreSQL.
 *
 * What has to hold: `GET {chat}/voices` lists the application's voices with their samples and the
 * person's choice; `PUT {chat}/voice` keeps one for this persona only, refuses one not on the
 * list, and `""` goes back; `POST {chat}/speak` reads in the person's voice, else the persona's,
 * and `voice` reads a sample in any listed one — with that voice's own provider.
 */
#[CoversClass(Speech::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\Routes::class)]
class VoicesTest extends LlmTestCase
{
    use ChatDatabase;

    private int $person = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(['chat' => ['personas' => ['naya' => ['name' => 'Naya', 'default' => true, 'voice' => 'el_GR-naya'], 'tesla' => ['name' => 'Tesla']]],
            'speech' => ['sample' => 'Γεια σου!', 'providers' => ['piper' => ['base_url' => 'http://piper:5000', 'voice' => 'el_GR-default'],
                'google' => ['api_key' => 'AIza']],
                'voices' => ['maria' => ['label' => 'Μαρία', 'voice' => 'el_GR-maria', 'sample' => '/audio/maria.mp3'],
                    'cloud' => ['label' => 'Cloud', 'voice' => 'el-GR-Wavenet-A', 'provider' => 'google'], 'broken' => ['label' => 'No name']]]]);
        Http::fake(['*' => function (Http $request): ClientResponse {
            $this->sent[] = ['url' => $request->url(), 'body' => (array) json_decode((string) $request->requestBody(), true)];

            return str_contains($request->url(), 'googleapis')
                ? ClientResponse::make(['audioContent' => base64_encode('ID3')], 200)
                : ClientResponse::make("RIFF\0\0WAVE", 200, ['content-type' => 'audio/wav']);
        }]);
    }

    protected function tearDown(): void
    {
        if ($this->person > 0) {
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

    /** The voice each request was read in, in order. */
    private function voicesSent(): array
    {
        return array_map(static fn (array $one): string => (string) ($one['body']['voice']['name'] ?? $one['body']['voice'] ?? ''), $this->sent);
    }

    /**
     * Listed with samples, chosen for Naya only, refused off the list, cleared; read in the
     * person's, then the persona's, and a sample in the cloud's voice through Google.
     */
    #[DataProvider('databases')]
    public function testThePersonsOwnVoice(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = new \Pramnos\User\User();
        $user->username = 'voice' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->person      = (int) $user->userid;
        ApiFixture::$scope = new Scope($this->person, null, Scope::CHAT, 'naya');
        ApiFixture::$stop  = null;

        // Act
        $before  = $this->call('GET', '/chat/voices');
        $this->call('POST', '/chat/speak', ['text' => 'Ένα']);
        $chosen  = $this->call('PUT', '/chat/voice', ['voice' => 'maria']);
        $this->call('POST', '/chat/speak', ['text' => 'Δύο']);
        $this->call('POST', '/chat/speak', ['text' => 'Τρία', 'voice' => 'cloud']);
        $refused = $this->call('PUT', '/chat/voice', ['voice' => 'broken']);
        ApiFixture::$scope = new Scope($this->person, null, Scope::CHAT, 'tesla');
        $hers    = $this->call('GET', '/chat/voices');
        $this->call('POST', '/chat/speak', ['text' => 'Τέσσερα']);
        ApiFixture::$scope = new Scope($this->person, null, Scope::CHAT, 'naya');
        $cleared = $this->call('PUT', '/chat/voice', ['voice' => '']);
        $this->call('POST', '/chat/speak', ['text' => 'Πέντε']);

        // Assert
        $this->assertSame([['key' => 'maria', 'label' => 'Μαρία', 'sample' => '/audio/maria.mp3'], ['key' => 'cloud', 'label' => 'Cloud', 'sample' => null]],
            $before['data']['voices'], 'a voice without the provider\'s name for it is left out');
        $this->assertNull($before['data']['chosen']);
        $this->assertSame('Γεια σου!', $before['data']['sample_text']);
        $this->assertSame('maria', $chosen['data']['chosen']);
        $this->assertSame(422, $refused['status']);
        $this->assertNull($hers['data']['chosen'], 'Tesla has a voice of her own choosing');
        $this->assertNull($cleared['data']['chosen']);
        $this->assertSame(['el_GR-naya', 'el_GR-maria', 'el-GR-Wavenet-A', 'el_GR-default', 'el_GR-naya'], $this->voicesSent(),
            'the persona\'s; the person\'s; a sample; Tesla\'s provider default; the persona\'s again');
        $this->assertStringContainsString('googleapis', $this->sent[2]['url'], 'the cloud voice through its own provider');
    }

    /** Nobody signed in has no voice to choose. */
    public function testNeedsSomebody(): void
    {
        // Arrange
        ApiFixture::$scope = new Scope(0);
        ApiFixture::$stop  = null;

        // Act / Assert
        $this->assertSame(401, $this->call('GET', '/chat/voices')['status']);
    }
}
