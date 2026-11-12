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

    /**
     * `GET {chat}/me` carries the application's own fields where its assistant has `meExtras()` —
     * a photo, say — and never lets them over the framework's own.
     */
    #[DataProvider('databases')]
    public function testMeCarriesTheApplicationsOwnFields(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['assistant' => MeExtrasAssistant::class]]);
        ApiFixture::$scope = new Scope(1234567);
        ApiFixture::$stop  = null;

        // Act
        $me = (new ApiFixture())->me();

        // Assert
        $this->assertSame('https://media.example/me.jpg', $me['data']['photo'], 'its own field');
        $this->assertNotSame('Somebody else', $me['data']['name'], 'never over the framework\'s');
    }

    /**
     * A partition's own assistant, settings and first meetings (partitions under one account): its
     * persona the application's assistant keeps, chosen through the API and refused where it says;
     * its settings its own over the holder's, the holder's untouched; its first meetings its own
     * conversations', never the holder's.
     */
    #[DataProvider('databases')]
    public function testAPartitionHasItsOwnPersonaSettingsAndFirstMeetings(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['assistant' => PartitionAssistant::class, 'memory' => ['learn' => false],
            'personas' => ['naya' => ['name' => 'Naya', 'default' => true], 'tesla' => ['name' => 'Tesla']]]]);
        $user = new \Pramnos\User\User();
        $user->username = 'part' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $id   = (int) $user->userid;
        $part = (new Scope($id))->withPartition(4);
        \Pramnos\Llm\Chat\Settings::change(new \Pramnos\User\User($id), 'tesla', ['thought_at' => '07:00', 'quiet' => ['from' => '22:00', 'to' => '07:00']]);
        $this->db->queryBuilder()->table('assist.chat_conversations')->insert(['organization_id' => null, 'userid' => $id, 'space' => 'chat:tesla@4',
            'title' => 'Hers', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        ApiFixture::$stop = null;

        // Act
        $scoped = \Pramnos\Llm\Chat\Personas::ofPartition($part);
        \Pramnos\Llm\Chat\Settings::change(new \Pramnos\User\User($id), 'tesla', ['thought_at' => '09:30'], 4);
        $hers   = \Pramnos\Llm\Chat\Settings::of(new \Pramnos\User\User($id), 'tesla', 4);
        $holder = \Pramnos\Llm\Chat\Settings::of(new \Pramnos\User\User($id), 'tesla');
        ApiFixture::$scope = $part;
        ApiFixture::$input = ['persona' => 'naya'];
        $refused = (new ApiFixture())->persona();
        ApiFixture::$input = ['persona' => 'tesla'];
        $kept    = (new ApiFixture())->persona();
        ApiFixture::$input = [];
        $meHers  = (new ApiFixture())->me();
        ApiFixture::$scope = new Scope($id);
        $meHolder = (new ApiFixture())->me();

        // Assert
        $this->assertSame(['tesla', 4], [$scoped->persona, $scoped->partition], 'the partition\'s own persona');
        $this->assertSame('09:30', $hers['thought_at'], 'its own setting');
        $this->assertSame(['from' => '22:00', 'to' => '07:00'], $hers['quiet'], 'the holder\'s where it has none of its own');
        $this->assertSame('07:00', $holder['thought_at'], 'the holder\'s untouched');
        $this->assertSame([403, 'Not for this profile.'], [$refused['status'], $refused['error']], 'the application refuses');
        $this->assertSame(200, $kept['status']);
        $this->assertNotNull($meHers['data']['first_meetings']['tesla'], 'its own conversations');
        $this->assertNull($meHolder['data']['first_meetings']['tesla'], 'never the holder\'s');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $id)->delete();
        $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $id)->delete();
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->delete();
    }

    /**
     * A device subscribed from a partition belongs to it (FW-490): tagged `chat@<partition>`, and
     * moved to another profile when the same device is subscribed again from it; the holder's
     * devices are `chat`; and a partition's notice reaches only its own devices.
     */
    #[DataProvider('databases')]
    public function testADeviceBelongsToTheProfileItWasSubscribedFrom(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable::class,
            \Pramnos\Framework\Migrations\Notifications\AddTrustedDeviceToPushSubscriptions::class, \Pramnos\Framework\Migrations\Notifications\AddClientToPushSubscriptions::class,
            \Pramnos\Framework\Migrations\Notifications\AddAppToPushSubscriptions::class], $this->db);
        $id     = random_int(100000, 999999);
        $device = ['endpoint' => 'https://push.example/d/' . $id, 'keys' => ['p256dh' => 'BPkey', 'auth' => 'authkey']];
        ApiFixture::$stop  = null;
        ApiFixture::$input = $device;

        // Act
        ApiFixture::$scope = (new Scope($id))->withPartition(3);
        $first  = (new ApiFixture())->pushSubscribe();
        $three  = \Pramnos\Push\Subscriptions::exist($id, 'chat@3');
        ApiFixture::$scope = (new Scope($id))->withPartition(5);
        (new ApiFixture())->pushSubscribe();
        $moved  = [\Pramnos\Push\Subscriptions::exist($id, 'chat@3'), \Pramnos\Push\Subscriptions::exist($id, 'chat@5'), \Pramnos\Push\Subscriptions::exist($id, 'chat')];
        ApiFixture::$scope = new Scope($id);
        $holder = (new ApiFixture())->pushSubscribe();

        // Assert
        $this->assertSame('chat@3', $first['data']['app']);
        $this->assertTrue($three);
        $this->assertSame([false, true, false], $moved, 'the same device moved to the other profile');
        $this->assertSame('chat', $holder['data']['app'], 'the holder\'s');
        $this->db->queryBuilder()->table('pramnos.pushsubscriptions')->where('userid', $id)->delete();
    }
}

/** An application's assistant that keeps a persona per partition: partition 4 has Tesla, and none may choose Naya. */
class PartitionAssistant extends \Pramnos\Llm\Chat\BaseAssistant
{
    /** @var array<int, string> The persona each partition chose */
    public static array $chosen = [4 => 'tesla'];

    /** The partition's persona, or null for the holder's. */
    public function chosenPersona(Scope $scope): ?string
    {
        return self::$chosen[$scope->partition ?? 0] ?? null;
    }

    /** Keep a partition's choice; Naya is refused. */
    public function choosePersona(Scope $scope, string $key): ?string
    {
        if ($key === 'naya') {
            return 'Not for this profile.';
        }
        self::$chosen[(int) $scope->partition] = $key;

        return null;
    }
}

/** An application's assistant that adds its own fields to `/me`. */
class MeExtrasAssistant extends \Pramnos\Llm\Chat\BaseAssistant
{
    /**
     * A photo, and an attempt at the framework's name.
     *
     * @return array<string, mixed>
     */
    public function meExtras(Scope $scope): array
    {
        return ['photo' => 'https://media.example/me.jpg', 'name' => 'Somebody else'];
    }
}
