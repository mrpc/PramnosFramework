<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\Http\ApiClient;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Handles;
use Pramnos\Conversations\Markdown;
use Pramnos\Conversations\Messages;

/**
 * The handles that mention many at once, as Slack's — `@here`, `@channel`, `@everyone`, a group —
 * on each backend.
 *
 * Why it matters: a handle decides who gets a notification. Telling too few loses the one message
 * somebody had to see; telling too many teaches a team to mute the conversations. So each handle is
 * pinned to its audience: `@here` the members connected now (as the broadcasting server's presence
 * says) but the paused, `@channel` all of them, `@everyone` everyone who may use the conversations
 * and only in the general one, a group its members in the conversation. Never the writer, nor
 * somebody inactive.
 */
#[CoversClass(Handles::class)]
#[CoversClass(Messages::class)]
#[CoversClass(ApiClient::class)]
class HandlesTest extends TestCase
{
    use ConversationsProbe {
        tearDown as tearDownProbe;
    }

    /** Who is connected to the conversations, as the broadcasting server answers; null: it cannot be asked. */
    private function connected(?array $ids): void
    {
        Application::getInstance()->applicationInfo['broadcasting'] = ['transport' => 'websocket', 'http_api' => ['url' => 'http://rt.test'],
            'pusher' => ['app_key' => 'k', 'app_secret' => 's']];
        ApiClient::$fetch = static fn (string $url): ?string => $ids === null ? null
            : json_encode(['users' => array_map(static fn (int $id): array => ['id' => (string) $id], $ids)]);
    }

    /** Clears the server's answer for the next test. */
    protected function tearDown(): void
    {
        ApiClient::$fetch = null;
        unset(Application::getInstance()->applicationInfo['broadcasting']);
        $this->tearDownProbe();
    }

    /**
     * `@here` (and `@online`, its old name) tells the members connected now, each with a row of their
     * own; one paused by the application's `quiet` hook is not told; and when who is connected cannot
     * be asked, every member but the paused is.
     */
    #[DataProvider('databases')]
    public function testHereTellsWhoIsConnected(string $type, string $host, int $port, string $user): void
    {
        // Arrange — maria and nikos in the channel; only maria connected, and root, who is not in it
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $channel = Conversations::create('channel', 'ops', [$maria, $nikos], $eleni);
        $this->connected([$maria, $this->ids['root']]);

        // Act
        $here    = Messages::post($channel, $eleni, '@here ο server ξανά');
        $online  = Messages::post($channel, $eleni, '@online κι εδώ');
        Application::getInstance()->applicationInfo['conversations']['quiet'] = static fn (array $ids): array => [$maria];
        $paused  = Messages::post($channel, $eleni, '@here πάλι');
        unset(Application::getInstance()->applicationInfo['conversations']['quiet']);
        $this->connected(null);
        $unknown = Messages::post($channel, $eleni, '@here ποιος είναι εδώ;');

        // Assert
        $this->assertSame([$maria], $this->mentioned($here), 'only who is connected, and only of the members');
        $this->assertSame([[$maria, 'here']], $this->rows($here), 'a row for her alone');
        $this->assertSame([$maria], $this->mentioned($online), '@online is @here');
        $this->assertSame([], $this->mentioned($paused), 'her notifications paused');
        $this->assertSame([$maria, $nikos], $this->mentioned($unknown), 'presence unknown: the members');
    }

    /**
     * `@channel` (and `@all`) tells every member, connected or not, with one row for all of them;
     * a group tells its people who are in the conversation; `reach()` counts who a body would tell.
     */
    #[DataProvider('databases')]
    public function testChannelAndAGroup(string $type, string $host, int $port, string $user): void
    {
        // Arrange — nobody connected; nikos and tasos are sales, tasos not in the channel
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30,
            'groups' => ['Sales' => fn (): array => [$nikos, $this->ids['tasos']], 'here' => [$maria], 'bad name!' => [$maria]]];
        $channel = Conversations::create('channel', 'ops', [$maria, $nikos], $eleni);
        $this->connected([]);

