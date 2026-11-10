<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Conversations as Chat;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Clients;
use Pramnos\Conversations\ConversationNotification;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Http\Request;
use Pramnos\Push\Subscriptions;

/**
 * A message is notified in the client its reader was last active in — a tab, the desktop
 * application — and in every client once they are away, as Slack, Teams and Discord do: by push
 * to that tab's subscription alone, and by `notify` on the realtime telling. The bell, always.
 */
#[CoversClass(Clients::class)]
#[CoversClass(ConversationNotification::class)]
#[CoversClass(\Pramnos\Conversations\Realtime::class)]
#[CoversClass(Subscriptions::class)]
#[CoversClass(Chat::class)]
class ClientsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** Put the real broadcasting back, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        FakeDriver::restore();
        unset(Application::getInstance()->applicationInfo['features']);
        $this->probeTearDown();
    }

    /**
     * Which client is told: the last one active; every one (null) once the person has been away
     * longer than their minutes — by silence, or by an idle or locked system; with "at once", a
     * client not in front counts as away; nobody known, or a report with no usable id, is every
     * client, as before.
     */
    #[DataProvider('databases')]
    public function testTheClientToTellIsTheOneThePersonIsIn(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $at = 1_800_000_000;

        // Act
        Clients::active($eleni, 'tab-a', null, true, false, $at);
        Clients::active($eleni, 'desktop-1', null, true, false, $at + 10);
        $last      = Clients::target($eleni, $at + 60);
        $silent    = Clients::target($eleni, $at + 10 + 301);
        Clients::active($maria, 'desktop-2', 280, true, false, $at);
        $idle      = Clients::target($maria, $at + 30);
        Clients::active($maria, 'desktop-2', null, true, true, $at);
        $locked    = Clients::target($maria, $at + 1);
        Clients::active($nikos, 'tab-n', null, false, false, $at);
        $hidden    = Clients::target($nikos, $at + 5);
        Clients::awayAfter($nikos, 0);
        $atOnce    = Clients::target($nikos, $at + 5);
        Clients::active($nikos, 'tab-n', null, true, false, $at);
        $front     = Clients::target($nikos, $at + 5);
        Clients::active($this->ids['eleni'], 'not an id!', null, true, false, $at + 20);
        $refused   = [];
        try {
            Clients::awayAfter($maria, 7);
        } catch (\InvalidArgumentException $e) {
            $refused[] = $e->getMessage();
        }

        // Assert
        $this->assertSame('desktop-1', $last, 'the last client active');
        $this->assertNull($silent, 'nothing for longer than five minutes: every client');
        $this->assertNull($idle, 'the system idle for nearly five minutes, and thirty seconds since');
        $this->assertNull($locked, 'a locked screen is away');
        $this->assertSame('tab-n', $hidden, 'a hidden tab is still where they are, on five minutes');
        $this->assertNull($atOnce, '"at once": a client not in front is away');
        $this->assertSame('tab-n', $front);
        $this->assertSame([0, 5], [Clients::awayAfter($nikos), Clients::awayAfter($eleni)], 'their choice, or the default');
        $this->assertSame([$eleni => 'desktop-1', 999999 => null], Clients::targets([$eleni, 999999], $at + 30), 'an id that is no id is ignored; nobody known: every client');
        $this->assertCount(1, $refused, 'only 0, 5, 10 or 30 minutes');

        // Act — the edges: a choice before any report, nobody to report for, nobody asked about,
        // and the table not there yet (before its migration): everybody told everywhere
        $nobody = $this->ids['maria'] + 1000;
        Clients::awayAfter($nobody, 30);
        Clients::active(0, 'tab-x', null, true, false, $at);
        Clients::active($nobody, '', null, true, false, $at);
        $none = Clients::targets([]);
        $kept = Clients::awayAfter($nobody);
        $this->db->schema()->dropTableIfExists(Clients::TABLE);
        $missing = Clients::targets([$eleni], $at + 30);
        (new \Pramnos\Framework\Migrations\Conversations\CreateConversationClientsTable($this->migrationApplication()))->up();
        (new \Pramnos\Framework\Migrations\Conversations\AddAppToConversationClients($this->migrationApplication()))->up();

        // Assert
        $this->assertSame(30, $kept, 'kept before any report');
        $this->assertSame([], $none);
        $this->assertSame([$eleni => null], $missing);
    }

    /**
     * While the desktop application runs and its person is at it, it is the client told — unless a
     * tab is in front now — and no web push goes to the browser beside it (FW-397).
     *
     * The application tells the system's idle seconds, every minute, and nothing else does: that is
     * how its report is known. In production, with the application open behind other windows, every
     * message went as a web push to the browser as well, whose click opened the browser instead. Its
     * minute's report must not take the place of a tab in front; once it stops reporting, the screen
     * locks, or the system has been idle longer than the person's minutes, it is as before.
     */
    #[DataProvider('databases')]
    public function testTheDesktopApplicationNotifiesWhileItRuns(string $type, string $host, int $port, string $user): void
    {
        // Arrange — the production case: the application behind other windows, a browser subscribed
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $at = time();
        Subscriptions::store($maria, ['endpoint' => 'https://push.test/chrome', 'keys' => ['p256dh' => 'k', 'auth' => 'a']]);
        $dm = Conversations::create('dm', null, [$maria], $eleni);
        $id = Messages::post($dm, $eleni, 'γεια');
        $notification = new ConversationNotification($id, 'direct');

        // Act
        Clients::active($maria, 'desktop-1', 0, false, false, $at - 30);
        $behind  = [Clients::appRunning($maria, $at), Clients::target($maria, $at), $notification->via($this->user('maria'))];
        Clients::active($maria, 'tab-a', null, true, false, $at - 10);
        Clients::active($maria, 'desktop-1', 0, false, false, $at - 5);   // its minute's report, beside the tab
        $tabFront = Clients::target($maria, $at);
        $tabGone  = Clients::target($maria, $at + 60);                       // the tab not seen for 70 s
        Clients::active($maria, 'tab-a', null, false, false, $at - 1);     // the tab hidden
        Clients::active($maria, 'desktop-1', 0, false, true, $at);          // the screen locked
        $locked   = [Clients::appRunning($maria, $at + 1), $notification->via($this->user('maria'))];
        Clients::active($maria, 'desktop-1', 200, false, false, $at);       // idle for long, on five minutes
        $idle     = Clients::appRunning($maria, $at + 120);
        Clients::active($maria, 'desktop-1', 0, false, false, $at);
        $stopped  = Clients::appRunning($maria, $at + 181);                 // no report for three minutes

        // Assert
        $this->assertSame([true, 'desktop-1', ['database']], $behind, 'running behind other windows: it is told, no web push');
        $this->assertSame('tab-a', $tabFront, 'a tab in front now is told, and the application\'s report did not replace it');
        $this->assertSame('desktop-1', $tabGone, 'the tab gone from front: the application again');
        $this->assertSame([false, ['database', 'push']], $locked, 'a locked screen: the web push goes');
        $this->assertFalse($idle, 'idle longer than their minutes: away');
        $this->assertFalse($stopped, 'not reported for three minutes: not running');
        $this->assertFalse(Clients::appRunning($this->ids['nikos'], $at), 'nobody known');
    }

    /** An application holding this database, for a migration run by hand. */
    private function migrationApplication(): \Pramnos\Application\Application
    {
        $application = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
        $application->database = $this->db;

        return $application;
    }

    /**
     * A message: its push goes to the subscription of the tab the reader is in, and to one that
     * names no tab; its realtime telling says which client shows a notification, or `all` for a
     * reader who is away; its writer reports where they are through the controller, and sets how
     * long away means away.
     */
    #[DataProvider('databases')]
    public function testAMessageIsToldWhereItsReaderIs(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria, $nikos], $eleni);
        foreach (['tab-a' => 'https://push.test/a', 'tab-b' => 'https://push.test/b', '' => 'https://push.test/old'] as $client => $endpoint) {
            Subscriptions::store($maria, ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a']]);
            if ($client !== '') {
                Subscriptions::tagClient($maria, $endpoint, $client);
            }
        }
        $fake = FakeDriver::swap();

        // Act
        $reported = $this->ask('maria', 'active', ['client' => 'tab-a', 'front' => true]);
        $away     = $this->ask('maria', 'away', ['minutes' => 10]);
        $read     = $this->ask('maria', 'away');
        $id       = Messages::post($general, $eleni, 'γεια');
        $client   = (new ConversationNotification($id, 'message'))->pushClient($this->user('maria'));
        $tagged   = array_column(Subscriptions::forUser($maria), 'client', 'endpoint');
        ksort($tagged);
        $refusedTag = Subscriptions::tagClient($maria, 'https://push.test/a', 'not an id!');

        // Assert
        $this->assertSame(['ok' => true], $reported['body']);
        $this->assertSame(['minutes' => 10, 'choices' => Clients::CHOICES], $away['body']);
        $this->assertSame(10, $read['body']['minutes'], 'read without minutes');
        $this->assertSame('tab-a', $client, 'the push goes to the tab she is in');
        $this->assertSame(['https://push.test/a' => 'tab-a', 'https://push.test/b' => 'tab-b', 'https://push.test/old' => null], $tagged);
        $this->assertFalse($refusedTag);
        $told = array_column(array_map(static fn (array $r): array => [$r['channel'], $r['payload']['notify'] ?? null], array_values(array_filter($fake->recorded(), static fn (array $r): bool => $r['event'] === 'conversation.activity'))), 1, 0);
        $this->assertSame(['private-users.' . $maria => 'tab-a', 'private-users.' . $nikos => 'all'], $told, 'nikos was never seen: all his clients');
    }

    /** One of the controller's actions, as `$name`, with a JSON body; its status and answer. */
    private function ask(string $name, string $action, array $body = []): array
    {
        $_GET  = [];
        $_POST = [];
        Request::setRawInput((string) json_encode($body));
        $chat = new class (Application::getInstance()) extends Chat {
            /** Who asks, set by the test. */
            public ?object $asking = null;

            /** The test's account, not a session. */
            protected function me(): ?object
            {
                return $this->asking;
            }
        };
        $chat->asking = $this->user($name);
        $response     = $chat->{$action}();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
    }
}
