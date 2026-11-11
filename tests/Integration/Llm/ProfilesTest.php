<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Plan;
use Pramnos\Llm\Chat\Profiles;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * The people of an account (FW-450), on MySQL and PostgreSQL.
 *
 * What has to hold: a profile's conversations, memories and plan are its own — the account holder
 * sees none of them and they see none of the holder's; a child's profile speaks as a child; which
 * profile speaks is the request's, among the account's own, unless the device is held to one,
 * which nothing but the PIN undoes; a parent reads a child's conversations and plan, a child no
 * one's; the application's hook hears each of a profile's answers; a profile deleted takes what it
 * kept; and there are no more than the application allows.
 */
#[CoversClass(Profiles::class)]
#[CoversClass(Scope::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class ProfilesTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        unset($_SERVER[Profiles::HEADER], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_METHOD']);
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** An account to make profiles for. */
    private function user(): \Pramnos\User\User
    {
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class, \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $mark = 'pr' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();

        return new \Pramnos\User\User($id);
    }

    /**
     * Each profile's own: its conversations, memory and plan apart from the holder's; a child's
     * spoken to as a child; made no more than the application allows; deleted with what it kept.
     */
    #[DataProvider('databases')]
    public function testAProfilesThingsAreItsOwn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['profiles' => ['limit' => static fn (object $u): int => 2], 'plan' => true, 'memory' => ['learn' => false]]]);
        $user   = $this->user();
        $holder = new Scope((int) $user->userid, null);
        $bad    = Profiles::create($user, ['name' => '']);
        $child  = Profiles::create($user, ['name' => 'Niko', 'kind' => 'child', 'birth_year' => (int) date('Y') - 6]);
        $other  = Profiles::create($user, ['name' => 'Maria', 'kind' => 'parent']);
        $third  = Profiles::create($user, ['name' => 'Eleni']);
        $asChild = $holder->withProfile($child['profile_id']);
        (new Plan($holder))->add(['project' => 'Kitchen', 'status' => 'two quotes']);
        (new Plan($asChild))->add(['project' => 'Maths test']);
        $this->answer([[200, self::says('Hi Niko')], [200, self::says('Hello')]]);

        // Act
        $childTalk  = (new Conversations(new BaseAssistant()))->as($asChild)->ask(null, 'Hello', null, []);
        $childPrompt = (string) json_encode($this->sent[0]['body']['messages'], JSON_UNESCAPED_UNICODE);
        $holderTalk = (new Conversations(new BaseAssistant()))->as($holder)->ask(null, 'Hi');
        $seenByHolder = (new Conversations(new BaseAssistant()))->as($holder)->find($childTalk['conversation_id']);
        $space      = $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', $childTalk['conversation_id'])->value('space');
        $plans      = [array_column((new Plan($holder))->projects(), 'project'), array_column((new Plan($asChild))->projects(), 'project')];
        $cleared    = Profiles::clear((int) $user->userid, $child['profile_id'], 2);
        $afterClear = [$this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', $childTalk['conversation_id'])->count(), Profiles::get((int) $user->userid, $child['profile_id']) !== null];
        $deleted    = Profiles::delete((int) $user->userid, $child['profile_id']);
        $left       = $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', $childTalk['conversation_id'])->count();

        // Assert
        $this->assertSame([6, 'child'], [$child['age'], $child['kind']]);
        $this->assertSame('This account has as many profiles as it may.', $third, 'no more than the application allows');
        $this->assertSame('A profile needs a name.', $bad);
        $this->assertSame('chat@' . $child['profile_id'], $space, 'kept under its own space');
        $this->assertNull($seenByHolder, 'the holder does not see it as their own');
        $this->assertSame([['Kitchen'], ['Maths test']], $plans, 'each plan its own');
        $this->assertStringContainsString('You are speaking with a child.', $childPrompt, 'a child\'s profile speaks as a child');
        $this->assertStringNotContainsString('two quotes', $childPrompt, 'never the holder\'s plan');
        $this->assertNotNull($holderTalk['conversation_id']);
        $this->assertSame([true, 0, true], [$cleared, ...$afterClear], 'cleared: what it kept gone, the profile kept');
        $this->assertTrue($deleted);
        $this->assertSame(0, $left, 'deleted with what it kept');
        $this->assertSame('parent', $other['kind']);
    }

    /**
     * Which profile speaks: the request's, among the account's own; a device held to a child's
     * speaks as it whatever the request says, the lock made only with a PIN and undone only by
     * it; a few wrong PINs, and it waits.
     */
    #[DataProvider('databases')]
    public function testADeviceHeldToAChildIsReleasedOnlyByThePin(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['profiles' => true]]);
        $user   = $this->user();
        $holder = new Scope((int) $user->userid, null);
        $child  = Profiles::create($user, ['name' => 'Niko', 'kind' => 'child'])['profile_id'];
        $parent = Profiles::create($user, ['name' => 'Maria', 'kind' => 'parent'])['profile_id'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer device-' . bin2hex(random_bytes(4));

        // Act
        $_SERVER[Profiles::HEADER] = (string) $parent;
        $asked      = Profiles::forRequest($holder)->profile;
        $_SERVER[Profiles::HEADER] = '999999';
        $notTheirs  = Profiles::forRequest($holder)->profile;
        $noPin      = Profiles::lock($user, $child);
        $badPin     = Profiles::setPin($user, '12');
        Profiles::setPin($user, '4321');
        \Pramnos\User\User::clearUserCache();
        $user       = new \Pramnos\User\User((int) $user->userid);
        $locked     = Profiles::lock($user, $child);
        $_SERVER[Profiles::HEADER] = (string) $parent;
        $held       = Profiles::forRequest($holder)->profile;
        unset($_SERVER[Profiles::HEADER]);
        $heldBare   = Profiles::forRequest($holder)->profile;
        $wrong      = Profiles::unlock($user, '0000');
        $released   = Profiles::unlock($user, '4321');
        $free       = Profiles::forRequest($holder)->profile;

        // Assert
        $this->assertSame($parent, $asked, 'the profile the request names');
        $this->assertNull($notTheirs, 'never another account\'s');
        $this->assertSame('Set a PIN first: it is what unlocks this device again.', $noPin);
        $this->assertSame('A PIN is four to eight digits.', $badPin);
        $this->assertNull($locked);
        $this->assertSame([$child, $child], [$held, $heldBare], 'held to the child, whatever the request says');
        $this->assertSame('That is not the PIN.', $wrong);
        $this->assertNull($released);
        $this->assertNull($free, 'released: the holder again');
    }

    /**
     * A parent reads a child's conversations, with a summary, and the child's plan in short, also
     * through the API and by asking the assistant; a child reads no one's; the application's hook
     * hears a profile's answers.
     */
    #[DataProvider('databases')]
    public function testAParentReadsAChildsConversationsAndTheApplicationHearsEachAnswer(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $heard = [];
        $this->configure(['chat' => ['plan' => true, 'memory' => ['learn' => false], 'profiles' => ['after' => static function (Scope $s, array $p, string $q, string $a, int $c) use (&$heard): void {
            $heard[] = [$p['name'], $q, $a];
        }]]]);
        $user    = $this->user();
        $holder  = new Scope((int) $user->userid, null);
        $child   = Profiles::create($user, ['name' => 'Niko', 'kind' => 'child'])['profile_id'];
        $asChild = $holder->withProfile($child);
        (new Plan($asChild))->add(['project' => 'Maths test', 'blockers' => 'fractions']);
        $this->answer([[200, self::says('Well done!')], [200, self::says('Hi')]]);
        $talk = (new Conversations(new BaseAssistant()))->as($asChild)->ask(null, 'I finished my homework');

        // Act
        $read     = Profiles::conversationsOf($holder, $child);
        $one      = Profiles::conversationOf($holder, $child, $talk['conversation_id']);
        $byChild  = Profiles::conversationsOf($asChild, $child);
        $tool     = Profiles::tools($holder)['profile_activity']['run'](['name' => 'Niko']);
        $none     = Profiles::tools($asChild);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ApiFixture::$scope = $holder;
        ApiFixture::$stop  = null;
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);
        $plan   = $router->dispatch(\Pramnos\Http\Request::create('/chat/profiles/' . $child . '/plan', 'GET'));
        $list   = $router->dispatch(\Pramnos\Http\Request::create('/chat/profiles', 'GET'));

        // Assert
        $this->assertStringStartsWith('Niko had 1 conversations in the last 7 days, 2 messages in all', $read['summary']);
        $this->assertSame([$talk['conversation_id']], array_column($read['conversations'], 'conversation_id'));
        $this->assertSame('I finished my homework', $one['messages'][0]['content'], 'the conversation, whole');
        $this->assertSame('There is no such profile.', $byChild, 'a child reads no one\'s');
        $this->assertStringContainsString('Maths test', $tool, 'asked of the assistant: their talk and their plan');
        $this->assertSame([], $none, 'no such tool for a child');
        $this->assertSame([['Niko', 'I finished my homework', 'Well done!']], $heard, 'the application hears each of a profile\'s answers');
        $this->assertSame(200, $plan['status']);
        $this->assertStringContainsString('1 in the plan: 1 active; blocked: Maths test', $plan['data']['summary'], 'the plan in short first');
        $this->assertSame(['Niko'], array_column($list['data']['profiles'], 'name'));
    }

    /**
     * The application's own children as profiles: made once, by their key, and kept up to date;
     * one no longer given marked detached, never deleted, what it said kept — the holder lets go
     * of its source to keep it as their own; a profile made by hand never touched; and the
     * summary in the page's language.
     */
    #[DataProvider('databases')]
    public function testTheApplicationsChildrenAreProfilesKeptUpToDate(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $children = ['child:1' => ['name' => 'Niko', 'kind' => 'child', 'birth_year' => 2019], 'child:2' => ['name' => 'Eleni', 'kind' => 'child', 'birth_year' => 2021]];
        $this->configure(['chat' => ['profiles' => ['limit' => static fn (): int => 1, 'from' => static function (int $u) use (&$children): array {
            return $children;
        }]]]);
        $user = $this->user();
        $own  = Profiles::create($user, ['name' => 'Maria', 'kind' => 'parent']);

        // Act
        $made     = Profiles::sync((int) $user->userid);
        $again    = Profiles::sync((int) $user->userid);
        $children['child:1']['name'] = 'Nikos';
        unset($children['child:2']);
        Profiles::sync((int) $user->userid);
        $after    = array_column(Profiles::of((int) $user->userid), null, 'name');
        $kept     = Profiles::update((int) $user->userid, $after['Eleni']['profile_id'], ['source' => null]);
        Profiles::sync((int) $user->userid);
        $finally  = array_column(Profiles::of((int) $user->userid), null, 'name');

        // Assert
        $this->assertSame([2, 0], [$made, $again], 'made once, past the limit the application set for its own');
        $this->assertSame(['child:1', false, 2019], [$after['Nikos']['source'], $after['Nikos']['detached'], $after['Nikos']['birth_year']], 'kept up to date');
        $this->assertSame(['child:2', true], [$after['Eleni']['source'], $after['Eleni']['detached']], 'no longer given: detached, not deleted');
        $this->assertSame([null, false], [$kept['source'], $kept['detached']], 'kept as the holder\'s own');
        $this->assertArrayHasKey('Eleni', $finally, 'and left alone after');
        $this->assertNull($finally['Maria']['source'], 'one made by hand never touched');
        $this->assertSame($own['profile_id'], $finally['Maria']['profile_id']);
    }
}
