<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Http\Request;
use Pramnos\Llm\Chat\Api\ChatController;
use Pramnos\Llm\Chat\Api\Routes;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Routing\Router;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The conversation API, through its routes, on MySQL and PostgreSQL.
 *
 * Every endpoint is reached the way an application reaches it — the router, one line of
 * registration — and the decisions an application makes for itself are its subclass's.
 */
#[CoversClass(ChatController::class)]
#[CoversClass(Routes::class)]
#[CoversClass(\Pramnos\Llm\Chat\Attachments::class)]
#[CoversClass(\Pramnos\Llm\Chat\Conversations::class)]
#[CoversClass(\Pramnos\Llm\Chat\Concerns\TakesQuestions::class)]
class ChatApiTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        ApiFixture::$scope   = new Scope(7, 3);
        ApiFixture::$input   = [];
        ApiFixture::$stop    = null;
        ApiFixture::$charged = [];
        ApiFixture::$queue   = null;
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** One request through the routes, as an application mounts them. */
    private function call(string $method, string $uri, array $input = [], array $paths = []): mixed
    {
        ApiFixture::$input = $input;
        $router = new Router(null);
        Routes::register($router, ApiFixture::class, $paths);

        return $router->dispatch(Request::create($uri, $method));
    }

    /**
     * A question is queued and followed, then read, listed with the limits, and forgotten —
     * through the routes, with the application's hooks consulted.
     */
    #[DataProvider('databases')]
    public function testAQuestionFromAskingToForgetting(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('Fine.')]]);

        // Act — asked, then answered by the worker
        $queued = $this->call('POST', '/chat', ['content' => 'How is it?', 'draft' => 4]);
        $askId  = $queued['data']['ask_id'];
        (new \Pramnos\Llm\Chat\Asks((new ApiFixture())->exposeConversations()))->run(ApiFixture::$queue->tasks[0][1]);
        $asked  = $this->call('GET', '/chat/asks/' . $askId);
        $id     = $asked['data']['conversation_id'];

        // Assert
        $this->assertSame(202, $queued['status']);
        $this->assertSame([7], ApiFixture::$charged, 'beforeAsk ran');
        $this->assertSame(['draft' => 4], ApiFixture::$queue->tasks[0][1]['extra'], 'extra() travelled with it');
        $this->assertSame('answered', $asked['data']['status']);
        $list = $this->call('GET', '/chat');
        $this->assertSame([$id], array_column($list['data']['conversations'], 'conversation_id'));
        $this->assertSame('OpenAI', $list['data']['limits']['provider']);
        $this->assertSame('ours', $list['data']['limits']['month'], 'limits() decorated');
        $this->assertSame('Fine.', $this->call('GET', '/chat/' . $id)['data']['messages'][1]['content']);
        $this->assertSame(['deleted' => true], $this->call('DELETE', '/chat/' . $id)['data']);
        $this->assertSame(404, $this->call('GET', '/chat/' . $id)['status']);
        $this->assertSame(404, $this->call('DELETE', '/chat/' . $id)['status']);
        $this->assertSame(404, $this->call('GET', '/chat/asks/999')['status']);
    }

    /**
     * Answered in the request when the application has no worker; refusals keep their status.
     */
    #[DataProvider('databases')]
    public function testAnsweredInTheRequest(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['answer_in_request' => true]]);
        $this->answer([[200, self::says('Now.')]]);

        // Act
        $answered = $this->call('POST', '/chat', ['content' => 'Now?']);
        $this->answer([[500, []]]);
        $failed = $this->call('POST', '/chat', ['content' => 'Again?']);

        // Assert
        $this->assertSame(200, $answered['status']);
        $this->assertSame('Now.', $answered['data']['message']['content']);
        $this->assertSame([502, 'provider'], [$failed['status'], $failed['why']]);
    }

    /**
     * What stops a question before anything is queued: the hook, nobody signed in, no
     * connection, nothing asked, a conversation that is not theirs, consent, the application's
     * own limit.
     */
    #[DataProvider('databases')]
    public function testWhatStopsAQuestion(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);

        // Act / Assert
        ApiFixture::$stop = ['status' => 402, 'error' => 'Not on this plan.'];
        $this->assertSame(402, $this->call('POST', '/chat', ['content' => 'x'])['status']);
        ApiFixture::$stop = null;
        ApiFixture::$scope = null;
        $this->assertSame(403, $this->call('POST', '/chat', ['content' => 'x'])['status']);
        ApiFixture::$scope = new Scope(7, 3);
        $this->assertSame(422, $this->call('POST', '/chat', ['content' => '  '])['status']);
        $this->assertSame(404, $this->call('POST', '/chat', ['content' => 'x', 'conversation_id' => 999])['status']);
        $this->assertSame([409, 'Over the limit.'], array_values(array_intersect_key($this->call('POST', '/chat', ['content' => 'limit'])
            , ['status' => 1, 'error' => 1])));
        $this->setting('llm_openai_api_key', '');
        $this->assertSame(503, $this->call('POST', '/chat', ['content' => 'x'])['status']);

        // Consent, for a provider that asks for it
        $this->setting('llm_provider', 'deepseek');
        $this->setting('llm_deepseek_api_key', 'sk-d');
        \Pramnos\Llm\Llm::use('consent', new \Pramnos\Llm\Policy\StoredConsent());
        $refused = $this->call('POST', '/chat', ['content' => 'x']);
        $this->assertSame([403, true, 'deepseek'], [$refused['status'], $refused['consent_required'], $refused['provider']]);
        $state = $this->call('GET', '/chat/consent')['data'];
        $this->assertSame(['deepseek', 'DeepSeek', true, false], [$state['provider'], $state['label'], $state['required'], $state['given']]);
        $this->assertTrue($this->call('POST', '/chat/consent')['data']['given']);
        $this->assertSame(202, $this->call('POST', '/chat', ['content' => 'x'])['status']);
        $this->assertFalse($this->call('DELETE', '/chat/consent')['data']['given']);
        ApiFixture::$scope = null;
        $this->assertSame(403, $this->call('POST', '/chat/consent')['status']);
    }

    /**
     * Files sent with a question become one attachment: each document's words under its name,
     * every picture kept by the application and listed; a file that cannot be read, or files
     * too large together, are refused.
     */
    #[DataProvider('databases')]
    public function testFilesSentWithAQuestion(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $file = static fn (string $name, string $bytes): array => ['name' => $name, 'data' => base64_encode($bytes)];

        // Act
        $sent = $this->call('POST', '/chat', ['content' => 'Read these', 'attachments' => [
            $file('a.txt', 'First words'), $file('b.md', 'Second words'), $file('photo.png', 'PNG'), 'not a file',
        ]]);
        $text = ApiFixture::$queue->tasks[0][1]['attachment'];

        // Assert
        $this->assertSame(202, $sent['status']);
        $this->assertSame('a.txt, b.md, photo.png', $text['name']);
        $this->assertSame("--- a.txt ---\nFirst words\n\n--- b.md ---\nSecond words\n\nPictures in this document:\n- https://media.example/photo.png", $text['text']);
        $one = $this->call('POST', '/chat', ['attachment' => $file('only.png', 'PNG')]);
        $this->assertSame(202, $one['status'], 'a picture alone is a question');
        $this->assertSame('Pictures the person attached.', strtok(ApiFixture::$queue->tasks[1][1]['attachment']['text'], "\n"));
        $this->assertSame(422, $this->call('POST', '/chat', ['attachments' => [$file('x.pdf', '%PDF')]])['status']);
        $this->assertSame(422, $this->call('POST', '/chat', ['attachments' => [$file('big.txt', str_repeat('a', \Pramnos\Llm\Chat\Attachments::BYTES + 1))]])['status']);
    }

    /**
     * Memory and scheduled questions through the API, the person's own only, at addresses an
     * application may keep from before.
     */
    #[DataProvider('databases')]
    public function testMemoryAndSchedules(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $memory = new \Pramnos\Llm\Chat\Memory(new Scope(7, 3));
        $memory->remember('fact', 'Likes tables');
        $this->answer([[200, self::says('Report')]]);
        $old = ['routines' => '/routines', 'consent' => '/ai/consent'];

        // Act / Assert — memory
        $memories = $this->call('GET', '/chat/memory')['data']['memories'];
        $this->assertSame(['Likes tables'], array_column($memories, 'content'));
        $this->assertSame(200, $this->call('DELETE', '/chat/memory/' . $memories[0]['memory_id'])['status']);
        $this->assertSame(404, $this->call('DELETE', '/chat/memory/999')['status']);

        // Schedules, at the old addresses
        $made = $this->call('POST', '/routines', ['title' => 'Daily', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00'], $old);
        $this->assertSame(201, $made['status']);
        $id = $made['data']['routine_id'];
        $this->assertSame(422, $this->call('POST', '/routines', ['title' => 'x'], $old)['status']);
        $this->assertSame(['Daily'], array_column($this->call('GET', '/routines', [], $old)['data']['routines'], 'title'));
        $this->assertFalse($this->call('PUT', '/routines/' . $id, ['active' => 'false'], $old)['data']['active']);
        $this->assertSame(404, $this->call('PUT', '/routines/999', ['active' => true], $old)['status']);
        $this->assertTrue($this->call('POST', '/routines/' . $id . '/run', [], $old)['data']['ok']);
        $this->assertSame(404, $this->call('POST', '/routines/999/run', [], $old)['status']);
        $this->assertSame(200, $this->call('GET', '/ai/consent', [], $old)['status']);
        $this->assertSame(200, $this->call('DELETE', '/routines/' . $id, [], $old)['status']);
        $this->assertSame(404, $this->call('DELETE', '/routines/' . $id, [], $old)['status']);
    }

    /**
     * Resuming refused by the application's limit says why; a failed run is a 409 with it.
     */
    #[DataProvider('databases')]
    public function testSchedulesRefused(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $made = $this->call('POST', '/chat/routines', ['title' => 'Daily', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00'])['data'];
        $this->call('PUT', '/chat/routines/' . $made['routine_id'], ['active' => false]);
        $this->configure(['chat' => ['assistant' => FullAssistant::class]]);

        // Act
        $resumed = $this->call('PUT', '/chat/routines/' . $made['routine_id'], ['active' => true]);
        $ran     = $this->call('POST', '/chat/routines/' . $made['routine_id'] . '/run');

        // Assert
        $this->assertSame([409, 'No room.'], [$resumed['status'], $resumed['error']]);
        $this->assertSame([409, 'No room.'], [$ran['status'], $ran['error']]);
    }

    /**
     * What is checked before asking is the connection the router sends this organisation to —
     * its own provider and key — not the installation's: an organisation with only its own key
     * may ask, and agreement is asked for its provider. Agreement is per feature, each on the
     * provider that feature uses; a feature the application does not have is a 422.
     */
    #[DataProvider('databases')]
    public function testTheRoutedProviderAndEachFeature(string $type, string $host, int $port): void
    {
        // Arrange — no installation key; organisation 3 is sent to DeepSeek on its own key
        $this->connectDatabase($type, $host, $port);
        $this->setting('llm_openai_api_key', '');
        $this->setting('llm_feature_editorial_provider', 'mistral');
        $this->configure(['features' => ['chat' => 'Ask AI', 'editorial' => 'Editorial'], 'consent' => \Pramnos\Llm\Policy\StoredConsent::class]);
        \Pramnos\Llm\Llm::use('router', new OwnKeyRouter());

        // Act
        $chat      = $this->call('GET', '/chat/consent');
        $asked     = $this->call('POST', '/chat', ['content' => 'Hi']);
        $given     = $this->call('POST', '/chat/consent', ['feature' => 'chat']);
        $queued    = $this->call('POST', '/chat', ['content' => 'Hi']);
        $editorial = $this->call('GET', '/chat/consent', ['feature' => 'editorial']);
        $unknown   = $this->call('GET', '/chat/consent', ['feature' => 'nothing']);
        $refused   = $this->call('DELETE', '/chat/consent', ['feature' => 'nothing']);
        $limits    = $this->call('GET', '/chat')['data']['limits'];

        // Assert — the organisation's provider, not the installation's
        $this->assertSame(['chat', 'deepseek', true, false], [$chat['data']['feature'], $chat['data']['provider'], $chat['data']['required'], $chat['data']['given']]);
        // Configured through its own key, so it is asked for agreement rather than told nothing is set up
        $this->assertSame([403, true], [$asked['status'], $asked['consent_required']]);
        $this->assertTrue($given['data']['given']);
        $this->assertSame(202, $queued['status']);
        $this->assertSame(['editorial', 'mistral'], [$editorial['data']['feature'], $editorial['data']['provider']]);
        $this->assertSame([422, 422], [$unknown['status'], $refused['status']]);
        $this->assertSame('DeepSeek', $limits['provider']);
    }

    /** The scheduled-questions answer passes through the application's hook — a plan's limit. */
    #[DataProvider('databases')]
    public function testTheRoutinesAnswerIsDecorated(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);

        // Act
        $answer = $this->call('GET', '/chat/routines');

        // Assert
        $this->assertSame(3, $answer['data']['limit']);
        $this->assertSame([], $answer['data']['routines']);
    }

    /**
     * Without an application's hook, the framework's guard decides: nobody signed in is a 401.
     */
    public function testTheDefaultGuard(): void
    {
        // Act
        $answer = (new ChatController())->list();

        // Assert
        $this->assertSame(401, $answer['status']);
    }

    /**
     * `refusal()` is asked first by every action, so an application's plan can close any of
     * them — a hook that one action forgot would be the way round the plan.
     */
    public function testEveryActionCanBeStopped(): void
    {
        // Arrange
        ApiFixture::$stop = ['status' => 402, 'error' => 'Not on your plan.'];
        $api = new ApiFixture();

        // Act
        $answers = [
            'list'            => $api->list(),
            'read'            => $api->read(1),
            'forget'          => $api->forget(1),
            'ask'             => $api->ask(),
            'asked'           => $api->asked(1),
            'memory'          => $api->memory(),
            'forgetMemory'    => $api->forgetMemory(1),
            'routines'        => $api->routines(),
            'createRoutine'   => $api->createRoutine(),
            'updateRoutine'   => $api->updateRoutine(1),
            'deleteRoutine'   => $api->deleteRoutine(1),
            'runRoutine'      => $api->runRoutine(1),
            'consent'         => $api->consent(),
            'giveConsent'     => $api->giveConsent(),
            'withdrawConsent' => $api->withdrawConsent(),
        ];

        // Assert
        $this->assertNotEmpty($answers, 'the sweep found nothing to check');
        foreach ($answers as $action => $answer) {
            $this->assertSame(ApiFixture::$stop, $answer, $action . ' went ahead');
        }
    }

    /**
     * The hooks' defaults: the request's body, the person signed in (nobody here), no picture
     * kept, the framework's queue — and each refusal of a conversation as its HTTP status.
     */
    public function testTheDefaults(): void
    {
        // Arrange
        $api  = new ChatController();
        $call = static fn (string $method, mixed ...$args): mixed => (new \ReflectionMethod(ChatController::class, $method))->invoke($api, ...$args);

        // Act / Assert
        $this->assertSame([], $call('input'));
        $this->assertNull($call('scope'));
        $this->assertNull($call('storePicture', '/tmp/x.png', 'x.png'));
        $this->assertInstanceOf(\Pramnos\Llm\Chat\Asks::class, $call('asks'));
        $this->assertSame(
            [403, 403, 404, 422, 409, 409, 502],
            array_map(static fn (string $why): int => $call('statusFor', $why), ['scope', 'consent', 'missing', 'empty', 'limit', 'tags', 'provider']),
        );
    }
}

/** The application's controller, as a test sets it. */
class ApiFixture extends ChatController
{
    public static ?Scope $scope = null;

    /** @var array<string, mixed> */
    public static array $input = [];

    /** @var array<string, mixed>|null */
    public static ?array $stop = null;

    /** @var list<int> */
    public static array $charged = [];

    public static ?RecordingQueue $queue = null;

    public function exposeConversations(): \Pramnos\Llm\Chat\Conversations
    {
        return $this->conversations();
    }

    protected function refusal(string $action): ?array
    {
        return self::$stop;
    }

    protected function scope(): ?Scope
    {
        return self::$scope;
    }

    protected function input(): array
    {
        return self::$input;
    }

    protected function beforeAsk(Scope $scope, array $input): ?array
    {
        if (($input['content'] ?? '') === 'limit') {
            return ['status' => 409, 'error' => 'Over the limit.'];
        }
        self::$charged[] = $scope->userId;

        return null;
    }

    protected function storePicture(string $path, string $filename): ?string
    {
        return is_file($path) ? 'https://media.example/' . $filename : null;
    }

    protected function extra(array $input): array
    {
        return isset($input['draft']) ? ['draft' => $input['draft']] : [];
    }

    protected function limits(?Scope $scope): array
    {
        return parent::limits($scope) + ['month' => 'ours'];
    }

    protected function routinesAnswer(?Scope $scope, array $answer): array
    {
        return parent::routinesAnswer($scope, $answer) + ['limit' => 3];
    }

    protected function asks(): \Pramnos\Llm\Chat\Asks
    {
        self::$queue ??= new RecordingQueue();

        return new \Pramnos\Llm\Chat\Asks($this->conversations(), self::$queue);
    }
}

/** An assistant whose plan has room for no more routines, and allows none to run. */
final class FullAssistant extends \Pramnos\Llm\Chat\BaseAssistant
{
    public function routineRefusal(Scope $scope, bool $adding = false): ?string
    {
        return 'No room.';
    }
}

/** Organisation 3 chose DeepSeek and brings its own key. */
final class OwnKeyRouter extends \Pramnos\Llm\Policy\DefaultRouter
{
    public function route(array $context, \Pramnos\Llm\Connection $connection): \Pramnos\Llm\Connection
    {
        return ($context['purpose'] ?? '') === 'chat' && (int) ($context['organization_id'] ?? 0) === 3
            ? $connection->onProvider('deepseek')->with(['api_key' => 'sk-own', 'own' => true])
            : $connection;
    }
}
