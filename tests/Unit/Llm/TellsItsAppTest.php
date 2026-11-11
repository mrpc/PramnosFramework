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
}
