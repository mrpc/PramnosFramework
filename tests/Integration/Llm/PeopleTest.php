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
 * What has to hold: a person's page has every persona's conversations, memories, diary, choices
 * and routines; a reset of the experience keeps the history, puts the choices back and sets
 * `reset_at` — which `GET {chat}/me` gives, each persona's first meeting counted from it — and is
 * audited; an erase takes everything of the companion's spaces and nothing of the Ask's or the
 * calendar, and is audited too.
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
     * Read in full; the experience reset with the history kept and `me` telling the page to meet
     * again; then the history erased, the Ask's and the calendar kept.
     */
    #[DataProvider('databases')]
    public function testSeeAndStartOver(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class], $this->db);
        $maria = $this->person('Μαρίαxyz');
        $this->answer([[200, self::says('Γεια!')], [200, self::says('Meow.')], [200, self::says('Sales are up.')]]);
        $naya  = (new Conversations(new BaseAssistant()))->as(new Scope($maria));
        $naya->ask(null, 'Γεια σου');
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

        // Act — the experience reset, the history kept
        $whole     = $people->person($maria);
        $resetAt   = $people->reset($maria, 1);
        $after     = $people->person($maria);
        ApiFixture::$scope = new Scope($maria);
        ApiFixture::$stop  = null;
        $me        = (new ApiFixture())->me();
        $this->db->queryBuilder()->table('assist.chat_conversations')->insert(['organization_id' => null, 'userid' => $maria, 'space' => Scope::CHAT,
            'title' => 'Again', 'created_at' => gmdate('Y-m-d H:i:s', time() + 5), 'updated_at' => gmdate('Y-m-d H:i:s', time() + 5)]);
        $again     = (new ApiFixture())->me();
        $kept      = $this->db->queryBuilder()->table('assist.chat_memory')->where('userid', $maria)->count();
        $resets    = \Pramnos\Auth\AuditLog::query(['event' => 'llm.companion.reset'], 5);

        // Act — the history erased too
        $counts    = $people->erase($maria, 1);
        $erased    = $people->person($maria);
        $asksLeft  = $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $maria)->count();
        $calendar  = $this->db->queryBuilder()->table('assist.chat_calendar')->where('userid', $maria)->count();
        $audited   = \Pramnos\Auth\AuditLog::query(['event' => 'llm.companion.erased'], 5);

        // Assert — what it knows of them
        $this->assertSame('tesla', $whole['persona']);
        $this->assertCount(1, $whole['personas']['naya']['conversations']);
        $this->assertSame(2, $whole['personas']['naya']['conversations'][0]['messages']);
        $this->assertSame(['fact'], array_column($whole['personas']['naya']['memories'], 'kind'));
        $this->assertSame(['total' => 1, 'unread' => 1], $whole['personas']['naya']['diary']);
        $this->assertSame('maria', $whole['personas']['tesla']['voice']);
        $this->assertNull($whole['personas']['naya']['voice'], 'a voice per persona');
        $this->assertSame(['Χάπι'], array_column($whole['personas']['naya']['routines'], 'title'));
        $this->assertSame('Run a marathon', $whole['profile'][0]['pending']);
        $this->assertNotNull($whole['personas']['tesla']['first_meeting']);
        $this->assertNull($whole['reset_at']);

        // Assert — the reset: choices back, met again, nothing of the history gone
        $this->assertCount(1, $after['personas']['naya']['conversations'], 'the conversations kept');
        $this->assertSame(1, $kept, 'the memories kept');
        $this->assertNull($after['personas']['tesla']['voice'], 'the choices back to the defaults');
        $this->assertSame('naya', $after['persona']);
        $this->assertSame($resetAt, $after['reset_at']);
        $this->assertNull($after['personas']['naya']['first_meeting'], 'a first meeting again, still to come');
        $this->assertSame($resetAt, $me['data']['reset_at']);
        $this->assertSame(['naya' => null, 'tesla' => null], $me['data']['first_meetings'], 'met again from the start');
        $this->assertNull($me['data']['first_meeting']);
        $this->assertNotNull($again['data']['first_meetings']['naya'], 'the first conversation after the reset is the first meeting');
        $this->assertNull($again['data']['first_meetings']['tesla']);
        $this->assertNotEmpty(array_filter($resets, static fn (array $row): bool => (string) $row['target_id'] === (string) $maria));

        // Assert — the erase
        $this->assertSame(['conversations' => 3, 'messages' => 4, 'asks' => 0, 'memories' => 1, 'artifacts' => 1, 'routines' => 1, 'profile' => 1], $counts);
        $this->assertSame([], $erased['personas']['naya']['conversations']);
        $this->assertSame(1, $asksLeft, 'the Ask\'s conversation stays');
        $this->assertSame(1, $calendar, 'the calendar stays');
        $this->assertNotEmpty(array_filter($audited, static fn (array $row): bool => (string) $row['target_id'] === (string) $maria));
        $this->assertNull($people->person(999999999));
    }
}
