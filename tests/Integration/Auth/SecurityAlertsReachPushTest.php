<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Notifications\NewSignInNotification;
use Pramnos\Auth\Notifications\SecondFactorCodeNotification;
use Pramnos\Auth\Notifications\SecurityChangeNotification;
use Pramnos\Auth\SecurityChangeNotifier;
use Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Push\Subscriptions;

/**
 * The security alerts reach a subscribed browser, and the credentials do not.
 *
 * Web push shipped complete — a key pair, subscriptions, a channel, a log, a service worker, a
 * browser script, a soft prompt — and **nothing in the authentication flow ever sent one**. The
 * only senders were the mass-message screen and the per-user message screen, both of them an
 * operator typing something by hand. The notifications a push is actually for went by email
 * alone.
 *
 * The line drawn here is between an **alert** and a **credential**, and it is not a detail:
 * a sign-in code or an auth link delivered to a subscribed browser is delivered to whoever holds
 * that device. Mail is the deliberate second channel for those, and stays the only one.
 */
#[CoversClass(NewSignInNotification::class)]
#[CoversClass(SecurityChangeNotification::class)]
class SecurityAlertsReachPushTest extends BaseTestCase
{
    private $db;

    private int $userId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = \Pramnos\Framework\Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->runMigrations([CreatePushSubscriptionsTable::class], $this->db);
        $this->userId = 800000 + random_int(1, 90000);
    }

    protected function tearDown(): void
    {
        try {
            $this->db->queryBuilder()->table('pramnos.pushsubscriptions')
                ->where('userid', $this->userId)->delete();
        } catch (\Throwable) {
            // Nothing to undo.
        }

        parent::tearDown();
    }

    /**
     * A new-sign-in alert goes to mail and to push once the account has a browser.
     */
    public function testANewSignInAlertReachesASubscribedBrowser(): void
    {
        // Arrange
        $this->subscribe();

        // Act
        $channels = (new NewSignInNotification('chrome|windows'))->via($this->notifiable());

        // Assert
        $this->assertContains('mail', $channels, 'mail is the copy that survives everything');
        $this->assertContains('push', $channels);
    }

    /**
     * And is mail alone for an account with no subscription.
     *
     * Not a failure — most accounts have never granted permission. Adding `push` regardless
     * would write a "nothing subscribed" row to the push log on every alert, for every one of
     * them.
     */
    public function testAnAccountWithNoBrowserGetsMailAlone(): void
    {
        // Act — nothing subscribed
        $channels = (new NewSignInNotification('chrome|windows'))->via($this->notifiable());

        // Assert
        $this->assertSame(['mail'], $channels);
    }

    /**
     * The push carries the device and no link.
     *
     * A notification that appears unprompted and offers a button to secure your account is the
     * shape of the attack it warns about. It says what happened; the mail carries the actions,
     * behind a signed token.
     */
    public function testTheNewSignInPushSaysWhatHappenedAndOffersNoLink(): void
    {
        // Act
        $push = (new NewSignInNotification('safari|ios'))->toPush($this->notifiable());

        // Assert
        $this->assertNotSame('', $push['title']);
        $this->assertStringContainsString('iPhone', $push['body'], 'in words, not `safari|ios`');
        $this->assertArrayNotHasKey('url', $push, 'no link that acts');
        $this->assertArrayNotHasKey('actions', $push);
        $this->assertSame('newsignin', $push['tag'], 'two sign-ins do not stack into two');
    }

    /**
     * A security change reaches push too — and the copy to the former address does not.
     *
     * An address change sends two mails, to the old address and the new, and both are the same
     * account. Pushing on both would deliver the same warning to the same devices twice.
     */
    public function testASecurityChangePushesOnceEvenWhenTwoMailsGoOut(): void
    {
        // Arrange
        $this->subscribe();

        // Act
        $toNew = (new SecurityChangeNotification(SecurityChangeNotifier::EMAIL))
            ->via($this->notifiable());
        $toOld = (new SecurityChangeNotification(SecurityChangeNotifier::EMAIL, '', true))
            ->via($this->notifiable());

        // Assert
        $this->assertContains('push', $toNew);
        $this->assertSame(['mail'], $toOld, 'the former-address copy is mail alone');
    }

    /**
     * Each kind of change gets its own tag.
     *
     * A password change and a factor being removed are different facts. Collapsing them would
     * hide the one somebody did not do behind the one they did.
     */
    public function testEachKindOfChangeHasItsOwnTag(): void
    {
        // Act
        $password = (new SecurityChangeNotification(SecurityChangeNotifier::PASSWORD))
            ->toPush($this->notifiable());
        $factor = (new SecurityChangeNotification(SecurityChangeNotifier::FACTOR_REMOVED))
            ->toPush($this->notifiable());

        // Assert
        $this->assertNotSame($password['tag'], $factor['tag']);
        $this->assertStringContainsString('password', strtolower($password['body']));
    }

    /**
     * A sign-in code is **not** pushed, however many browsers are subscribed.
     *
     * The distinction the whole change rests on. A code is a credential: delivered to a
     * subscribed browser it is delivered to whoever holds that device, which is the person the
     * second factor exists to stop. Mail is the deliberate second channel and stays the only
     * one.
     */
    public function testASignInCodeIsNeverPushed(): void
    {
        // Arrange
        $this->subscribe();

        // Act
        $channels = (new SecondFactorCodeNotification('123456'))->via($this->notifiable());

        // Assert
        $this->assertSame(['mail'], $channels);
        $this->assertFalse(
            method_exists(SecondFactorCodeNotification::class, 'toPush'),
            'and there is no way to send it as one'
        );
    }

    /** A browser that agreed to receive notifications for this account. */
    private function subscribe(): void
    {
        Subscriptions::store($this->userId, [
            'endpoint' => 'https://push.example/' . bin2hex(random_bytes(8)),
            'keys'     => ['p256dh' => 'key', 'auth' => 'secret'],
        ], 'Firefox/143.0');
    }

    /**
     * A notifiable that routes push itself is asked, before its properties are read.
     *
     * `routeNotificationFor('push')` is how an object says "send mine to this account instead" —
     * a shared mailbox notifying its owner, a service account notifying an operator. Reading
     * `userid` first would push to the object rather than to whoever it named, and both channels
     * resolve it in this order, so an object that works for the database channel has to work here.
     */
    public function testANotifiableThatRoutesPushIsAskedFirst(): void
    {
        // Arrange — the routed account is the subscribed one; the object's own id is not.
        $this->subscribe();
        $routed = new class ($this->userId) {
            public int $userid = 999999;

            public function __construct(private int $account)
            {
            }

            public function routeNotificationFor(string $channel): mixed
            {
                return $channel === 'push' ? $this->account : null;
            }
        };

        // Act
        $channels = (new NewSignInNotification('fp'))->via($routed);

        // Assert
        $this->assertContains('push', $channels, 'the routed account was not the one checked');
    }

    /**
     * The same resolution, in the security-change alert.
     *
     * Two notifications with a private copy of `accountOf()` each — so a fix to one is not a fix
     * to the other, and only a test of both notices.
     */
    public function testASecurityChangeResolvesTheAccountTheSameWay(): void
    {
        // Arrange
        $this->subscribe();
        $routed = new class ($this->userId) {
            public int $userid = 999999;

            public function __construct(private int $account)
            {
            }

            public function routeNotificationFor(string $channel): mixed
            {
                return $channel === 'push' ? $this->account : null;
            }
        };

        // Act
        $routedChannels   = (new SecurityChangeNotification('password'))->via($routed);
        $anonymousChannels = (new SecurityChangeNotification('password'))->via(new class () {
        });

        // Assert
        $this->assertContains('push', $routedChannels);
        $this->assertSame(['mail'], $anonymousChannels);
    }

    /**
     * A notifiable that is none of those gets mail, not an error.
     *
     * `via()` runs before anything is sent, so an object the framework cannot identify has to
     * degrade to the channel that does not need an account id. Throwing here would lose the
     * email as well as the push.
     */
    public function testANotifiableWithNoAccountStillGetsMail(): void
    {
        // Arrange
        $anonymous = new class () {
        };

        // Act
        $channels = (new NewSignInNotification('fp'))->via($anonymous);

        // Assert
        $this->assertSame(['mail'], $channels);
    }

    /** The account, as `notify()` would hand it over. */
    private function notifiable(): object
    {
        return new class ($this->userId) {
            public function __construct(public int $userid)
            {
            }
        };
    }
}
