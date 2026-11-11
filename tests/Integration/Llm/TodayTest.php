<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Calendar;
use Pramnos\Llm\Chat\Diary;
use Pramnos\Llm\Chat\Profile;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Today;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * The day's cards (FW-444), on MySQL and PostgreSQL.
 *
 * What has to hold: `GET {chat}/today` gives the morning's thought first — written once a day and
 * kept, not written again — then the day's appointments, the tasks due today or overdue, the
 * reminders that go off today, what waits for the person's word, and the unread diary pages; each
 * with what opening it does. Without the thought turned on, no model is asked.
 */
#[CoversClass(Today::class)]
#[CoversClass(\Pramnos\Llm\Chat\Conversations::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class TodayTest extends LlmTestCase
{
    use ChatDatabase;

    private int $person = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['today' => ['thought' => true], 'calendar' => true, 'profile' => true, 'journal' => ['diary' => true], 'memory' => ['learn' => false]]]);
    }

    protected function tearDown(): void
    {
        if ($this->person > 0) {
            foreach (['assist.chat_artifacts', 'assist.chat_calendar', 'assist.chat_dossier', 'pramnos.ai_routines'] as $table) {
                $this->db->queryBuilder()->table($table)->where('userid', $this->person)->delete();
            }
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->person)->delete();
        }
        ApiFixture::$scope = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A request through the routes. */
    private function today(): mixed
    {
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);

        return $router->dispatch(\Pramnos\Http\Request::create('/chat/today', 'GET'));
    }

    /**
     * Every kind of card from its source, the thought first; asked again, the same thought without
     * a second call; with the thought off, none and no call.
     */
    #[DataProvider('databases')]
    public function testTheDaysCards(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = new \Pramnos\User\User();
        $user->username  = 'today' . random_int(100000, 999999);
        $user->firstname = 'Μαρία';
        $user->email     = $user->username . '@example.test';
        $user->save();
        $this->person = (int) $user->userid;
        $scope        = new Scope($this->person);
        $zone         = (new \Pramnos\Llm\Chat\BaseAssistant())->timezone($scope);
        $day          = (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format('Y-m-d');
        $calendar     = new Calendar($scope, $zone);
        $calendar->create('Οδοντίατρος', $day . ' 10:00', $day . ' 11:00');
        $calendar->create('Αύριο', (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d') . ' 10:00');
        $calendar->addTask('Γάλα', $day . ' 18:00');
        $calendar->addTask('Λογαριασμός', '2020-01-01 09:00');
        $calendar->addTask('Κάποτε');
        (new Profile($scope))->note('goals', 'Run a marathon', 'running');
        (new Diary($scope))->keep($day, 'Μια όμορφη μέρα.');
        $routine = (new \Pramnos\Llm\Chat\Routines(new \Pramnos\Llm\Chat\Conversations(new \Pramnos\Llm\Chat\BaseAssistant())))
            ->create($scope, ['kind' => 'reminder', 'title' => 'Χάπι', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00', 'timezone' => $zone]);
        $this->db->queryBuilder()->table('pramnos.ai_routines')->where('routine_id', (int) $routine['routine_id'])
            ->update(['next_run_at' => \Pramnos\Llm\CallLog::instant(time())]);
        ApiFixture::$scope = $scope;
        ApiFixture::$stop  = null;
        $this->answer([[200, self::says('Καλημέρα Μαρία, να έχεις μια ήρεμη μέρα.')]]);

        // Act
        $first  = $this->today();
        $again  = $this->today();
        $this->configure(['chat' => ['calendar' => true]]);
        $off    = $this->today();

        // Assert
        $cards = $first['data']['cards'];
        $kinds = array_map(static fn (array $c): string => $c['kind'] . ':' . $c['title'], $cards);
        $this->assertSame(['thought:', 'event:Οδοντίατρος', 'reminder:Λογαριασμός', 'reminder:Γάλα', 'reminder:Χάπι', 'question:running', 'diary:Diary ' . $day], $kinds,
            'tomorrow\'s appointment and a task without a due time are not today\'s');
        $this->assertSame('Καλημέρα Μαρία, να έχεις μια ήρεμη μέρα.', $cards[0]['text']);
        $this->assertSame('10:00–11:00', $cards[1]['text']);
        $this->assertSame('2020-01-01', $cards[2]['text'], 'overdue: its day');
        $this->assertSame('18:00', $cards[3]['text']);
        $this->assertSame('Run a marathon', $cards[5]['text'], 'what waits for their word');
        $this->assertSame('diary', $cards[6]['action']['type']);
        $this->assertStringContainsString('for the start of their day', json_encode($this->sent[0]['body']['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertSame($cards, $again['data']['cards'], 'the same thought, kept');
        $this->assertCount(1, $this->sent, 'written once a day');
        $this->assertSame(['event', 'reminder', 'reminder', 'reminder'], array_column($off['data']['cards'], 'kind'), 'only what is on');
    }

    /** Nobody signed in has no day. */
    public function testNeedsSomebody(): void
    {
        // Arrange
        ApiFixture::$scope = new Scope(0);
        ApiFixture::$stop  = null;

        // Act / Assert
        $this->assertSame(401, $this->today()['status']);
    }
}
