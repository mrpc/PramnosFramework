<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Asks;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';
require_once __DIR__ . '/RoutinesAsksConsentTest.php';

/**
 * A queued question followed as a stream (FW-438), on MySQL and PostgreSQL.
 *
 * What has to hold, for a voice that starts within a second: each change of what the answer is
 * doing is told once; the text is told only up to its last whole sentence, so a voice never reads
 * half a word it has to take back; the end carries the stored answer with its mood; a failure is
 * told as one; another person's ask is not followed; the endpoint answers a stream, or 404.
 */
#[CoversClass(Asks::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class AsksStreamTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['mood' => true], 'analyst' => ['avatar_set' => ['markup' => '<svg></svg>', 'moods' => ['happy' => 'success']]]]);
    }

    protected function tearDown(): void
    {
        ApiFixture::$scope = null;
        ApiFixture::$queue = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A sentence is whole when a mark ends it and a space follows; a decimal point is not an end. */
    public function testWholeSentences(): void
    {
        // Act / Assert
        $this->assertSame('', Asks::wholeSentences('Hello the'));
        $this->assertSame('Hello there.', Asks::wholeSentences('Hello there. How ar'));
        $this->assertSame('It is 3.5 degrees!', Asks::wholeSentences("It is 3.5 degrees!\nTomorr"));
        $this->assertSame('', Asks::wholeSentences('It is 3.5'), 'not a sentence\'s end');
    }

    /**
     * Followed while the worker answers: a tool, then words, then nothing doing, then the answer —
     * each told once, the words up to whole sentences, the end with the stored answer and mood.
     */
    #[DataProvider('databases')]
    public function testFollowedToTheEnd(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $scope  = new Scope($person);
        $this->answer([[200, self::says("Sunny today. Enjoy it!\n[[mood:happy|80]]")]]);
        $queue  = new RecordingQueue();
        $asks   = new Asks(new Conversations(new BaseAssistant()), $queue);
        $askId  = $asks->start($scope, null, 'Weather?');
        $row    = fn (array $set) => $this->db->queryBuilder()->table('assist.chat_asks')->where('ask_id', $askId)->update($set);
        $steps  = [
            fn () => $row(['doing' => '{"tool":"fetch_url","host":"meteo.gr"}']),
            fn () => $row(['partial' => 'Sunny to']),
            fn () => $row(['doing' => null, 'partial' => 'Sunny today. Enj']),
            fn () => $row(['partial' => 'Sunny today. Enjoy it! ']),
            fn () => $asks->run($queue->tasks[0][1]),
        ];
        $events = [];

        // Act
        $followed = $asks->follow($scope, $askId, static function (string $event, mixed $data) use (&$events): void {
            $events[] = [$event, $data];
        }, static function () use (&$steps): void {
            if ($steps !== []) {
                (array_shift($steps))();
            }
        });
        $stranger = $asks->follow(new Scope($person + 1), $askId, static fn () => null, static fn () => null);

        // Assert
        $this->assertTrue($followed);
        $this->assertSame(['doing', 'doing', 'doing', 'partial', 'partial', 'answered'], array_column($events, 0));
        $this->assertNull($events[0][1], 'nothing doing at first');
        $this->assertSame(['tool' => 'fetch_url', 'host' => 'meteo.gr'], $events[1][1]);
        $this->assertSame(['text' => 'Sunny today.'], $events[3][1], 'whole sentences only');
        $this->assertSame(['text' => 'Sunny today. Enjoy it!'], $events[4][1]);
        $end = $events[5][1];
        $this->assertSame('Sunny today. Enjoy it!', $end['content']);
        $this->assertSame('happy', $end['mood']['mood']);
        $this->assertIsInt($end['message_id']);
        $this->assertFalse($end['ended']);
        $this->assertFalse($stranger, 'another person\'s ask is not followed');
        $this->db->queryBuilder()->table('assist.chat_asks')->where('userid', $person)->delete();
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /** A failure is told as one; the endpoint answers a stream for the person's ask, 404 otherwise. */
    #[DataProvider('databases')]
    public function testAFailureAndTheEndpoint(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $scope  = new Scope($person);
        $asks   = new Asks(new Conversations(new BaseAssistant()), new RecordingQueue());
        $askId  = $asks->start($scope, null, 'Weather?');
        $this->db->queryBuilder()->table('assist.chat_asks')->where('ask_id', $askId)->update(['status' => 'failed', 'why' => 'provider', 'says' => 'Down.']);
        $events = [];
        ApiFixture::$scope = $scope;
        ApiFixture::$stop  = null;

        // Act
        $asks->follow($scope, $askId, static function (string $event, mixed $data) use (&$events): void {
            $events[] = [$event, $data];
        }, static fn () => null);
        $stream  = (new ApiFixture())->askStream($askId);
        $missing = (new ApiFixture())->askStream(999999999);

        // Assert
        $this->assertSame(['doing', 'failed'], array_column($events, 0));
        $this->assertSame(['status' => 'failed', 'why' => 'provider', 'says' => 'Down.'], $events[1][1]);
        $this->assertInstanceOf(\Pramnos\Http\StreamedResponse::class, $stream);
        $this->assertSame(404, $missing['status']);
        $this->db->queryBuilder()->table('assist.chat_asks')->where('userid', $person)->delete();
    }
}
