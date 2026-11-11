<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Asks;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Personas;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';
require_once __DIR__ . '/RoutinesAsksConsentTest.php';

/**
 * Personas on the conversation page (FW-423), on MySQL and PostgreSQL.
 *
 * What has to hold: the default persona writes plain `chat`, so conversations from before
 * personas stay its own; another keeps its own under `chat:<persona>` and neither sees the
 * other's; a persona other than the default is told who it is; each answers with its own mood
 * words; what is the person's — calendar, profile, journal — is the same for every persona; a
 * queued question keeps its persona through the worker; and the person lists and chooses through
 * the API, a choice not offered being refused.
 */
#[CoversClass(Personas::class)]
#[CoversClass(Scope::class)]
#[CoversClass(Conversations::class)]
class PersonasTest extends LlmTestCase
{
    use ChatDatabase;

    /** Users this test made, removed on tear-down. */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        \Pramnos\Assist\AvatarSets::reset();
        $this->configure(['chat' => ['mood' => true, 'personas' => [
            'naya'  => ['name' => 'Naya', 'default' => true, 'avatar_set' => ['markup' => '<svg></svg>', 'moods' => ['happy' => 'success']]],
            'tesla' => ['character' => 'tesla'],
        ]]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $id) {
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $id)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->delete();
        }
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        unset($_SERVER['REQUEST_METHOD']);
        \Pramnos\Assist\AvatarSets::reset();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The system prompt a request sent. */
    private static function system(array $sent): string
    {
        return (string) $sent['body']['messages'][0]['content'];
    }

    /**
     * A stored space carries its persona and comes back from it; the person, without one; the
     * default is the one marked so; a character's name, prompt and set fill a persona that names
     * it; the analyst's space has none.
     */
    public function testTheScopeAndTheList(): void
    {
        // Arrange
        $tesla = new Scope(5, 2, Scope::CHAT, 'tesla');

        // Act
        $back = new Scope(5, 2, $tesla->stored());

        // Assert
        $this->assertSame('chat:tesla', $tesla->stored());
        $this->assertSame('chat', $tesla->space, 'everything that asks the space still hears chat');
        $this->assertSame('tesla', $back->persona);
        $this->assertSame('chat', $tesla->person()->stored(), 'the person, whichever persona');
        $this->assertSame('naya', Personas::default());
        $this->assertSame('Tesla', Personas::get('tesla')['name']);
        $this->assertSame('tesla', Personas::get('tesla')['avatar_set'], 'the character\'s set, by name');
        $this->assertNull(Personas::of(new Scope(5, null, Scope::ADMIN)), 'the analyst has none');
        $this->assertSame('', Personas::identity(new Scope(5)), 'the default is the assistant\'s own instructions');
        $this->assertStringContainsString('In this conversation you are Tesla', Personas::identity($tesla));
        $this->assertInstanceOf(BaseAssistant::class, \Pramnos\Llm\Chat\Chat::assistant('chat:tesla'));
    }

    /**
     * Each persona's conversations are its own, the default's stored as plain chat; Tesla is told
     * who she is; each keeps only its own mood words; the calendar is the person's under either.
     */
    #[DataProvider('databases')]
    public function testEachPersonaKeepsItsOwn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $this->answer([[200, self::says('Hi! [[mood:affectionate|70]]')], [200, self::says('Purr. [[mood:affectionate|70]]')]]);
        $naya  = (new Conversations(new BaseAssistant()))->as(new Scope($person));
        $tesla = (new Conversations(new BaseAssistant()))->as(new Scope($person, null, Scope::CHAT, 'tesla'));

        // Act
        $toNaya  = $naya->ask(null, 'Hello');
        $toTesla = $tesla->ask(null, 'Hello');
        $spaceOf = fn (array $answer): string => (string) $this->db->queryBuilder()->table('assist.chat_conversations')
            ->where('conversation_id', (int) $answer['conversation_id'])->first()->fields['space'];
        $calendar = (fn () => $this->scope)->call(new \Pramnos\Llm\Chat\Calendar(new Scope($person, null, Scope::CHAT, 'tesla')));

