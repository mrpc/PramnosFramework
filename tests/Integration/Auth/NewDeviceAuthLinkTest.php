<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\NewDeviceAuthLink;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The single-use link that authorises a sign-in from an unknown device.
 *
 * The whole class had never been executed — 109 statements, none of them run by anything.
 * That matters more here than in most places: this is the one new-device action every account
 * can satisfy, so it is the one an installation actually falls back on, and what makes it safe
 * is not the token but the four rules around it. **Single use**, **fifteen minutes**, **one
 * link at a time**, and **a rate limit on a button anybody with a correct password can reach**.
 * Each is the whole security of the method when the others are absent.
 *
 * No mail is sent. `send()` writes the store before it hands anything to a notifier, and what
 * is asserted here is the store: the hash, the expiry, and the fact that issuing again kills
 * the link the person is already holding.
 *
 * Runs on MySQL, like the other tests that touch `authserver.*` — the prefix resolves to
 * `authserver_` in the same database there, and the fixture builds it from the real migrations
 * so the test cannot pass against a schema nobody ships.
 */
#[CoversClass(NewDeviceAuthLink::class)]
class NewDeviceAuthLinkTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private ?array $savedInstances = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL, like the other authserver.* tests.');
        }

        User::setupDb();
        $this->buildTables();
        $this->enableAuthFeature();

        $user = new User();
        $user->username = 'authlink_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->save();
        $this->uid = (int) $user->userid;
    }

    protected function tearDown(): void
    {
        foreach (['authserver.user_activity_log'] as $table) {
            try {
                $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
            } catch (\Throwable $exception) {
                // Mid-migration installations have no such table; nothing to undo.
            }
        }

        if ($this->uid > 0) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#userdetails')
                    ->where('userid', $this->uid)->delete();
                $this->db->queryBuilder()->table('#PREFIX#users')
                    ->where('userid', $this->uid)->delete();
            } catch (\Throwable $exception) {
                // As above.
            }
        }

        if ($this->savedInstances !== null) {
            $reflection = new \ReflectionProperty(Application::class, 'appInstances');
            $reflection->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    // ── The link's lifetime ───────────────────────────────────────────────────

    /**
     * A link is stored as a hash and consumed exactly once.
     *
     * Single use is not a nicety: the mail sits in an inbox afterwards, and a mail provider's
     * link-preview fetch counts as an open. A link that stayed usable would be a credential
     * with a fifteen-minute window and no owner.
     */
    public function testALinkSignsInOnceAndOnlyOnce(): void
    {
        // Arrange
        $link  = new NewDeviceAuthLink($this->db);
        $token = $this->issue($link);

        // Act
        $first  = $link->consume($token);
        $second = $link->consume($token);

        // Assert
        $this->assertSame($this->uid, $first);
        $this->assertNull($second, 'the same link authorised a second sign-in');
        $this->assertSame('', $this->detail(NewDeviceAuthLink::FIELD_HASH), 'the hash outlived the link');
    }

    /**
     * Only the hash is stored, never the token.
     *
     * A leaked `userdetails` row hands out no working links. That matters more here than for
     * a password reset: this token signs the holder in rather than asking them to choose a
     * password.
     */
    public function testOnlyTheHashIsStored(): void
    {
        // Arrange
        $link  = new NewDeviceAuthLink($this->db);
        $token = $this->issue($link);

        // Act
        $stored = $this->detail(NewDeviceAuthLink::FIELD_HASH);

        // Assert
        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertNotSame($token, $stored);
        $this->assertSame(
            0,
            (int) $this->db->query(
                'SELECT COUNT(*) AS c FROM `' . $this->db->prefix . 'userdetails` '
                . "WHERE value = '" . $token . "'"
            )->fields['c'],
            'the raw token is in the table somewhere'
        );
    }

    /** An expired link is refused, and cleared rather than left to be found later. */
    public function testAnExpiredLinkIsRefusedAndCleared(): void
    {
        // Arrange
        $link  = new NewDeviceAuthLink($this->db);
        $token = $this->issue($link);
        $this->setExpiry(time() - 1);

        // Act
        $result = $link->consume($token);

        // Assert
        $this->assertNull($result);
        $this->assertSame('', $this->detail(NewDeviceAuthLink::FIELD_HASH));
        $this->assertContains('newdevice_authlink_expired', $this->loggedActions());
    }

    /** A link that expires this very second is still good — the boundary is `<`, not `<=`. */
    public function testALinkExpiringNowIsStillAccepted(): void
    {
        // Arrange
        $link  = new NewDeviceAuthLink($this->db);
        $token = $this->issue($link);
        $this->setExpiry(time());

        // Assert
        $this->assertSame($this->uid, $link->consume($token));
    }

    /** Fifteen minutes, from the class rather than from a number somebody remembers. */
    public function testTheStoredExpiryIsTheDeclaredTtl(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);
        $before = time();
        $this->issue($link);

        // Act
        $expires = (int) $this->detail(NewDeviceAuthLink::FIELD_EXPIRES);

        // Assert
        $this->assertGreaterThanOrEqual($before + NewDeviceAuthLink::TTL, $expires);
        $this->assertLessThanOrEqual(time() + NewDeviceAuthLink::TTL, $expires);
    }

    /**
     * Issuing again invalidates the link the person is already holding.
     *
     * Somebody who clicks "send it again" must not end up with two live ways in — a mailbox
     * with two valid links is two credentials for one login, and the older one is the one
     * nobody is watching.
     */
    public function testIssuingAgainKillsThePreviousLink(): void
    {
        // Arrange
        $link  = new NewDeviceAuthLink($this->db);
        $first = $this->issue($link);
        $this->rewindSends(NewDeviceAuthLink::RESEND_INTERVAL + 5);
        $second = $this->issue($link);

        // Assert
        $this->assertNotSame($first, $second, 'the same token was issued twice');
        $this->assertNull($link->consume($first), 'the old link still works');
        $this->assertSame($this->uid, $link->consume($second));
    }

    /** Nonsense, an empty string and a token nobody issued all answer null rather than throwing. */
    public function testATokenNobodyIssuedIsRefused(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);

        // Assert
        $this->assertNull($link->consume(''));
        $this->assertNull($link->consume('   '));
        $this->assertNull($link->consume(bin2hex(random_bytes(32))));
        $this->assertNull($link->consume("' OR 1=1 --"));
    }

    // ── Who may be sent one ───────────────────────────────────────────────────

    /**
     * An account with no usable address gets nothing, and no token is stored.
     *
     * The link is the mailbox test. Storing a token for an account whose mail cannot be
     * delivered would leave a live credential nobody can reach — and, worse, a login the
     * person can never complete with no explanation.
     */
    public function testAnAccountWithNoUsableAddressIsRefused(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->uid)->update(['email' => 'not-an-address']);

        // Act
        $sent = $link->send($this->uid);

        // Assert
        $this->assertFalse($sent);
        $this->assertSame('', $this->detail(NewDeviceAuthLink::FIELD_HASH));
    }

    /**
     * User 0 and user 1 are refused before anything is read.
     *
     * `userid < 2` is the framework's own guard for "not a real account": 0 is anonymous and 1
     * is the system user, which has no mailbox and must never be signable-in by mail.
     */
    public function testTheSystemAndAnonymousAccountsAreRefused(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);

        // Assert
        $this->assertFalse($link->send(0));
        $this->assertFalse($link->send(1));
        $this->assertFalse($link->send(-5));
    }

    // ── The rate limit ────────────────────────────────────────────────────────

    /**
     * Two links inside a minute is one link.
     *
     * The button sits behind a correct password, so anybody who has phished one can hold it
     * down. The gap is what stops the mailbox being used as an outbound relay.
     */
    public function testASecondLinkWithinTheIntervalIsRefused(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);
        $this->issue($link);

        // Assert
        $this->assertFalse($link->maySend($this->uid));
        $this->assertFalse($link->send($this->uid));
    }

    /** And after the gap, another one is allowed. */
    public function testAfterTheIntervalAnotherLinkIsAllowed(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);
        $this->issue($link);

        // Act
        $this->rewindSends(NewDeviceAuthLink::RESEND_INTERVAL + 5);

        // Assert
        $this->assertTrue($link->maySend($this->uid));
    }

    /**
     * Five in the window, then no more — even with the gap respected each time.
     *
     * The interval alone only slows a flood down; the count is what ends it.
     */
    public function testTheWindowLimitStopsAFlood(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);
        for ($i = 0; $i < NewDeviceAuthLink::MAX_SENDS; $i++) {
            $this->recordSend(time() - (NewDeviceAuthLink::RESEND_INTERVAL + 5) * ($i + 1));
        }

        // Assert
        $this->assertFalse(
            $link->maySend($this->uid),
            NewDeviceAuthLink::MAX_SENDS . ' sends in the window did not exhaust the limit'
        );
    }

    /** Sends older than the window do not count against it. */
    public function testSendsOlderThanTheWindowAreForgotten(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);
        for ($i = 0; $i < NewDeviceAuthLink::MAX_SENDS + 2; $i++) {
            $this->recordSend(time() - NewDeviceAuthLink::SEND_WINDOW - 60 - $i);
        }

        // Assert
        $this->assertTrue($link->maySend($this->uid), 'the window is not a window');
    }

    /**
     * With no activity log at all, the send is **allowed**.
     *
     * The documented decision, asserted because it is the surprising one: refusing every link
     * when a log table is missing would refuse the login itself, and a half-migrated
     * installation must not lock everybody out of their own accounts.
     */
    public function testWithNoActivityLogTheSendIsAllowed(): void
    {
        // Arrange
        $link = new NewDeviceAuthLink($this->db);
        $this->issue($link);
        $this->assertFalse($link->maySend($this->uid), 'precondition: the limit applies');

        // Act
        $this->db->query(
            'DROP TABLE IF EXISTS `' . $this->db->prefix . 'authserver_user_activity_log`'
        );
        \Pramnos\Auth\ActivityLog::resetTableCache();

        // Assert
        $this->assertTrue($link->maySend($this->uid));
    }

    // ── Sending one ───────────────────────────────────────────────────────────

    /**
     * The happy path, end to end: a token stored, a notification handed over, a send recorded.
     *
     * The fifteen lines nothing had ever run. Every other test here reaches the store through
     * the class's own private path, which proves the rules but not that `send()` applies them in
     * the right order — and the order is the whole safety: refuse *before* generating, store
     * *before* mailing, record *after* both.
     */
    public function testSendStoresALinkAndHandsOverOneNotification(): void
    {
        // Arrange
        $link = $this->sendingLink();

        // Act
        $sent = $link->send($this->uid, '/account');

        // Assert
        $this->assertTrue($sent);
        $this->assertCount(1, $link->delivered, 'one notification, or a mailbox with two live links');
        $this->assertInstanceOf(
            \Pramnos\Auth\Notifications\NewDeviceAuthLinkNotification::class,
            $link->delivered[0]
        );
        $this->assertNotSame('', $this->detail(NewDeviceAuthLink::FIELD_HASH), 'nothing was stored');
        $this->assertContains('newdevice_authlink_sent', $this->loggedActions());
    }

    /**
     * The link in the mail is the link the store will accept.
     *
     * Asserted because the two are produced in different places — the token is hashed into
     * `userdetails` and then formatted into a URL — and a mismatch would be a feature that fails
     * only for real users, never for a test that reads the store directly.
     */
    public function testTheMailedLinkIsTheOneTheStoreAccepts(): void
    {
        // Arrange
        $link = $this->sendingLink();

        // Act
        $link->send($this->uid);
        $url = $link->delivered[0]->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // Assert
        $this->assertArrayHasKey('token', $query);
        $this->assertSame($this->uid, $link->consume((string) $query['token']));
    }

    /**
     * A notifier that throws leaves no send recorded and answers false.
     *
     * A dead mail server must not report a link as sent: the person would be told to check an
     * inbox nothing is coming to, and the failed attempt would count against their rate limit.
     * The stored token is left behind on purpose — it is unreachable, and clearing it would
     * revoke a link an earlier, successful send may still have out there.
     */
    public function testAFailedDeliveryIsNotReportedAsSent(): void
    {
        // Arrange
        $link = $this->sendingLink(fail: true);

        // Act
        $sent = $link->send($this->uid);

        // Assert
        $this->assertFalse($sent);
        $this->assertNotContains('newdevice_authlink_sent', $this->loggedActions());
    }

    // ── The link the mail carries ─────────────────────────────────────────────

    /**
     * The URL points at the endpoint that consumes it, and carries the return path.
     *
     * The return path travels in the link so the person lands where they were going, and it is
     * read back through the controller's own return-url handling — the one place open-redirect
     * filtering lives. Asserted as encoded, because a raw one would let the `&` in a return
     * path invent parameters.
     */
    public function testTheUrlCarriesTheTokenAndTheReturnPathEncoded(): void
    {
        // Arrange
        $link   = new NewDeviceAuthLink($this->db);
        $method = new \ReflectionMethod(NewDeviceAuthLink::class, 'url');

        // Act
        $plain = (string) $method->invoke($link, 'abc123', '');
        $with  = (string) $method->invoke($link, 'abc123', '/account?tab=devices&x=1');

        // Assert
        $this->assertStringContainsString('login/authlink?token=abc123', $plain);
        $this->assertStringNotContainsString('&return=', $plain, 'an empty return path is not a parameter');
        $this->assertStringContainsString('&return=' . urlencode('/account?tab=devices&x=1'), $with);
        $this->assertStringNotContainsString('return=/account?tab=devices&x=1', $with);
    }

    /** The method name the step-up flow registers it under. */
    public function testItIdentifiesItselfAsAuthlink(): void
    {
        // Assert
        $this->assertSame('authlink', NewDeviceAuthLink::METHOD);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** Built from the real migrations, so the test cannot pass against a schema nobody ships. */
    private function buildTables(): void
    {
        $this->db->query(
            'DROP TABLE IF EXISTS `' . $this->db->prefix . 'authserver_user_activity_log`'
        );
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
        ], $this->db);
        \Pramnos\Auth\ActivityLog::resetTableCache();
    }

    /**
     * `ActivityLog::record()` gates on the `auth` feature, and the send accounting reads the
     * rows it writes — without the feature the log is a silent no-op and the rate limit cannot
     * be observed at all.
     */
    private function enableAuthFeature(): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['features' => ['auth', 'authserver']];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $instances  = $reflection->getValue() ?? [];
        $this->savedInstances = $instances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);

        \Pramnos\Application\FeatureRegistry::loadFromConfig(['auth', 'authserver']);
    }

    /**
     * Issue a link and return the raw token.
     *
     * `send()` mails, and a mailer is not what these tests are about — so the store is written
     * the way `send()` writes it, through the class's own private path, and the token comes
     * back. Everything after storage is the notifier's business and is covered where the
     * notification is.
     */
    private function issue(NewDeviceAuthLink $link): string
    {
        $token = bin2hex(random_bytes(32));

        $store = new \ReflectionMethod(NewDeviceAuthLink::class, 'store');
        $this->assertTrue(
            (bool) $store->invoke($link, $this->uid, hash('sha256', $token), time() + NewDeviceAuthLink::TTL),
            'the fixture could not store a link'
        );

        \Pramnos\Auth\ActivityLog::record($this->uid, 'newdevice_authlink_sent');

        return $token;
    }

    /**
     * The class with its notifier replaced, recording what it was handed.
     *
     * `notifier()` is the seam; everything else runs, including the store, the rate limit and
     * the activity record.
     */
    private function sendingLink(bool $fail = false): object
    {
        return new class ($this->db, $fail) extends NewDeviceAuthLink {
            /** @var list<object> */
            public array $delivered = [];

            public function __construct($database, private bool $fail)
            {
                parent::__construct($database);
            }

            protected function notifier(): \Pramnos\Notification\Notifier
            {
                $recorder = $this;

                return new class ($recorder, $this->fail) extends \Pramnos\Notification\Notifier {
                    public function __construct(private object $recorder, private bool $fail)
                    {
                    }

                    public function sendNow($notifiable, $notification): void
                    {
                        if ($this->fail) {
                            throw new \RuntimeException('no mail server');
                        }

                        $this->recorder->delivered[] = $notification;
                    }
                };
            }
        };
    }

    /** One recorded send, at the given time. */
    private function recordSend(int $when): void
    {
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => $this->uid,
            'action'     => 'newdevice_authlink_sent',
            'created_at' => date('Y-m-d H:i:s', $when),
        ]);
    }

    /** Move every recorded send this many seconds into the past. */
    private function rewindSends(int $seconds): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefix . 'authserver_user_activity_log` '
            . 'SET created_at = DATE_SUB(created_at, INTERVAL ' . $seconds . ' SECOND) '
            . 'WHERE userid = ' . $this->uid
        );
    }

    /** Overwrite the stored expiry, to test a boundary without waiting for it. */
    private function setExpiry(int $expires): void
    {
        $this->db->queryBuilder()->table('#PREFIX#userdetails')
            ->where('userid', $this->uid)
            ->where('fieldname', NewDeviceAuthLink::FIELD_EXPIRES)
            ->update(['value' => (string) $expires]);
    }

    /** One stored detail, or ''. */
    private function detail(string $field): string
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#userdetails')
            ->select(['value'])
            ->where('userid', $this->uid)
            ->where('fieldname', $field)
            ->first();

        return ($row === null || ($row->numRows ?? 0) === 0)
            ? ''
            : (string) ($row->fields['value'] ?? '');
    }

    /** @return list<string> every action logged for the fixture account */
    private function loggedActions(): array
    {
        $result  = $this->db->queryBuilder()->table('authserver.user_activity_log')
            ->select(['action'])
            ->where('userid', $this->uid)
            ->get();
        $actions = [];
        while ($result !== null && ($row = $result->fetch()) !== null) {
            $actions[] = (string) ($row['action'] ?? '');
        }

        return $actions;
    }
}
