<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Http\Request;
use Pramnos\Llm\Chat\Api\ChatController;
use Pramnos\Llm\Chat\Api\Routes;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Policy\StoredConsent;
use Pramnos\Llm\Speech\Listening;
use Pramnos\Llm\Speech\Speech;
use Pramnos\Llm\Speech\SpeechQueue;
use Pramnos\Llm\Speech\SpeechQueueTask;
use Pramnos\Queue\QueueItem;
use Pramnos\Routing\Router;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The Voice roadmap's remaining gaps (FW-522): a self-hosted speech call queued to its own
 * worker pool, the same call recorded beside the `speech` log in `llm_calls`, and a person's
 * agreement asked before a recording leaves for a provider at all — on MySQL and PostgreSQL.
 */
#[CoversClass(SpeechQueue::class)]
#[CoversClass(SpeechQueueTask::class)]
#[CoversClass(Listening::class)]
#[CoversClass(Speech::class)]
#[CoversClass(ChatController::class)]
final class SpeechQueueConsentTest extends LlmTestCase
{
    use ChatDatabase;

    protected function tearDown(): void
    {
        Listening::$queueFactory = null;
        Speech::$queueFactory    = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A queue whose addTask() runs the real worker task synchronously, as if it claimed it at once. */
    private function immediateQueue(): \Closure
    {
        return static function (): \Pramnos\Queue\QueueManager {
            return new class extends \Pramnos\Queue\QueueManager {
                public function __construct()
                {
                }

                public function addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
                {
                    $item = (new \ReflectionClass(QueueItem::class))->newInstanceWithoutConstructor();
                    $item->payload = (string) json_encode($data);
                    (new \ReflectionClass(SpeechQueueTask::class))->newInstanceWithoutConstructor()->execute($item);

                    return 1;
                }
            };
        };
    }

    /** A request through the routes, with a fixed scope. */
    private function call(string $method, string $uri, array $input, ?Scope $scope): mixed
    {
        VoiceController::$input = $input;
        VoiceController::$scope = $scope;
        $router = new Router(null);
        Routes::register($router, VoiceController::class);

        return $router->dispatch(Request::create($uri, $method));
    }

    /**
     * A worker claims a queued row, runs the real provider call, and writes its answer back —
     * the same claim-once shape as `code_exec` (FW-508); a row somebody else already claimed, or
     * one gone already, is nothing to do.
     */
    #[DataProvider('databases')]
    public function testTheWorkerClaimsRunsAndWrites(string $type, string $host, int $port): void
    {
        // Arrange — a pending listen row, as hearQueued() would leave it, for a self-hosted whisper
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]]]);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"Geia sou"}', 200)]);
        $runId = SpeechQueue::create(7, 'listen', base64_encode('OGG-BYTES'), ['type' => 'audio/ogg', 'seconds' => 2.0, 'provider' => 'whisper']);
        $task  = (new \ReflectionClass(SpeechQueueTask::class))->newInstanceWithoutConstructor();
        $item  = $this->getMockBuilder(QueueItem::class)->disableOriginalConstructor()->getMock();
        $item->payload = (string) json_encode(['run_id' => $runId, 'user_id' => 7]);

        // Act
        $result = $task->execute($item);
        $row    = SpeechQueue::find($runId, 7);

        // Assert — claimed, run for real, and the row carries the exact answer the caller reads
        $this->assertTrue($result);
        $this->assertSame('done', $row['status']);
        $this->assertSame(['ok' => true, 'status' => 200, 'text' => 'Geia sou'], json_decode((string) $row['result'], true));
        $this->assertSame('Run a queued speech call, call ' . $runId, $task->getDescription($item));

        // A second claim of the same (already done) row is a no-op, not a re-run
        $this->assertTrue($task->execute($item));

        // pending() counts only what is still waiting or running
        $this->assertSame(0, SpeechQueue::pending());
        SpeechQueue::create(9, 'speak', 'hello', []);
        $this->assertSame(1, SpeechQueue::pending());

        // A claim that succeeds but whose row is for a different person than the payload says
        // (the user_id and run_id disagree with each other) finds nothing to run — not a crash.
        $otherRunId = SpeechQueue::create(42, 'listen', base64_encode('X'), ['provider' => 'whisper']);
        $wrongUser  = $this->getMockBuilder(QueueItem::class)->disableOriginalConstructor()->getMock();
        $wrongUser->payload = (string) json_encode(['run_id' => $otherRunId, 'user_id' => 999]);
        $this->assertTrue($task->execute($wrongUser));
    }

    /**
     * A worker claims the row and finishes it before the caller's own wait gives up: `hear()` and
     * `speak()` read the real answer straight back from the row, same as the inline path would.
     */
    #[DataProvider('databases')]
    public function testAQueuedCallThatIsClaimedInTimeAnswersFromTheRow(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'queue' => true, 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]],
            'speech' => ['provider' => 'piper', 'queue' => true, 'providers' => ['piper' => ['base_url' => 'http://piper:5000']]]]);
        Listening::$queueFactory = $this->immediateQueue();
        Speech::$queueFactory    = $this->immediateQueue();

        // Act
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"queued text"}', 200)]);
        $heard = Listening::hear('OGG', 'audio/ogg', 1.0, null, 7);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make("RIFF\0\0WAVE", 200)]);
        $spoken = Speech::speak('Hello', null, 'a-custom-voice', 9);

        // Assert — the answer came from the row a worker wrote, nothing is left pending, and the
        // voice asked for (not registered under voices()) reached the provider unchanged
        $this->assertSame(['ok' => true, 'status' => 200, 'text' => 'queued text'], $heard);
        $this->assertSame(['ok' => true, 'status' => 200, 'audio' => "RIFF\0\0WAVE", 'type' => 'audio/wav'], $spoken);
        $this->assertSame(0, SpeechQueue::pending());
    }

    /** A worker that fails the call writes why; the caller reads that back as a 502, not a crash. */
    #[DataProvider('databases')]
    public function testAQueuedCallTheWorkerFailsIsA502(string $type, string $host, int $port): void
    {
        // Arrange — the provider itself answers an error, which the worker turns into a failed row
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'queue' => true, 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]],
            'speech' => ['provider' => 'piper', 'queue' => true, 'providers' => ['piper' => ['base_url' => 'http://piper:5000']]]]);
        $failingQueue = static function (): \Pramnos\Queue\QueueManager {
            return new class extends \Pramnos\Queue\QueueManager {
                public function __construct()
                {
                }

                public function addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
                {
                    $runId = (int) $data['run_id'];
                    SpeechQueue::claim($runId, 'worker-test');
                    SpeechQueue::fail($runId, 'the sandbox exploded');

                    return 1;
                }
            };
        };
        Listening::$queueFactory = $failingQueue;
        Speech::$queueFactory    = $failingQueue;

        // Act
        $heard  = Listening::hear('OGG', 'audio/ogg', 1.0, null, 7);
        $spoken = Speech::speak('Hello', null, null, 9);

        // Assert
        $this->assertSame(['ok' => false, 'status' => 502, 'says' => 'the sandbox exploded'], $heard);
        $this->assertSame(['ok' => false, 'status' => 502, 'says' => 'the sandbox exploded'], $spoken);
    }

    /** Already too many calls waiting or running: a new one never even tries to queue. */
    #[DataProvider('databases')]
    public function testTooManyAlreadyPendingSkipsQueueingEntirely(string $type, string $host, int $port): void
    {
        // Arrange — the circuit breaker's own ceiling, already reached
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'queue' => true, 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]],
            'speech' => ['provider' => 'piper', 'queue' => true, 'providers' => ['piper' => ['base_url' => 'http://piper:5000']]]]);
        for ($i = 0; $i < Listening::QUEUE_MAX_PENDING; $i++) {
            SpeechQueue::create(1, 'listen', 'x', []);
        }
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"straight through"}', 200)]);

        // Act
        $heard = Listening::hear('OGG', 'audio/ogg', 1.0, null, 7);

        // Assert — answered without a new row ever being added
        $this->assertSame(['ok' => true, 'status' => 200, 'text' => 'straight through'], $heard);
        $this->assertSame(Listening::QUEUE_MAX_PENDING, SpeechQueue::pending(), 'nothing new was queued');

        // The same ceiling on the speak side, counted across both kinds together
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make("RIFF\0\0WAVE", 200)]);
        $spoken = Speech::speak('Hello', null, null, 9);
        $this->assertSame(['ok' => true, 'status' => 200, 'audio' => "RIFF\0\0WAVE", 'type' => 'audio/wav'], $spoken);
        $this->assertSame(Listening::QUEUE_MAX_PENDING, SpeechQueue::pending());
    }

    /**
     * Queued, claimed by nobody before the wait runs out: `hear()` falls back to running inline
     * rather than failing the call, having actually waited the full `QUEUE_WAIT_SECONDS`.
     */
    #[DataProvider('databases')]
    public function testAQueuedCallThatTimesOutFallsBackInline(string $type, string $host, int $port): void
    {
        // Arrange — the queue accepts the task but nothing ever claims it
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'queue' => true, 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]],
            'speech' => ['provider' => 'piper', 'queue' => true, 'providers' => ['piper' => ['base_url' => 'http://piper:5000']]]]);
        $neverClaims = static function (): \Pramnos\Queue\QueueManager {
            return new class extends \Pramnos\Queue\QueueManager {
                public function __construct()
                {
                }

                public function addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
                {
                    return 1; // accepted, never claimed
                }
            };
        };
        Listening::$queueFactory = $neverClaims;
        Speech::$queueFactory    = $neverClaims;

        // Act
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"inline after timeout"}', 200)]);
        $heard = Listening::hear('OGG', 'audio/ogg', 1.0, null, 7);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make("RIFF\0\0WAVE", 200)]);
        $spoken = Speech::speak('Hello', null, null, 9);

        // Assert
        $this->assertSame(['ok' => true, 'status' => 200, 'text' => 'inline after timeout'], $heard);
        $this->assertSame(['ok' => true, 'status' => 200, 'audio' => "RIFF\0\0WAVE", 'type' => 'audio/wav'], $spoken);
        $this->assertSame(0, SpeechQueue::pending(), 'both abandoned rows were forgotten once they fell back');
    }

    /**
     * Queueing is turned on and the provider is self-hosted, but the real queue cannot be
     * reached at all (no application context here to resolve a worker queue through) —
     * `hear()`/`speak()` fall back to running inline rather than failing the call: queueing is
     * only ever a performance choice.
     */
    #[DataProvider('databases')]
    public function testAnUnreachableQueueFallsBackInline(string $type, string $host, int $port): void
    {
        // Arrange — queueing on, but nothing here to actually dispatch a task through
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'queue' => true, 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]],
            'speech' => ['provider' => 'piper', 'queue' => true, 'providers' => ['piper' => ['base_url' => 'http://piper:5000']]]]);

        // Act
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"inline"}', 200)]);
        $heard = Listening::hear('OGG', 'audio/ogg', 1.0, null, 7);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make("RIFF\0\0WAVE", 200)]);
        $spoken = Speech::speak('Hello', null, null, 9);

        // Assert — still a correct answer, from the inline path, and nothing left pending
        $this->assertSame(['ok' => true, 'status' => 200, 'text' => 'inline'], $heard);
        $this->assertSame(['ok' => true, 'status' => 200, 'audio' => "RIFF\0\0WAVE", 'type' => 'audio/wav'], $spoken);
        $this->assertSame(0, SpeechQueue::pending(), 'the abandoned rows were forgotten once they fell back');
    }

    /** A hosted provider (Google, OpenAI) is never queued — it has nothing of its own to protect. */
    #[DataProvider('databases')]
    public function testAHostedProviderIsNeverQueued(string $type, string $host, int $port): void
    {
        // Arrange — whisper left at its built-in base_url (api.openai.com): cloud, not self-hosted
        $this->connectDatabase($type, $host, $port);
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['listen' => ['provider' => 'whisper', 'queue' => true]]);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"cloud"}', 200)]);

        // Act
        $heard = Listening::hear('OGG', 'audio/ogg', 1.0, null, 7);

        // Assert — answered directly, nothing ever queued
        $this->assertSame(['ok' => true, 'status' => 200, 'text' => 'cloud'], $heard);
        $this->assertSame(0, SpeechQueue::pending());
    }

    /** Beside the `speech` log: the same listen/speak call also lands in `llm_calls` (FW-522). */
    #[DataProvider('databases')]
    public function testTheCallAlsoLandsInTheCallLog(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]],
            'speech' => ['provider' => 'piper', 'providers' => ['piper' => ['base_url' => 'http://piper:5000']]]]);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"hi"}', 200)]);

        // Act
        Listening::hear('OGG', 'audio/ogg', 1.0, null, 7);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make("RIFF\0\0WAVE", 200)]);
        Speech::speak('Hello', null, null, 7);

        // Assert — one llm_calls row for each, with the right purpose and provider
        $rows = $this->rows('llm_calls');
        $this->assertCount(2, $rows);
        $this->assertSame(['listen', 'speak'], array_column($rows, 'purpose'));
        $this->assertSame(['whisper', 'piper'], array_column($rows, 'provider'));
        $this->assertSame([200, 200], array_column($rows, 'http_status'));
    }

    /**
     * A recording needs agreement first when the listening provider asks for it — the same
     * stored-consent table a model provider uses, under its own `listen:<provider>` key so the
     * two never collide.
     */
    #[DataProvider('databases')]
    public function testAudioConsentGatesListening(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['listen' => ['provider' => 'whisper', 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000', 'requires_consent' => true]]]]);
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('{"text":"hi"}', 200)]);
        $scope = new Scope(7, 3);

        // Act / Assert — refused before agreeing
        $state = $this->call('GET', '/chat/audio-consent', [], $scope);
        $this->assertSame(['required' => true, 'given' => false], $state['data']);
        $refused = $this->call('POST', '/chat/listen', ['audio' => base64_encode('x')], $scope);
        $this->assertSame(428, $refused['status']);
        $this->assertStringContainsString('agreement', $refused['error']);

        // Agree: the row lands in ai_consents under 'listen:whisper', and the call now goes through
        $given = $this->call('PUT', '/chat/audio-consent', [], $scope);
        $this->assertSame(['required' => true, 'given' => true], $given['data']);
        $this->assertSame('listen:whisper', $this->rows('ai_consents')[0]['provider']);
        $heard = $this->call('POST', '/chat/listen', ['audio' => base64_encode('x')], $scope);
        $this->assertSame(['status' => 200, 'data' => ['text' => 'hi']], $heard);

        // Another person has not agreed
        $this->assertSame(428, $this->call('POST', '/chat/listen', ['audio' => base64_encode('x')], new Scope(8, 3))['status']);

        // Withdraw: refused again
        $withdrawn = $this->call('DELETE', '/chat/audio-consent', [], $scope);
        $this->assertSame(['required' => true, 'given' => false], $withdrawn['data']);
        $this->assertSame([], $this->rows('ai_consents'));

        // A provider that does not ask for it is never gated
        $this->configure(['listen' => ['provider' => 'whisper', 'providers' => ['whisper' => ['base_url' => 'http://whisper:8000']]]]);
        $this->assertSame(['required' => false, 'given' => false], $this->call('GET', '/chat/audio-consent', [], $scope)['data']);
        $this->assertSame(200, $this->call('POST', '/chat/listen', ['audio' => base64_encode('x')], $scope)['status']);
    }

    /** Signed out: nothing to agree as, and the consent endpoints say so rather than guessing. */
    public function testAudioConsentNeedsSomebodySignedIn(): void
    {
        $this->assertSame(['status' => 403, 'error' => 'This needs somebody signed in.'], $this->call('PUT', '/chat/audio-consent', [], null));
    }

    /** Not allowed at all (the permission store's own guard): the audio-consent endpoints say so, same as any other action. */
    #[DataProvider('databases')]
    public function testAudioConsentIsGuardedLikeAnyOtherAction(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        VoiceController::$stop = ['status' => 403, 'error' => 'Not on this plan.'];

        try {
            // Act / Assert
            $this->assertSame(VoiceController::$stop, $this->call('GET', '/chat/audio-consent', [], new Scope(7, 3)));
            $this->assertSame(VoiceController::$stop, $this->call('PUT', '/chat/audio-consent', [], new Scope(7, 3)));
        } finally {
            VoiceController::$stop = null;
        }
    }

    /** Agreeing with no listening provider set up at all is refused, not a crash. */
    public function testGivingConsentWithNoProviderSetUpIsRefused(): void
    {
        // Arrange — the default provider (google) has no credentials anywhere: config() is null
        $this->configure(['listen' => ['provider' => 'google']]);

        // Act / Assert
        $this->assertSame(['status' => 422, 'error' => 'There is no listening provider set up.'], $this->call('PUT', '/chat/audio-consent', [], new Scope(7, 3)));
    }
}

/** A controller with a scope fixed by the test, like the chat API's own fixtures elsewhere. */
final class VoiceController extends ChatController
{
    /** @var array<string, mixed> */
    public static array $input = [];

    public static ?Scope $scope = null;

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

    protected function scope(): ?Scope
    {
        return self::$scope;
    }
}