        // Assert
        $this->assertSame('chat', $spaceOf($toNaya), 'the default writes plain chat');
        $this->assertSame('chat:tesla', $spaceOf($toTesla));
        $this->assertSame([(int) $toNaya['conversation_id']], array_column($naya->list(), 'conversation_id'), 'neither sees the other\'s');
        $this->assertSame([(int) $toTesla['conversation_id']], array_column($tesla->list(), 'conversation_id'));
        $this->assertStringNotContainsString('you are Tesla', self::system($this->sent[0]));
        $this->assertStringContainsString('In this conversation you are Tesla', self::system($this->sent[1]));
        $this->assertNull($toNaya['message']['mood'], 'affectionate is not one of Naya\'s words');
        $this->assertSame('affectionate', $toTesla['message']['mood']['mood'], 'it is one of Tesla\'s');
        $this->assertSame('rubbing', $toTesla['message']['mood']['plays']);
        $this->assertSame('chat', $calendar->stored(), 'the calendar is the person\'s under any persona');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /** A queued question keeps its persona through the worker: the job carries `chat:tesla`. */
    #[DataProvider('databases')]
    public function testAQueuedQuestionKeepsItsPersona(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $this->answer([[200, self::says('Meow.')]]);
        $queue = new RecordingQueue();
        $asks  = new Asks(new Conversations(new BaseAssistant()), $queue);

        // Act
        $askId = $asks->start(new Scope($person, null, Scope::CHAT, 'tesla'), null, 'Hi');
        $asks->run($queue->tasks[0][1]);
        $found = $asks->find(new Scope($person, null, Scope::CHAT, 'tesla'), $askId);

        // Assert
        $this->assertSame('chat:tesla', $queue->tasks[0][1]['space']);
        $this->assertSame('answered', $found['status'], 'found under its persona');
        $this->assertNull($asks->find(new Scope($person), $askId), 'not under the default');
        $this->assertStringContainsString('you are Tesla', self::system($this->sent[0]));
        $this->db->queryBuilder()->table('assist.chat_asks')->where('userid', $person)->delete();
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /**
     * Through the API: the list with the default chosen and each one's picture; a choice kept
     * with the account and the request's scope following it; one not offered refused; the style
     * kept apart for each persona.
     */
    #[DataProvider('databases')]
    public function testThePersonChoosesThroughTheApi(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = new \Pramnos\User\User();
        $user->username = 'persona' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->users[] = (int) $user->userid;
        ApiFixture::$scope = new Scope((int) $user->userid);
        ApiFixture::$stop  = null;

        // Act
        $before = (new ApiFixture())->personas();
        ApiFixture::$input = ['persona' => 'tesla'];
        $chose  = (new ApiFixture())->persona();
        ApiFixture::$input = ['persona' => 'garfield'];
        $nope   = (new ApiFixture())->persona();
        $scope  = Personas::scopeFor(new Scope((int) $user->userid));
        \Pramnos\Llm\Chat\Acts::choose(new \Pramnos\User\User((int) $user->userid), '', 'tesla');

        // Assert
        $this->assertSame('naya', $before['data']['chosen']);
        $this->assertSame(['naya', 'tesla'], array_column($before['data']['personas'], 'key'));
        $this->assertStringContainsString('pf-tesla', $before['data']['personas'][1]['avatar'], 'her own picture');
        $this->assertSame('tesla', $chose['data']['chosen']);
        $this->assertSame(422, $nope['status']);
        $this->assertSame('tesla', $scope->persona, 'the request follows the choice');
        $this->assertSame('tesla', Personas::chosen(new \Pramnos\User\User((int) $user->userid)), 'kept with the account');
        $this->assertSame(Scope::ADMIN, Personas::scopeFor(new Scope((int) $user->userid, null, Scope::ADMIN))->space);
        $this->assertNull(Personas::scopeFor(new Scope((int) $user->userid, null, Scope::ADMIN))->persona, 'the analyst has none');
    }
}
