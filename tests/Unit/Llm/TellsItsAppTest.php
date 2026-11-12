<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Chat\RoutineReady;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\SharedWithYou;
use Pramnos\Llm\Chat\TaskReminder;

/**
 * The conversations' notifications go, by push, to the devices of the assistant's own app when
 * they come from the `chat` space and the application has an app (FW-413) — and open the app.
 *
 * Push subscriptions of an app receive only what is sent to that app, so a reminder of an
 * assistant that lives in its own app, told as the site's, reached nobody's phone.
 */
#[CoversClass(RoutineReady::class)]
#[CoversClass(TaskReminder::class)]
#[CoversClass(SharedWithYou::class)]
final class TellsItsAppTest extends LlmTestCase
{
    /**
     * From `chat` with `app_url` set: the app's devices, the push opening the app, the bell's link
     * as it was. From `admin`, or with no app: the site's, the link as it was. A notification that
     * cannot be told its space is left as it is.
     */
    public function testTheChatSpacesNotificationsGoToItsApp(): void
    {
        // Arrange
        $this->configure(['chat' => ['app_url' => 'https://app.example/']]);
        $made = [
            new RoutineReady('Daily check-in', true, '', 'https://site.example/chat?c=1'),
            new TaskReminder('Call Maria', 'https://site.example/chat?c=2'),
            new SharedWithYou('Nikos shared “Q3” with you', 'https://site.example/chat?c=3'),
        ];

        // Act
        foreach ($made as $notification) {
            RoutineReady::tag($notification, Scope::CHAT);
        }
        $admin = RoutineReady::tag(new TaskReminder('Review', 'https://site.example/admin/Ai'), Scope::ADMIN);
        $plain = RoutineReady::tag(new \stdClass(), Scope::CHAT);

        // Assert
        foreach ($made as $notification) {
            $this->assertSame('chat', $notification->pushApp(null), $notification::class);
            $this->assertSame('https://app.example/', $notification->toPush(null)['url'], $notification::class . ': the push opens the app');
            $this->assertStringStartsWith('https://site.example/', $notification->toDatabase(null)['url'], 'the bell keeps its link');
        }
        $this->assertSame('', $admin->pushApp(null), 'the administration: the site\'s devices');
        $this->assertSame('https://site.example/admin/Ai', $admin->toPush(null)['url']);
        $this->assertEquals(new \stdClass(), $plain, 'nothing to tell');
        $this->configure([]);
        $this->assertSame('', $made[0]->pushApp(null), 'no app: the site\'s devices');
        $this->assertSame('https://site.example/chat?c=1', $made[0]->toPush(null)['url']);
    }

    /**
     * Where the application says the companion tells through its own app alone
     * (`notify.channel = app`): from `chat` or any persona's `chat:<persona>`, never the site's bell
     * (`database`) nor the site's devices — the app's devices only, and with no app, nowhere —
     * unless the application asks for the bell (`companion_notify_via`). The administration's keep
     * the bell. An application's own class that names the app but not the space is the companion's
     * too. Without that setting, the site's ordinary notification.
     */
    public function testTheCompanionsNotificationsNeverReachTheSite(): void
    {
        // Arrange
        $this->configure(['chat' => ['app_url' => 'https://app.example/']]);
        $ordinary = RoutineReady::tag(new TaskReminder('Tesla left you something', ''), 'chat:tesla')->via((object) ['userid' => 0]);
        $this->configure(['chat' => ['app_url' => 'https://app.example/', 'notify' => ['channel' => 'app']]]);
        $tesla = RoutineReady::tag(new TaskReminder('Tesla left you something', ''), 'chat:tesla');
        $naya  = RoutineReady::tag(new RoutineReady('Daily check-in', true, '', ''), Scope::CHAT);
        $share = RoutineReady::tag(new SharedWithYou('Shared', ''), 'chat:tesla');
        $admin = RoutineReady::tag(new TaskReminder('Review', ''), Scope::ADMIN);
        $person = (object) ['userid' => 0];

        // Act
        $withApp  = [$tesla->pushApp($person), $tesla->via($person), $naya->via($person), $share->via($person), $admin->via($person)];
        $ownClass = RoutineReady::channels($person, Scope::CHAT);
        $this->configure(['chat' => ['notify' => ['channel' => 'app']]]);
        $noApp    = [$tesla->pushApp($person), $tesla->via($person), $naya->via($person)];
        $this->configure(['chat' => ['app_url' => 'https://app.example/', 'notify' => ['channel' => 'app'], 'companion_notify_via' => ['database', 'push']]]);
        $optedIn  = $tesla->via($person);

        // Assert
        $this->assertContains('database', $ordinary, 'without the setting, the site\'s ordinary notification');
        $this->assertSame('chat', $withApp[0], 'every persona\'s goes to the app');
        $this->assertNotContains('database', $withApp[1], 'never the site\'s bell');
        $this->assertNotContains('database', $withApp[2]);
        $this->assertNotContains('database', $withApp[3]);
        $this->assertContains('database', $withApp[4], 'the administration\'s keep the bell');
        $this->assertNotContains('database', $ownClass, 'an application\'s class naming the app');
        $this->assertSame(['', [], []], $noApp, 'no app: nowhere at all, not the site\'s devices');
        $this->assertSame(['database'], $optedIn, 'the bell only where the application asks for it');
    }

    /**
     * Each device told of its own profile (FW-490): a partition's notices go to the app as
     * `chat@<partition>`, every persona's of it too, so only that partition's devices are told; the
     * holder's stay `chat`, the devices of no partition — old subscriptions among them. With the
     * companion's own channel, a partition's app is the companion's as well.
     */
    public function testEachDeviceIsToldOfItsOwnProfile(): void
    {
        // Arrange
        $this->configure(['chat' => ['app_url' => 'https://app.example/', 'notify' => ['channel' => 'app']]]);

        // Act
        $apps = array_map(static fn (string $space): string => RoutineReady::tag(new TaskReminder('x', ''), $space)->pushApp(null),
            ['chat@3', 'chat:tesla@3', Scope::CHAT, 'chat:tesla', Scope::ADMIN]);
        $channels = RoutineReady::channels((object) ['userid' => 0], 'chat@3');

        // Assert
        $this->assertSame(['chat@3', 'chat@3', 'chat', 'chat', ''], $apps, 'a partition\'s own app; the holder\'s; the administration\'s the site\'s');
        $this->assertNotContains('database', $channels, 'a partition\'s app is the companion\'s: never the site\'s bell');
    }
}
