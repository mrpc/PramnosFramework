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
 * mount of the conversation API, Piper's HTTP server and Google Cloud Text-to-Speech (FW-408) as
 * the built-in providers, and one line in the `speech` log per call.
 *
 * An assistant read aloud where the browser has no voice in the reader's language needs a
 * server; the application names it, the framework knows how to ask it, and the log is what an
 * operator sizes that server by.
 */
#[CoversClass(Speech::class)]
#[CoversClass(Piper::class)]
#[CoversClass(\Pramnos\Llm\Speech\GoogleCloud::class)]
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
     * `chat` path named, at `/naya/speak` — the audio as base64 with its type, or itself with
     * `raw`; Piper is sent the
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
        $raw  = $this->call('/chat/speak', ['text' => 'Καλημέρα', 'raw' => true]);
        rewind($this->log);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($this->log)), static fn (string $l): bool => str_contains($l, 'speak')));

        // Assert
        $this->assertSame(200, $chat['status']);
        $this->assertSame($chat, $naya, 'the same answer on both mounts');
        $this->assertSame("RIFF\0\0WAVE", base64_decode($chat['data']['audio']));
        $this->assertSame(['audio/wav', 10], [$chat['data']['type'], $chat['data']['bytes']]);
        $this->assertSame('http://piper:5000/', $this->sent[0]['url']);
        $this->assertSame(['text' => 'Καλημέρα', 'length_scale' => 1.1, 'voice' => 'el_GR-test'], $this->sent[0]['body'], 'trimmed, with the voice and options');
        // Raw: the audio itself, with the server's type, no base64
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $raw);
        $this->assertSame("RIFF\0\0WAVE", $raw->getBody());
        $this->assertSame('audio/wav', $raw->getHeaders()['Content-Type']);
        $this->assertCount(3, $lines, 'one line per call');
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

    /**
     * Google Cloud, signed in with the application's service account: the token callable's
     * answer as the bearer, the voice's language from its name, the options in `audioConfig`
     * with MP3 by default and the project as a header — and the audio decoded from
     * `audioContent`, under its encoding's type. An API key, given or kept encrypted in its
     * setting, signs in where there is no token.
     */
    public function testGoogleCloudWithATokenOrAKey(): void
    {
        // Arrange
        $asked = 0;
        $this->configure(['speech' => ['provider' => 'google', 'providers' => ['google' => [
            'token'   => static function () use (&$asked): string { $asked++; return 'ya29.token'; },
            'voice'   => 'el-GR-Chirp3-HD-Achernar',
            'options' => ['speakingRate' => 1.1, 'project' => 'my-project'],
        ]]]]);
        Http::fake(['*' => function (Http $request): ClientResponse {
            $this->sent[] = ['url' => $request->url(), 'body' => (array) json_decode((string) $request->requestBody(), true), 'headers' => $request->requestHeaders()];

            return ClientResponse::make(['audioContent' => base64_encode('ID3mp3')]);
        }]);

        // Act
        $withToken = Speech::speak('Καλημέρα');
        $this->configure(['speech' => ['provider' => 'google', 'providers' => ['google' => ['options' => ['audioEncoding' => 'OGG_OPUS', 'languageCode' => 'el-GR']]]]]);
        $this->setting(Speech::settingKey('google'), 'AIza-stored');
        $withKey = Speech::speak('Γεια');

        // Assert — the token
        $this->assertSame(['ok' => true, 'status' => 200, 'audio' => 'ID3mp3', 'type' => 'audio/mpeg'], $withToken);
        $this->assertSame(1, $asked, 'the token asked for once, when the text is read');
        $this->assertSame('https://texttospeech.googleapis.com/v1/text:synthesize', $this->sent[0]['url']);
        $this->assertSame('Bearer ya29.token', $this->sent[0]['headers']['Authorization'] ?? null);
        $this->assertSame('my-project', $this->sent[0]['headers']['x-goog-user-project'] ?? null);
        $this->assertArrayNotHasKey('x-goog-api-key', $this->sent[0]['headers']);
        $this->assertSame(['input' => ['text' => 'Καλημέρα'], 'voice' => ['languageCode' => 'el-GR', 'name' => 'el-GR-Chirp3-HD-Achernar'],
            'audioConfig' => ['speakingRate' => 1.1, 'audioEncoding' => 'MP3']], $this->sent[0]['body']);
        // The stored key, and an encoding of its own
        $this->assertSame('audio/ogg', $withKey['type']);
        $this->assertSame('AIza-stored', $this->sent[1]['headers']['x-goog-api-key'] ?? null);
        $this->assertArrayNotHasKey('Authorization', $this->sent[1]['headers']);
        $this->assertSame(['languageCode' => 'el-GR'], $this->sent[1]['body']['voice'], 'no voice named: the language alone');
        $this->assertContains(Speech::settingKey('google'), \Pramnos\Llm\Providers::allSettingKeys(), 'never printed with the settings');
    }

    /**
     * Google Cloud with neither a token nor a key — or a stored key that cannot be decrypted — is
     * not set up (501) and asks nobody; Google's
     * own error comes back with its message (502); a token that cannot be had is a 502 with why,
     * never a fatal.
     */
    public function testGoogleCloudWithoutCredentialsOrFailing(): void
    {
        // Arrange
        Http::fake(['*' => function (): ClientResponse {
            $this->sent[] = true;

            return ClientResponse::make(['error' => ['code' => 400, 'message' => 'Voice el-GR-Nope does not exist.']], 400);
        }]);

        // Act
        $this->configure(['speech' => ['provider' => 'google']]);
        $none = Speech::speak('x');
        $this->setting(Speech::settingKey('google'), 'enc:v1:not-really');
        $undecryptable = Speech::speak('x');
        $askedNobody = count($this->sent);
        $this->configure(['speech' => ['provider' => 'google', 'providers' => ['google' => ['api_key' => static fn (): string => 'AIza', 'voice' => 'el-GR-Nope']]]]);
        $refused = Speech::speak('x');
        $this->configure(['speech' => ['provider' => 'google', 'providers' => ['google' => ['token' => static function (): string {
            throw new \RuntimeException('The service account is not set up.');
        }]]]]);
        $noToken = Speech::speak('x');

        // Assert
        $this->assertSame([501, 'Speech is not set up.'], [$none['status'], $none['says']]);
        $this->assertSame(501, $undecryptable['status'], 'a stored key that cannot be decrypted is no key');
        $this->assertSame(0, $askedNobody);
        $this->assertSame([502, 'The speech server did not answer (400): Voice el-GR-Nope does not exist.'], [$refused['status'], $refused['says']]);
        $this->assertSame([502, 'The speech server did not answer: The service account is not set up.'], [$noToken['status'], $noToken['says']]);
    }

    /**
     * A provider that fails, or is not set up, is followed once by the application's `fallback`:
     * Google refused, Piper answers, and both calls are logged. When the fallback fails too, the
     * first provider's answer is the one returned; a provider asked for by name falls back on
     * nothing.
     */
    public function testTheFallbackAnswersWhenTheFirstFails(): void
    {
        // Arrange — Google refuses everything; Piper answers
        $this->configure(['speech' => ['provider' => 'google', 'fallback' => 'piper', 'providers' => [
            'google' => ['api_key' => 'AIza'],
            'piper'  => ['base_url' => 'http://piper:5000'],
        ]]]);
        $piperUp = true;
        Http::fake(['*' => function (Http $request) use (&$piperUp): ClientResponse {
            $this->sent[] = $request->url();
            if (str_contains($request->url(), 'googleapis')) {
                return ClientResponse::make(['error' => ['message' => 'Quota exceeded.']], 429);
            }

            return $piperUp ? ClientResponse::make('ID3', 200, ['content-type' => 'audio/mpeg']) : ClientResponse::make('down', 500, ['content-type' => 'text/plain']);
        }]);

        // Act
        $rescued = Speech::speak('Καλημέρα');
        $piperUp = false;
        $bothDown = Speech::speak('Καλημέρα');
        $named = Speech::speak('Καλημέρα', 'google');
        rewind($this->log);
        $logged = substr_count((string) stream_get_contents($this->log), '"provider":');

        // Assert
        $this->assertSame(['ok' => true, 'status' => 200, 'audio' => 'ID3', 'type' => 'audio/mpeg'], $rescued, 'Piper answered for Google');
        $this->assertSame([502, 'The speech server did not answer (429): Quota exceeded.'], [$bothDown['status'], $bothDown['says']], 'the first provider\'s refusal');
        $this->assertSame(502, $named['status']);
        $this->assertCount(5, $this->sent, 'google, piper; google, piper; google alone when named');
        $this->assertSame(5, $logged, 'every call logged, the fallback\'s too');
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
