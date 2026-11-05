<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Notifications;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\NewSignInAlert;
use Pramnos\Auth\Notifications\NewSignInNotification;

/**
 * A sign-in to an OAuth client is named by the client, not only by the browser it embeds (FW-320).
 *
 * A desktop client's browser is its engine — an Electron app is "Chrome on Linux" — so the alert
 * named a browser the person never opened. `oauth/authorize` remembers the client's registered
 * name before sending them to sign in, and the alert shows it before the device. The device stays:
 * both are the client's own words.
 */
final class NewSignInClientTest extends TestCase
{
    private const CHROME_LINUX = 'chrome|linux';

    protected function tearDown(): void
    {
        unset($_SESSION[NewSignInAlert::CLIENT_SESSION_KEY]);
    }

    /** The client's name comes first in the mail, the push and the template; escaped in the mail. */
    public function testTheClientIsNamedBeforeTheDevice(): void
    {
        // Arrange
        $plain  = new NewSignInNotification(self::CHROME_LINUX, 1, 'Site');
        $client = new NewSignInNotification(self::CHROME_LINUX, 1, 'Site', 'Chat <Desktop>');

        // Act
        $mail = $client->toMail(null);
        $push = $client->toPush(null);

        // Assert
        $this->assertSame('Chrome on Linux', $plain->device(), 'without a client, the device alone');
        $this->assertSame('Chat <Desktop> (Chrome on Linux)', $client->device());
        $this->assertStringContainsString('Chat &lt;Desktop&gt; (Chrome on Linux)', $mail['body'], 'escaped in the body');
        $this->assertStringContainsString('Chat <Desktop> (Chrome on Linux)', $mail['subject']);
        $this->assertStringContainsString('Chat <Desktop>', $push['body']);
        $this->assertSame('Chat <Desktop> (Chrome on Linux)', $client->storedMailTemplate()['vars']['device']);
    }

    /**
     * The remembered client is taken once, and only while fresh: an abandoned authorization does
     * not name a later, ordinary sign-in.
     */
    public function testTheRememberedClientIsTakenOnceAndOnlyWhileFresh(): void
    {
        // Arrange
        $take = new \ReflectionMethod(NewSignInAlert::class, 'pendingClient');

        // Act
        NewSignInAlert::rememberClient('  Chat Desktop ');
        $first  = $take->invoke(null);
        $second = $take->invoke(null);
        NewSignInAlert::rememberClient('');
        $empty  = $take->invoke(null);
        $_SESSION[NewSignInAlert::CLIENT_SESSION_KEY] = ['name' => 'Old', 'at' => time() - NewSignInAlert::CLIENT_TTL - 1];
        $stale  = $take->invoke(null);

        // Assert
        $this->assertSame('Chat Desktop', $first);
        $this->assertSame('', $second, 'taken once');
        $this->assertSame('', $empty, 'no name, nothing remembered');
        $this->assertSame('', $stale, 'too old to be this sign-in\'s');
        $this->assertArrayNotHasKey(NewSignInAlert::CLIENT_SESSION_KEY, $_SESSION);
    }
}
