<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Http\Request;
use Pramnos\Llm\Chat\Api\ChatController;
use Pramnos\Llm\Chat\Api\Routes;
use Pramnos\Llm\Speech\Piper;
use Pramnos\Llm\Speech\Speech;
use Pramnos\Logs\Logger;
use Pramnos\Routing\Router;

/**
 * Text read aloud through the application's speech server (FW-406): `POST {chat}/speak` on every
 * mount of the conversation API, Piper's HTTP server as the built-in provider, and one line in
 * the `speech` log per call.
 *
 * An assistant read aloud where the browser has no voice in the reader's language needs a
 * server; the application names it, the framework knows how to ask it, and the log is what an
 * operator sizes that server by.
 */
#[CoversClass(Speech::class)]
#[CoversClass(Piper::class)]
#[CoversClass(ChatController::class)]
#[CoversClass(Routes::class)]
final class SpeechTest extends LlmTestCase
{
    /** @var resource */
    private $log;

    private string $previousMode = '';

    protected function setUp(): void
    {
        parent::setUp();
        // The log read from a stream: a file accumulates between runs.
        $this->previousMode = (string) (new \ReflectionProperty(Logger::class, 'outputMode'))->getValue();
        $this->log = fopen('php://memory', 'w+');
        Logger::setStreamTarget($this->log);
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        SpeakingController::$input = [];
    }

    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        (new \ReflectionProperty(Logger::class, 'outputMode'))->setValue(null, $this->previousMode);
        fclose($this->log);
        parent::tearDown();
    }

    /** Piper set up in app.php, answering with a WAV file and recording what it was sent. */
    private function piper(int $status = 200, string $audio = "RIFF\0\0WAVE"): void
    {
        $this->configure(['speech' => ['providers' => ['piper' => ['base_url' => 'http://piper:5000', 'voice' => 'el_GR-test', 'options' => ['length_scale' => 1.1]]]]]);
        Http::fake(['*' => function (Http $request) use ($status, $audio): ClientResponse {
            $this->sent[] = ['url' => $request->url(), 'body' => (array) json_decode((string) $request->requestBody(), true)];

            return ClientResponse::make($audio, $status, ['content-type' => 'audio/wav']);
        }]);
    }

    /** One request through the routes, as an application mounts them. */
    private function call(string $uri, array $input, array $paths = []): mixed
    {
        SpeakingController::$input = $input;
        $router = new Router(null);
        Routes::register($router, SpeakingController::class, $paths);

        return $router->dispatch(Request::create($uri, 'POST'));
    }

    /**
     * The same text read aloud at `/chat/speak` and, mounted again under `/naya` with only its
     * `chat` path named, at `/naya/speak` — the audio as base64 with its type; Piper is sent the
     * text, the voice and the options; and each call is one line in the speech log with the
     * text's length, the time and the audio's size.
     */
    public function testReadAloudOnEveryMount(): void
    {
        // Arrange
        $this->piper();

        // Act
        $chat = $this->call('/chat/speak', ['text' => '  Καλημέρα  ']);
        $naya = $this->call('/naya/speak', ['text' => 'Καλημέρα'], ['chat' => '/naya']);
        rewind($this->log);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($this->log)), static fn (string $l): bool => str_contains($l, 'speak')));

        // Assert
        $this->assertSame(200, $chat['status']);
        $this->assertSame($chat, $naya, 'the same answer on both mounts');
        $this->assertSame("RIFF\0\0WAVE", base64_decode($chat['data']['audio']));
        $this->assertSame(['audio/wav', 10], [$chat['data']['type'], $chat['data']['bytes']]);
        $this->assertSame('http://piper:5000/', $this->sent[0]['url']);
        $this->assertSame(['text' => 'Καλημέρα', 'length_scale' => 1.1, 'voice' => 'el_GR-test'], $this->sent[0]['body'], 'trimmed, with the voice and options');
        $this->assertCount(2, $lines, 'one line per call');
        foreach (['"chars":8', '"bytes":10', '"ms":', '"voice":"el_GR-test"', '"status":200'] as $field) {
            $this->assertStringContainsString($field, $lines[0]);
        }
    }

    /**
     * No server set up is 501, not a fatal; an empty or overlong text is 422 and asks nobody; a
     * server that fails or cannot be reached is 502 with why, and is logged with no bytes; and a
     * request the controller refuses is refused before anything is read.
     */
    public function testWhatIsNotRead(): void
    {
        // Act — nothing set up
        $none = $this->call('/chat/speak', ['text' => 'x']);
        $this->piper(500);
        $empty = $this->call('/chat/speak', ['text' => '   ']);
        $long  = $this->call('/chat/speak', ['text' => str_repeat('α', Speech::MAX_TEXT + 1)]);
        $fails = $this->call('/chat/speak', ['text' => 'x']);
        Http::fake(['*' => static function (): never {
            throw new \RuntimeException('connection refused');
        }]);
        $down = Speech::speak('x');
        SpeakingController::$stop = ['status' => 401, 'error' => 'not_authenticated'];
        $refused = $this->call('/chat/speak', ['text' => 'x']);
        SpeakingController::$stop = null;
        rewind($this->log);
        $log = (string) stream_get_contents($this->log);

        // Assert
        $this->assertSame([501, 'Speech is not set up.'], [$none['status'], $none['error']]);
        $this->assertSame([422, 422], [$empty['status'], $long['status']]);
        $this->assertCount(1, $this->sent, 'only the failing call reached the server');
        $this->assertSame([502, 'The speech server did not answer (500).'], [$fails['status'], $fails['error']]);
        $this->assertSame(['ok' => false, 'status' => 502, 'says' => 'The speech server did not answer: connection refused'], $down);
        $this->assertSame(401, $refused['status']);
        $this->assertStringContainsString('"bytes":0', $log);
        $this->assertNull(Speech::config('unknown'), 'a provider with no entry');
    }
}

/** The framework's controller, with the request's body and refusal set by the test. */
class SpeakingController extends ChatController
{
    /** @var array<string, mixed> */
    public static array $input = [];

    /** @var array<string, mixed>|null */
    public static ?array $stop = null;

    protected function refusal(string $action): ?array
    {
        return self::$stop;
    }

    protected function input(): array
    {
        return self::$input;
    }
}
