<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Diary;
use Pramnos\Llm\Chat\People;
use Pramnos\Llm\Chat\Profile;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * What an administrator sees of the companion's people, and starting one over (FW-455), on MySQL
 * and PostgreSQL.
 *
 * What has to hold: the list has who talked to the companion — never somebody who only used the
 * Ask — with conversations and messages by persona; a person's page has every persona's
 * conversations, memories, diary, choices and routines; a conversation reads with its tools and
 * extra; and a reset takes everything of the companion's spaces and nothing of the Ask's or the
 * calendar, puts the choices back, sets `reset_at` — which `GET {chat}/me` gives — and is audited.
 */
#[CoversClass(People::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class PeopleTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['memory' => ['learn' => false], 'profile' => true, 'calendar' => true, 'journal' => ['diary' => true],
            'personas' => ['naya' => ['name' => 'Naya', 'default' => true], 'tesla' => ['name' => 'Tesla']]],
            'speech' => ['voices' => ['maria' => ['label' => 'Μαρία', 'voice' => 'el_GR-maria']]]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->people as $person) {
            foreach (['assist.chat_conversations', 'assist.chat_memory', 'assist.chat_artifacts', 'assist.chat_calendar', 'assist.chat_dossier', 'pramnos.ai_routines'] as $table) {
                $this->db->queryBuilder()->table($table)->where('userid', $person)->delete();
            }
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $person)->delete();
        }
        ApiFixture::$scope = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A saved person. */
    private function person(string $first): int
    {
        $user = new \Pramnos\User\User();
        $user->username  = 'people' . random_int(100000, 999999);
        $user->firstname = $first;
        $user->email     = $user->username . '@example.test';
        $user->save();

        return $this->people[] = (int) $user->userid;
    }

    /**
     * Listed, read in full and by conversation; reset with everything of the companion's gone and
     * the Ask's and the calendar kept; `me` tells the page it was started over.
     */
    #[DataProvider('databases')]
    public function testSeeAndStartOver(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class], $this->db);
        $maria = $this->person('Μαρίαxyz');
        $asker = $this->person('Askonly');
        $this->answer([[200, self::says('Γεια!')], [200, self::says('Meow.')], [200, self::says('Sales are up.')]]);
        $naya  = (new Conversations(new BaseAssistant()))->as(new Scope($maria));
        $first = $naya->ask(null, 'Γεια σου');
        (new Conversations(new BaseAssistant()))->as(new Scope($maria, null, Scope::CHAT, 'tesla'))->ask(null, 'Psst');
        $ask   = (new Conversations(new BaseAssistant()))->as(new Scope($maria, null, Scope::ADMIN))->ask(null, 'Sales?');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', (int) $ask['conversation_id'])->update(['userid' => $maria]);
        (new \Pramnos\Llm\Chat\Memory(new Scope($maria)))->keep('fact', 'Has a cat');
        (new Diary(new Scope($maria)))->keep(date('Y-m-d'), 'Μια μέρα.');
        (new Profile(new Scope($maria)))->note('goals', 'Run a marathon', 'running');
        (new \Pramnos\Llm\Chat\Calendar(new Scope($maria)))->create('Οδοντίατρος', date('Y-m-d') . ' 10:00');
        (new \Pramnos\Llm\Chat\Routines(new Conversations(new BaseAssistant())))
            ->create(new Scope($maria), ['kind' => 'reminder', 'title' => 'Χάπι', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $user = new \Pramnos\User\User($maria);
        \Pramnos\Llm\Speech\Speech::choose($user, 'maria', 'tesla');
        \Pramnos\Llm\Chat\Personas::choose($user, 'tesla');
        $people = new People();

        // Act
        $list      = $people->people(50, 0, 'Μαρίαxyz');
        $nobody    = $people->people(50, 0, 'Askonly');
        $whole     = $people->person($maria);
        $read      = $people->messages((int) $first['conversation_id']);
        $notOurs   = $people->messages((int) $ask['conversation_id']);
        $counts    = $people->reset($maria, 1);
        $after     = $people->person($maria);
        ApiFixture::$scope = new Scope($maria);
        ApiFixture::$stop  = null;
        $me        = (new ApiFixture())->me();
        $asksLeft  = $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $maria)->count();
        $calendar  = $this->db->queryBuilder()->table('assist.chat_calendar')->where('userid', $maria)->count();
        $audited   = \Pramnos\Auth\AuditLog::query(['event' => 'llm.companion.reset'], 5);

        // Assert
        $this->assertCount(1, $list);
        $this->assertEqualsCanonicalizing(['naya', 'tesla'], array_keys($list[0]['personas']), 'plain chat is the default persona\'s');
        $this->assertSame(2, $list[0]['conversations'], 'the Ask\'s conversation is not the companion\'s');
        $this->assertSame(4, $list[0]['messages']);
        $this->assertSame('tesla', $list[0]['persona']);
        $this->assertSame([], $nobody, 'somebody with no companion conversation is not listed');
        $this->assertCount(1, $whole['personas']['naya']['conversations']);
        $this->assertSame(['fact'], array_column($whole['personas']['naya']['memories'], 'kind'));
        $this->assertSame(['total' => 1, 'unread' => 1], $whole['personas']['naya']['diary']);
        $this->assertSame('maria', $whole['personas']['tesla']['voice']);
        $this->assertNull($whole['personas']['naya']['voice'], 'a voice per persona');
        $this->assertSame(['Χάπι'], array_column($whole['personas']['naya']['routines'], 'title'));
        $this->assertSame('Run a marathon', $whole['profile'][0]['pending']);
        $this->assertNotNull($whole['personas']['tesla']['first_meeting']);
        $this->assertNull($whole['reset_at']);
        $this->assertSame(['user', 'assistant'], array_column($read['messages'], 'role'));
        $this->assertNull($notOurs, 'an Ask conversation is not read here');
        $this->assertSame(['conversations' => 2, 'messages' => 4, 'asks' => 0, 'memories' => 1, 'artifacts' => 1, 'routines' => 1, 'profile' => 1], $counts);
        $this->assertSame([], $after['personas']['naya']['conversations']);
        $this->assertNull($after['personas']['tesla']['voice'], 'the choices back to the defaults');
        $this->assertSame('naya', $after['persona']);
        $this->assertNotNull($after['reset_at']);
        $this->assertSame(1, $asksLeft, 'the Ask\'s conversation stays');
        $this->assertSame(1, $calendar, 'the calendar stays');
        $this->assertSame($after['reset_at'], $me['data']['reset_at']);
        $this->assertSame(['naya' => null, 'tesla' => null], $me['data']['first_meetings'], 'met again from the start');
        $this->assertNotEmpty(array_filter($audited, static fn (array $row): bool => (string) $row['target_id'] === (string) $maria));
        $this->assertNull($people->person(999999999));
        $this->assertSame([], $people->people(50, 0, 'no-such-person-' . random_int(1000, 9999)), 'a search that finds nobody');
        $this->assertSame([], $people->people(50, 100000000), 'past the last page');
    }
}