        // Act
        $all     = Messages::post($channel, $eleni, '@channel συνάντηση στις 10');
        $legacy  = Messages::post($channel, $eleni, '@all συνάντηση');
        $sales   = Messages::post($channel, $eleni, '@sales νέα τιμολόγηση, me@sales.gr');
        $reach   = Handles::reach($channel, $eleni, '@channel και @sales');
        $none    = Handles::reach($channel, $eleni, 'τίποτα, <@' . $maria . '>');

        // Assert
        $this->assertSame([$maria, $nikos], $this->mentioned($all));
        $this->assertSame([[null, 'all']], $this->rows($all), 'one row for all the members, as @all always was');
        $this->assertSame([$maria, $nikos], $this->mentioned($legacy), '@all is @channel');
        $this->assertSame([$nikos], $this->mentioned($sales), 'the group\'s people in the conversation; an address is not a handle');
        $this->assertSame([[$nikos, 'group']], $this->rows($sales));
        $this->assertSame(2, $reach);
        $this->assertSame(0, $none, 'a person named is not a handle\'s reach');
        $this->assertSame(['sales'], array_keys(Handles::groups()), 'a handle\'s name and a name that is not one are no groups');
    }

    /**
     * `@everyone` in the general conversation tells everyone who may use the conversations, members
     * or not — not somebody below the floor; anywhere else it is `@channel`. The general one is named
     * by slug or by id, and only there does a composer offer it.
     */
    #[DataProvider('databases')]
    public function testEveryoneInTheGeneralConversation(string $type, string $host, int $port, string $user): void
    {
        // Arrange — the general channel has maria alone; tasos (11) is below the floor
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'everyone_channel' => 'general'];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $other   = Conversations::create('channel', 'ops', [$maria, $nikos], $eleni);

        // Act
        $everyone = Messages::post($general, $eleni, '@everyone αλλαγή ωραρίου');
        $outside  = Messages::post($other, $eleni, '@everyone εδώ');
        $offered  = [Handles::offered($general), Handles::offered($other)];
        Application::getInstance()->applicationInfo['conversations']['everyone_channel'] = $other;
        $byId     = Handles::isGeneral($other);
        unset(Application::getInstance()->applicationInfo['conversations']['everyone_channel']);

        // Assert
        $this->assertSame([$maria, $nikos, $this->ids['root']], $this->mentioned($everyone), 'everyone above the floor, members or not');
        $this->assertSame([$maria, $nikos], $this->mentioned($outside), 'elsewhere, @channel');
        $this->assertSame([[null, 'all']], $this->rows($outside));
        $this->assertSame([['here', 'channel', 'everyone'], ['here', 'channel']], $offered);
        $this->assertTrue($byId, 'named by id');
        $this->assertNull(Handles::generalId(), 'none set');
    }

    /**
     * A handle is marked where it is written, a group's too; an address with the same letters is not.
     */
    #[DataProvider('databases')]
    public function testHandlesAreMarked(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'groups' => ['sales' => []]];

        // Act
        $html = Markdown::render('@here @Channel @everyone @sales me@here.gr @here.now', static fn (int $id): ?string => null);

        // Assert
        $this->assertStringContainsString('<span class="pf-conv-mention pf-conv-mention-here">@here</span>', $html);
        $this->assertStringContainsString('<span class="pf-conv-mention pf-conv-mention-channel">@Channel</span>', $html);
        $this->assertStringContainsString('pf-conv-mention-everyone', $html);
        $this->assertStringContainsString('<span class="pf-conv-mention pf-conv-mention-group">@sales</span>', $html);
        $this->assertStringContainsString('me@here.gr @here.now', $html, 'an address, and a word that only starts with a handle');
    }

    /** Who a message's mention events told. @return list<int> */
    private function mentioned(int $message): array
    {
        $told = [];
        foreach ($this->dispatched as [$event, $e]) {
            if ($event === 'conversation.mention.received' && $e->messageId === $message) {
                $told[] = $e->personId;
            }
        }
        sort($told);

        return $told;
    }

    /** A message's mention rows: who, and how. @return list<array{0: ?int, 1: string}> */
    private function rows(int $message): array
    {
        return array_map(static fn (array $r): array => [$r['participantid'] === null ? null : (int) $r['participantid'], (string) $r['kind']],
            $this->db->queryBuilder()->table('#PREFIX#conversation_mentions')->where('messageid', $message)->orderBy('participantid')->getAllOrFail());
    }
}
