<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Http\Request;
use Pramnos\Llm\Chat\Api\Routes;
use Pramnos\Llm\Speech\GoogleListen;
use Pramnos\Llm\Speech\Listening;
use Pramnos\Llm\Speech\WhisperListen;
use Pramnos\Logs\Logger;
use Pramnos\Routing\Router;

require_once __DIR__ . '/SpeechTest.php';

/**
 * Speech recognised by a server (FW-437): `POST {chat}/listen`, Google Cloud Speech-to-Text with
 * the voice's own credentials, an OpenAI-compatible Whisper server, and one `listen` line in the
 * `speech` log with what it cost.
 *
 * Dictation where the browser has none — Firefox, an installed app on an iPhone — needs a server;
 * what has to hold is that the recording reaches it in the shape it reads, the words come back,
 * and a missing or failing server says so rather than answering nothing.
 */
#[CoversClass(Listening::class)]
#[CoversClass(GoogleListen::class)]
#[CoversClass(WhisperListen::class)]
final class ListeningTest extends LlmTestCase
{
    /** @var resource */
    private $log;

    private string $previousMode = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousMode = (string) (new \ReflectionProperty(Logger::class, 'outputMode'))->getValue();
        $this->log = fopen('php://memory', 'w+');
        Logger::setStreamTarget($this->log);
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        SpeakingController::$input = [];
        SpeakingController::$stop  = null;
    }

    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        (new \ReflectionProperty(Logger::class, 'outputMode'))->setValue(null, $this->previousMode);
        fclose($this->log);
        parent::tearDown();
    }

    /** What the log holds. */
    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    /** A request through the routes, as an application mounts them. */
    private function call(array $input): mixed
    {
        SpeakingController::$input = $input;
        $router = new Router(null);
        Routes::register($router, SpeakingController::class);

        return $router->dispatch(Request::create('/chat/listen', 'POST'));
    }

    /**
     * Google, signed in with the voice's own service account: WebM Opus at 48 kHz in Greek, the
     * recording base64 in the body; its results joined; the log with seconds and cost.
     */
    public function testGoogleWithTheVoicesCredentials(): void
    {
        // Arrange
        $this->configure(['speech' => ['providers' => ['google' => ['token' => static fn (): string => 'tok-1']]], 'listen' => ['provider' => 'google']]);
        $sent = [];
        Http::fake(['*' => static function (Http $request) use (&$sent): ClientResponse {
            $sent[] = ['url' => $request->url(), 'body' => (array) json_decode((string) $request->requestBody(), true), 'auth' => $request->requestHeaders()['Authorization'] ?? ''];

            return ClientResponse::make((string) json_encode(['results' => [['alternatives' => [['transcript' => 'Καλημέρα']]], ['alternatives' => [['transcript' => 'τι κάνεις;']]]]]), 200);
        }]);

        // Act
        $answer = $this->call(['audio' => base64_encode('OPUS-BYTES'), 'type' => 'audio/webm;codecs=opus', 'seconds' => 30]);

        // Assert
        $this->assertSame(['status' => 200, 'data' => ['text' => 'Καλημέρα τι κάνεις;']], $answer);
        $this->assertSame('https://speech.googleapis.com/v1/speech:recognize', $sent[0]['url']);
        $this->assertSame('Bearer tok-1', $sent[0]['auth'], 'the voice\'s own service account');
        $this->assertSame(['languageCode' => 'el-GR', 'encoding' => 'WEBM_OPUS', 'sampleRateHertz' => 48000, 'enableAutomaticPunctuation' => true], $sent[0]['body']['config']);
        $this->assertSame(base64_encode('OPUS-BYTES'), $sent[0]['body']['audio']['content']);
        $this->assertStringContainsString('"cost":0.008', $this->logged(), 'half a minute at 0.016 a minute');
    }

    /** Whisper: the recording as a file, with the model and two-letter language; its text back. */
    public function testWhisperTakesAFile(): void
    {
        // Arrange
        $this->configure(['listen' => ['provider' => 'whisper', 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000', 'model' => 'small']]]]);
        $parts = [];
        Http::fake(['*' => static function (Http $request) use (&$parts): ClientResponse {
            $parts = $request->multipartParts();

            return ClientResponse::make('{"text":" Γεια σου "}', 200);
        }]);

        // Act
        $heard = Listening::hear('OGG', 'audio/ogg');

        // Assert
        $this->assertSame(['ok' => true, 'status' => 200, 'text' => 'Γεια σου'], $heard);
        $this->assertSame(['file', 'model', 'language', 'response_format'], array_column($parts, 'name'));
        $this->assertSame('speech.ogg', $parts[0]['filename']);
        $this->assertSame('small', $parts[1]['contents']);
        $this->assertSame('el', $parts[2]['contents']);
    }

    /**
     * Nothing set up is 501; an empty recording 422; a failing server 502 with its reason — and
     * Google with no credentials of its own nor the voice's is not set up.
     */
    public function testWhatFailsSaysSo(): void
    {
        // Arrange
        $this->configure(['listen' => ['provider' => 'google']]);

        // Act
        $none  = $this->call(['audio' => base64_encode('x')]);
        $empty = Listening::hear('');
        $this->configure(['listen' => ['provider' => 'whisper', 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]]]);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"error":{"message":"bad audio"}}', 400)]);
        $broken = Listening::hear('x', 'audio/webm');

        // Assert
        $this->assertSame(501, $none['status']);
        $this->assertNull(Listening::config('google'), 'no credentials: not set up');
        $this->assertSame(422, $empty['status']);
        $this->assertSame(502, $broken['status']);
        $this->assertStringContainsString('bad audio', $broken['says']);
        $this->assertNull(Listening::config('nowhere'));
    }
}
