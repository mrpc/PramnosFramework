<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\SecurityChangeNotifier;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Telling an account that the things protecting it have changed.
 *
 * The class was at **6% covered** — one method, essentially none of it executed — and what it decides
 * is the one signal the owner of a stolen account ever gets.
 *
 * ## Why the *previous* address is the whole class
 *
 * A stolen session's first two moves are to change the email address and then the password. Every
 * notification after the first goes to the attacker's address, so «we told the account» is worthless:
 * the account is theirs now. Mailing the address that *was* on the record is the only message the owner
 * receives, and it is the one that arrives while the situation is still recoverable.
 *
 * That is why this is a class rather than a line at each call site, and it is the property most of the
 * assertions below are about.
 *
 * ## Recorded rather than mocked
 *
 * The notifier is a seam, and it records the notifiables it was handed. Which addresses were mailed is
 * the question, and a test that only checked the return value could not tell one send from two.
 */
#[CoversClass(SecurityChangeNotifier::class)]
class SecurityChangeNotifierTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private const CURRENT = 'notifier_current@example.test';

    /** @var list<string> Addresses the recording notifier was asked to mail */
    public static array $mailed = [];

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
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        \Pramnos\User\User::setupDb();

        $user = new \Pramnos\User\User();
        $user->username = 'notifier_' . bin2hex(random_bytes(4));
        $user->email    = self::CURRENT;
        $user->save();
        $this->uid = (int) $user->userid;

        self::$mailed = [];
        $this->allowNotifications(true);
    }

    protected function tearDown(): void
    {
        if ($this->uid > 0) {
            foreach (['#PREFIX#userdetails', '#PREFIX#users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
                } catch (\Throwable) {
                    // Nothing to undo.
                }
            }
        }

        self::$mailed = [];
        \Pramnos\User\User::clearUserCache();

        parent::tearDown();
    }

    /**
     * Turn the opt-in on or off for one test.
     *
     * `auth.security.notify_security_changes` is read from `applicationInfo`, not from the settings
     * table — so it is a deployment decision rather than something a signed-in administrator can flip,
     * which for «does this account get told its password changed» is the right place for it.
     */
    private function allowNotifications(bool $on): void
    {
        $application = Application::currentInstance();
        $application->applicationInfo['auth']['security']['notify_security_changes'] = $on;
    }

    /** The notifier under test, with the sending recorded. */
    private function notifier(): string
    {
        return RecordingSecurityChangeNotifier::class;
    }

    /**
     * With the opt-in off, nothing is sent and the caller is told so.
     *
     * Off by default, and deliberately: mail costs money and reputation, and an application whose users
     * change a password as routine hygiene would be sending a great deal of it. The framework's job is
     * to make the right thing one line of configuration away rather than to spend somebody else's send
     * quota for them.
     */
    public function testWithTheOptInOffNothingIsSent(): void
    {
        // Arrange
        $this->allowNotifications(false);
        $notifier = $this->notifier();

        // Act
        $sent = $notifier::notify($this->uid, SecurityChangeNotifier::PASSWORD);

        // Assert
        $this->assertFalse($sent);
        $this->assertSame([], self::$mailed);
    }

    /**
     * The reserved ids are refused before a user is even loaded.
     *
     * `userid` 0 and 1 are the guest and system rows. A notification «about» one of them is a mail to
     * whatever address those rows happen to carry — in a fresh installation, often an administrator's —
     * reporting a change to an account nobody owns.
     *
     * @param int $reserved
     */
    #[DataProvider('reservedIds')]
    public function testTheReservedIdsAreRefused(int $reserved): void
    {
        // Act
        $notifier = $this->notifier();
        $sent = $notifier::notify($reserved, SecurityChangeNotifier::PASSWORD);

        // Assert
        $this->assertFalse($sent);
        $this->assertSame([], self::$mailed);
    }

    /** @return array<string, array{int}> */
    public static function reservedIds(): array
    {
        return ['nobody' => [0], 'the system row' => [1]];
    }

    /**
     * An ordinary change goes to the address on the account, once.
     *
     * @param string $what
     */
    #[DataProvider('changes')]
    public function testAChangeGoesToTheAccount(string $what): void
    {
        // Act
        $notifier = $this->notifier();
        $sent = $notifier::notify($this->uid, $what);

        // Assert
        $this->assertTrue($sent, $what . ' was not reported at all');
        $this->assertSame([self::CURRENT], self::$mailed);
    }

    /** @return array<string, array{string}> */
    public static function changes(): array
    {
        return [
            'a password'         => [SecurityChangeNotifier::PASSWORD],
            'a factor added'     => [SecurityChangeNotifier::FACTOR_ADDED],
            'a factor removed'   => [SecurityChangeNotifier::FACTOR_REMOVED],
            'a passkey added'    => [SecurityChangeNotifier::PASSKEY_ADDED],
            'a passkey revoked'  => [SecurityChangeNotifier::PASSKEY_REMOVED],
        ];
    }

    /**
     * An address change is reported to the new address **and to the old one**.
     *
     * The assertion this class exists for. Without the second message, changing the address is a silent
     * takeover: every notification from then on — including the password change that follows it —
     * arrives at the attacker's mailbox, and the owner learns nothing until they try to sign in.
     *
     * Two sends, and the old address among them, asserted by name rather than by count.
     */
    public function testAnAddressChangeReachesTheAddressThatWasThere(): void
    {
        // Act
        $notifier = $this->notifier();
        $sent = $notifier::notify(
            $this->uid,
            SecurityChangeNotifier::EMAIL,
            '',
            'notifier_previous@example.test'
        );

        // Assert
        $this->assertTrue($sent);
        $this->assertContains(self::CURRENT, self::$mailed, 'the new address was not told');
        $this->assertContains(
            'notifier_previous@example.test',
            self::$mailed,
            'the previous address was not told, which is the only signal the owner gets'
        );
        $this->assertCount(2, self::$mailed);
    }

    /**
     * The old address is not mailed when it is the current one, whatever its case.
     *
     * `strcasecmp`, because an address that changed only in capitalisation has not changed — and two
     * identical mails about one event teach the recipient to ignore both.
     *
     * @param string $oldEmail
     */
    #[DataProvider('sameAddresses')]
    public function testAnUnchangedAddressIsNotMailedTwice(string $oldEmail): void
    {
        // Act
        $notifier = $this->notifier();
        $notifier::notify($this->uid, SecurityChangeNotifier::EMAIL, '', $oldEmail);

        // Assert
        $this->assertSame([self::CURRENT], self::$mailed);
    }

    /** @return array<string, array{string}> */
    public static function sameAddresses(): array
    {
        return [
            'the same address'      => [self::CURRENT],
            'the same in mixed case' => ['Notifier_Current@Example.Test'],
            'the same with spaces'  => ['  ' . self::CURRENT . '  '],
        ];
    }

    /**
     * An old address that is not an address is skipped rather than attempted.
     *
     * It arrives from whatever the account held before — a legacy row, an import, a column somebody
     * once used for a note. Handing that to the mailer is a bounce at best and, on a transactional
     * provider, a reputation hit charged to the sender.
     */
    public function testAnOldAddressThatIsNotAnAddressIsSkipped(): void
    {
        // Act
        $notifier = $this->notifier();
        $sent = $notifier::notify($this->uid, SecurityChangeNotifier::EMAIL, '', 'not-an-address');

        // Assert
        $this->assertTrue($sent, 'the account itself was not told either');
        $this->assertSame([self::CURRENT], self::$mailed);
    }

    /**
     * A send that throws is swallowed, and the change it reports is not undone.
     *
     * The rule the class states and this pins: a notification is never worth failing the change it
     * reports. Somebody told «your password could not be updated» because a mail server was down will
     * try again — and the second attempt, on an account whose password *did* change, is what actually
     * goes wrong.
     */
    public function testAFailedSendIsSwallowed(): void
    {
        // Arrange
        RecordingSecurityChangeNotifier::$explode = true;

        try {
            // Act
            $sent = RecordingSecurityChangeNotifier::notify(
                $this->uid,
                SecurityChangeNotifier::PASSWORD
            );

            // Assert
            $this->assertFalse($sent, 'a failed send was reported as sent');
        } finally {
            RecordingSecurityChangeNotifier::$explode = false;
        }
    }
}

/**
 * The notifier under test, with the sending recorded rather than performed.
 *
 * A subclass because `notify()` is static and reaches for `static::notifier()` — which is the only
 * reason that method is not private.
 */
class RecordingSecurityChangeNotifier extends SecurityChangeNotifier
{
    public static bool $explode = false;

    protected static function notifier(): \Pramnos\Notification\Notifier
    {
        return new class extends \Pramnos\Notification\Notifier {
            public function __construct()
            {
            }

            public function sendNow(
                mixed $notifiable,
                \Pramnos\Notification\NotificationInterface $notification
            ): void {
                if (RecordingSecurityChangeNotifier::$explode) {
                    throw new \Exception('the mail server refused the message');
                }

                SecurityChangeNotifierTest::$mailed[] = (string) ($notifiable->email ?? '');
            }
        };
    }
}
