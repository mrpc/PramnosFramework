<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Asks;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/RoutinesAsksConsentTest.php';

/**
 * What a queued answer is doing right now (FW-434), on MySQL and PostgreSQL.
 *
 * What has to hold: while a tool runs, `GET {chat}/asks/{id}` says which — and for a page, only
 * its site, never the whole address; once text arrives, and once the ask ends, it says nothing.
 */
#[CoversClass(Asks::class)]
#[CoversClass(\Pramnos\Llm\Client::class)]
class AsksDoingTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * A tool's name alone; one that sends an address out, that address's host and nothing more;
     * an address that is not one, the name alone.
     */
    public function testWhatAScreenIsTold(): void
    {
        // Act / Assert
        $this->assertSame(['tool' => 'customer'], Asks::doingOf('customer', ['email' => 'maria@x.gr'], ['tags' => ['personal' => true]]));
        $this->assertSame(['tool' => 'fetch_url', 'host' => 'meteo.gr'], Asks::doingOf('fetch_url', ['url' => 'https://METEO.gr/forecast?city=Athens'], ['outbound' => 'url']));
        $this->assertSame(['tool' => 'fetch_url'], Asks::doingOf('fetch_url', ['url' => 'not an address'], ['outbound' => 'url']));
    }

    /**
     * Read while the tools run, through the ask: each tool in turn, with the page's host; after
     * the answer, nothing.
     */
    #[DataProvider('databases')]
    public function testTheAskSaysWhatTheAnswerIsDoing(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $scope  = new Scope($person, null);
        $seen   = [];
        $asks   = null;
        $askId  = 0;
        $peek   = static function () use (&$asks, &$askId, &$seen, $scope): string {
            $seen[] = $asks->find($scope, $askId)['doing'];

            return 'ok';
        };
        $assistant = new class ($peek) extends BaseAssistant {
            /** @param callable(): string $peek */
            public function __construct(private $peek)
            {
            }

            /** A personal read and a page fetch, each looking at the ask while it runs. */
            public function tools(Turn $turn): array
            {
                return [
                    'customer' => ['description' => 'A customer', 'parameters' => [], 'run' => fn (): string => ($this->peek)()],
                    'read_page' => ['description' => 'A page', 'parameters' => ['url' => ['type' => 'string']], 'outbound' => 'url', 'run' => fn (): string => ($this->peek)()],
                ];
            }
        };
        $this->answer([
            [200, self::calls([['customer', ['name' => 'Maria']]])],
            [200, self::calls([['read_page', ['url' => 'https://meteo.gr/forecast?q=Athens']]])],
            [200, self::says('Sunny.')],
        ]);
        $queue = new RecordingQueue();
        $asks  = new Asks(new Conversations($assistant), $queue);

        // Act
        $askId = $asks->start($scope, null, 'Weather for Maria?');
        $asks->run($queue->tasks[0][1]);
        $after = $asks->find($scope, $askId);

        // Assert
        $this->assertSame([['tool' => 'customer'], ['tool' => 'read_page', 'host' => 'meteo.gr']], $seen, 'each tool as it runs; the site, never the address');
        $this->assertSame('answered', $after['status']);
        $this->assertNull($after['doing'], 'nothing once it ends');
        $this->db->queryBuilder()->table('assist.chat_asks')->where('userid', $person)->delete();
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }
}
