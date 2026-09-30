<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Auth\Factors\PushApprovalSecondFactor;
use Pramnos\Auth\Notifications\SignInApprovalNotification;
use Pramnos\Auth\PushApprovals;
use Pramnos\Auth\TrustedDevices;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Push\Subscriptions;

/**
 * "Is it you trying to sign in?", against a real database.
 *
 * The rules under test are the ones that make the prompt safe rather than merely
 * convenient: only trusted devices are asked; only a trusted, signed-in browser can answer;
 * every ask needs the number; the first answer stands; only the waiting browser can use the
 * approval, once and soon after it; an account is asked a bounded number of times; an
 * excluded administrator is not asked at all.
 */
#[CoversClass(PushApprovals::class)]
#[CoversClass(PushApprovalSecondFactor::class)]
#[CoversClass(SignInApprovalNotification::class)]
#[CoversClass(Subscriptions::class)]
class PushApprovalsTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;

    private const OWNER = 4801;
    private const OTHER = 4802;

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    /** The trust cookie of the owner's phone, and of a second browser. */
    private string $phoneCookie = '';

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->db->schema()->ensureSchema('authserver');
        $this->db->schema()->ensureSchema('pramnos');
        foreach ([TrustedDevices::TABLE, PushApprovals::TABLE, 'pramnos.pushsubscriptions'] as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverTrustedDevicesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\AddTrustedViaToAuthserverTrustedDevices::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverPushApprovalsTable::class,
            \Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable::class,
            \Pramnos\Framework\Migrations\Notifications\AddTrustedDeviceToPushSubscriptions::class,
        ], $this->db);

        foreach ([TrustedDevices::ENABLED_SETTING, PushApprovals::ENABLED_SETTING] as $name) {
            $this->savedSettings[$name] = Settings::getSetting($name, null);
        }
        Settings::setSetting(TrustedDevices::ENABLED_SETTING, '1', false);
        Settings::setSetting(PushApprovals::ENABLED_SETTING, '1', false);
        unset($_COOKIE[TrustedDevices::COOKIE], $_SESSION[PushApprovals::SESSION_KEY]);

        // The owner's phone: trusted, and subscribed while carrying its cookie.
        $this->phoneCookie = $this->trustedBrowser(self::OWNER, 'phone');
        unset($_COOKIE[TrustedDevices::COOKIE]);
    }

    protected function tearDown(): void
    {
        if ($this->db->connected) {
            foreach ([TrustedDevices::TABLE, PushApprovals::TABLE, 'pramnos.pushsubscriptions'] as $table) {
                $this->db->schema()->dropTableIfExists($table);
            }
        }
        foreach ($this->savedSettings as $name => $value) {
            Settings::setSetting($name, $value === null ? '' : (string) $value, false);
        }
        unset($_COOKIE[TrustedDevices::COOKIE], $_SESSION[PushApprovals::SESSION_KEY]);

        parent::tearDown();
    }

    /** Trust a browser for an account and subscribe it, as the phone would; returns its cookie. */
    private function trustedBrowser(int $userId, string $name): string
    {
        $devices = $this->devices();
        $id      = (int) $devices->trust($userId);
        $cookie  = (string) $_COOKIE[TrustedDevices::COOKIE];
        $endpoint = 'https://web.push.apple.com/' . $name . bin2hex(random_bytes(4));

        Subscriptions::store($userId, ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'Safari');
        Subscriptions::linkTrustedDevice($userId, $endpoint, $id);

        return $cookie;
    }

    private function devices(int $usertype = 1): TrustedDevices
    {
        return new class ($usertype) extends TrustedDevices {
            public function __construct(private int $usertype)
            {
            }

            protected function writeCookie(string $token, int $expires): void
            {
                $_COOKIE[self::COOKIE] = $token;
            }

            protected function usertypeOf(int $userId): int
            {
                return $this->usertype;
            }
        };
    }

    /**
     * The service, with push "installed", the session chosen by the test, and every delivery
     * captured instead of sent. `$staleRead` makes its next read see the ask as still
     * unanswered — the read of a request that lost the race to another answer.
     */
    private function approvals(string $session = 'waiting-browser', ?TrustedDevices $devices = null, bool $staleRead = false, bool $risky = false): PushApprovals
    {
        return new class ($devices ?? $this->devices(), $session, $staleRead, $risky) extends PushApprovals {
            /** @var list<array{recipients: list<array<string, mixed>>, push: array<string, mixed>}> */
            public array $sent = [];
            public int $now;

            public function __construct(TrustedDevices $devices, public string $session, public bool $staleRead, public bool $risky)
            {
                parent::__construct($devices);
                $this->now = time();
            }

            protected function canPush(): bool
            {
                return true;
            }

            protected function looksRisky(int $userId): bool
            {
                return $this->risky;
            }

            protected function byToken(string $token): ?array
            {
                $row = parent::byToken($token);

                if ($row === null || !$this->staleRead) {
                    return $row;
                }

                // Only the first read is stale: the one made before the other answer landed.
                $this->staleRead = false;

                return ['decision' => null] + $row;
            }

            protected function sessionLookup(): string
            {
                return hash('sha256', $this->session);
            }

            protected function now(): int
            {
                return $this->now;
            }

            protected function deliver(int $userId, array $recipients, SignInApprovalNotification $notification): void
            {
                $this->sent[] = ['recipients' => $recipients, 'push' => $notification->toPush(null)];
            }
        };
    }

    /** The token the notification carried, which is what the phone answers with. */
    private function tokenOf(PushApprovals $approvals): string
    {
        $url = (string) end($approvals->sent)['push']['url'];
        preg_match('/token=([a-f0-9]{64})/', $url, $match);

        return $match[1] ?? '';
    }

    /** Answer as the owner's phone: its trust cookie present. */
    private function asPhone(): void
    {
        $_COOKIE[TrustedDevices::COOKIE] = $this->phoneCookie;
    }

    /** The laptop's cookie, from {@see asKnownLaptop()}. */
    private string $laptopCookie = '';

    /**
     * Sign in from a laptop the owner trusted once, whose trust has since expired: known, not
     * trusted. Returns its device id.
     */
    private function asKnownLaptop(): int
    {
        $id = (int) $this->devices()->trust(self::OWNER);
        $this->laptopCookie = (string) $_COOKIE[TrustedDevices::COOKIE];
        $this->db->queryBuilder()->table(TrustedDevices::TABLE)->where('device_id', $id)
            ->update(['expires_at' => time() - 1]);

        return $id;
    }

    private function asKnownLaptopCookie(): void
    {
        $_COOKIE[TrustedDevices::COOKIE] = $this->laptopCookie;
    }

    /** Approve as the phone does: picking the number the waiting page shows. */
    private function approveFromPhone(PushApprovals $approvals): string
    {
        $number = (int) $approvals->status(self::OWNER)['number'];
        $this->asPhone();

        return $approvals->decide($this->tokenOf($approvals), self::OWNER, PushApprovals::APPROVED, $number);
    }

    /**
     * Only the account's trusted, subscribed devices are asked — not a browser that merely
     * granted permission, not a revoked device, not the browser signing in.
     */
    public function testOnlyTrustedSubscribedDevicesAreAsked(): void
    {
        // Arrange — a plain subscription with no trust, and a second trusted device later revoked
        Subscriptions::store(self::OWNER, ['endpoint' => 'https://fcm.googleapis.com/untrusted', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'Chrome');
        $this->trustedBrowser(self::OWNER, 'tablet');
        $tablet = (int) $this->db->queryBuilder()->table(TrustedDevices::TABLE)->where('userid', self::OWNER)
            ->orderBy('device_id', 'desc')->first()->fields['device_id'];
        $this->devices()->revoke(self::OWNER, $tablet);
        unset($_COOKIE[TrustedDevices::COOKIE]);

        // Act
        $recipients = $this->approvals()->recipients(self::OWNER);

        // Assert — the phone alone
        $this->assertCount(1, $recipients);
        $this->assertStringStartsWith('https://web.push.apple.com/phone', $recipients[0]['endpoint']);
        $this->assertSame('an unrecognised browser', $recipients[0]['device_name'], 'named from its fingerprint');

        // And the phone is never asked about its own sign-in.
        $this->asPhone();
        $this->assertSame([], $this->approvals()->recipients(self::OWNER));
        $this->assertSame([], $this->approvals()->recipients(0));
    }

    /**
     * A browser the account never trusted needs the number, however familiar it looks — its
     * User-Agent and country are what whoever holds the password chooses. The waiting page
     * shows the number, the phone gets three to pick from, and the notification's only button
     * is No. The row is bound to the waiting browser's session, and the notification carries
     * where to answer and where to send the receipt.
     */
    public function testAnUnknownBrowserNeedsTheNumber(): void
    {
        // Arrange
        $approvals = $this->approvals();

        // Act
        $started = $approvals->start(self::OWNER);
        $status  = $approvals->status(self::OWNER);
        $this->asPhone();
        $answering = $approvals->forAnswering($this->tokenOf($approvals), self::OWNER);

        // Assert — the number
        $this->assertTrue($started);
        $this->assertIsInt($status['number']);
        $this->assertCount(3, $answering['choices']);
        $this->assertCount(3, array_unique($answering['choices']));
        $this->assertContains($status['number'], $answering['choices']);

        // The notification: no Yes on the lock screen
        $push = $approvals->sent[0]['push'];
        $this->assertSame('Trying to sign in?', $push['title']);
        $this->assertStringContainsString(PushApprovals::APPROVE_PATH . '?token=', $push['url']);
        $this->assertStringContainsString('push/ack?token=', $push['data']['ack']);
        $this->assertSame(['deny'], array_column($push['actions'], 'action'));
        $this->assertStringEndsWith('&decision=denied', $push['data']['actions']['deny']['post']);
        $this->assertTrue($push['data']['open'], 'tapping it opens the answer page');

        // The row
        $row = (array) $this->db->queryBuilder()->table(PushApprovals::TABLE)->first()->fields;
        $this->assertSame($status['number'], (int) $row['number']);
        $this->assertSame(hash('sha256', 'waiting-browser'), $row['session_lookup']);
        $this->assertSame((int) $row['approval_id'], $_SESSION[PushApprovals::SESSION_KEY]);
        $this->assertSame((int) $row['created_at'] + PushApprovals::TTL, (int) $row['expires_at']);
    }

    /**
     * A Yes without the number, on an ask that needs one, changes nothing — it is what a
     * lock-screen button would send, and such an ask carries none. It is neither an approval
     * nor a refusal: the phone can still answer properly.
     */
    public function testAYesWithoutTheNumberChangesNothing(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $this->asPhone();

        // Act
        $answer = $approvals->decide($this->tokenOf($approvals), self::OWNER, PushApprovals::APPROVED);

        // Assert
        $this->assertSame('invalid', $answer);
        $this->assertSame('sent', $approvals->status(self::OWNER)['state']);
        $this->assertSame(PushApprovals::APPROVED, $this->approveFromPhone($approvals), 'still answerable');
    }

    /**
     * A browser this account trusted before — its trust since expired, so it takes the second
     * step — signing in with nothing unusual gets a plain Yes: no number, and a Yes on the
     * lock screen beside the No. That Yes approves.
     */
    public function testABrowserTrustedBeforeGetsAPlainYes(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $this->asKnownLaptop();

        // Act
        $approvals->start(self::OWNER);
        $status = $approvals->status(self::OWNER);
        $push   = end($approvals->sent)['push'];
        $this->asPhone();
        $answer = $approvals->decide($this->tokenOf($approvals), self::OWNER, PushApprovals::APPROVED);

        // Assert
        $this->assertNull($status['number']);
        $this->assertSame(['approve', 'deny'], array_column($push['actions'], 'action'));
        $this->assertSame([], $approvals->forAnswering($this->tokenOf($approvals), self::OWNER)['choices']);
        $this->assertSame(PushApprovals::APPROVED, $answer);
    }

    /**
     * The plain Yes needs all of it: a browser trusted before, nothing unusual about the
     * attempt, and a site that allows it. Anything unusual, a forgotten browser, or
     * `auth_push_number_matching` = `always`, and the number is back.
     */
    public function testThePlainYesNeedsAKnownBrowserACalmAttemptAndTheSite(): void
    {
        // Arrange
        $saved = Settings::getSetting(PushApprovals::NUMBER_SETTING, null);
        $laptop = $this->asKnownLaptop();
        $numbered = function (PushApprovals $approvals): bool {
            $approvals->start(self::OWNER);

            return $approvals->status(self::OWNER)['number'] !== null;
        };

        try {
            // Act & Assert — something unusual
            $this->assertTrue($numbered($this->approvals('waiting-browser', null, false, true)), 'unusual');

            // The site asks for the number every time
            Settings::setSetting(PushApprovals::NUMBER_SETTING, 'always', false);
            $this->assertTrue($numbered($this->approvals()), 'always');
            Settings::setSetting(PushApprovals::NUMBER_SETTING, 'nonsense', false);
            $this->assertSame('risk', PushApprovals::numberPolicy(), 'nonsense is the default');

            // The browser forgotten
            $this->devices()->revoke(self::OWNER, $laptop);
            $this->asKnownLaptopCookie();
            $this->assertTrue($numbered($this->approvals()), 'forgotten');
        } finally {
            Settings::setSetting(PushApprovals::NUMBER_SETTING, $saved === null ? '' : (string) $saved, false);
        }
    }

    /**
     * The waiting page follows the ask: sent, delivered once the phone's worker sends its
     * receipt, approved once the phone says yes — and a receipt with a bad token is refused.
     */
    public function testTheWaitingPageFollowsTheAsk(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $token = $this->tokenOf($approvals);

        // Act & Assert
        $this->assertSame('sent', $approvals->status(self::OWNER)['state']);
        $this->assertSame(['an unrecognised browser'], $approvals->status(self::OWNER)['devices']);
        $this->assertFalse($approvals->acknowledge(str_repeat('0', 64)));
        $this->assertFalse($approvals->acknowledge('not-a-token'));
        $this->assertTrue($approvals->acknowledge($token));
        $this->assertTrue($approvals->acknowledge($token), 'a second receipt is still a receipt');
        $this->assertSame('delivered', $approvals->status(self::OWNER)['state']);

        $this->assertSame(PushApprovals::APPROVED, $this->approveFromPhone($approvals));
        $this->assertSame(PushApprovals::APPROVED, $approvals->status(self::OWNER)['state']);
    }

    /**
     * Only the waiting browser can use the approval, and only once: a second browser — even
     * one holding the pending account — sees nothing to finish, and a used approval is spent.
     */
    public function testTheApprovalIsTheWaitingBrowsersAndIsUsedOnce(): void
    {
        // Arrange — asked and approved
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $this->approveFromPhone($approvals);
        $elsewhere = $this->approvals('another-browser');

        // Act & Assert
        $this->assertSame('none', $elsewhere->status(self::OWNER)['state']);
        $this->assertFalse($elsewhere->consume(self::OWNER), 'another session cannot use it');
        $this->assertFalse($approvals->consume(self::OTHER), 'nor another account');

        $factor = new PushApprovalSecondFactor($approvals);
        $this->assertTrue($factor->verify(self::OWNER, ''));
        $this->assertFalse($factor->verify(self::OWNER, ''), 'spent');
        $this->assertArrayNotHasKey(PushApprovals::SESSION_KEY, $_SESSION);
    }

    /**
     * Answering needs a trusted browser signed in as the account. A forwarded link, another
     * account, a made-up decision or an answer after the two minutes all change nothing.
     */
    public function testOnlyATrustedBrowserOfTheAccountCanAnswer(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $token = $this->tokenOf($approvals);

        // Act & Assert — no trust cookie: a forwarded link
        $this->assertNull($approvals->forAnswering($token, self::OWNER));
        $this->assertSame('invalid', $approvals->decide($token, self::OWNER, PushApprovals::APPROVED));

        // The phone, but as another account, or with nonsense
        $this->asPhone();
        $this->assertNull($approvals->forAnswering($token, self::OTHER));
        $this->assertSame('invalid', $approvals->decide($token, self::OTHER, PushApprovals::APPROVED));
        $this->assertSame('invalid', $approvals->decide($token, self::OWNER, 'maybe'));
        $this->assertSame('invalid', $approvals->decide('bad', self::OWNER, PushApprovals::APPROVED));

        // Too late
        $approvals->now += PushApprovals::TTL + 1;
        $this->assertFalse($approvals->forAnswering($token, self::OWNER)['open']);
        $this->assertSame('expired', $approvals->decide($token, self::OWNER, PushApprovals::APPROVED));
        $this->assertSame('expired', $approvals->status(self::OWNER)['state']);
        $this->assertFalse($approvals->acknowledge($token), 'and no receipt after it ends');
        $this->assertFalse($approvals->consume(self::OWNER));
    }

    /**
     * Picking the wrong number is a refusal, not a retry: somebody guessing is the case the
     * number exists for. A refusal cannot be turned into an approval afterwards.
     */
    public function testTheWrongNumberRefusesTheSignIn(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $token  = $this->tokenOf($approvals);
        $number = (int) $approvals->status(self::OWNER)['number'];
        $this->asPhone();

        // Act
        $answer = $approvals->decide($token, self::OWNER, PushApprovals::APPROVED, $number === 99 ? 98 : $number + 1);

        // Assert
        $this->assertSame(PushApprovals::DENIED, $answer);
        $this->assertSame(PushApprovals::DENIED, $approvals->decide($token, self::OWNER, PushApprovals::APPROVED, $number));
        $this->assertSame(PushApprovals::DENIED, $approvals->status(self::OWNER)['state']);
        $this->assertFalse($approvals->consume(self::OWNER));
        $row = (array) $this->db->queryBuilder()->table(PushApprovals::TABLE)->first()->fields;
        $this->assertNotNull($row['decided_device_id'], 'the device that answered is recorded');
        $this->assertNotNull($row['delivered_at'], 'an answer means it arrived');
    }

    /**
     * The right number approves.
     */
    public function testTheRightNumberApproves(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $this->asPhone();

        // Act
        $answer = $approvals->decide($this->tokenOf($approvals), self::OWNER, PushApprovals::APPROVED, (int) $approvals->status(self::OWNER)['number']);

        // Assert
        $this->assertSame(PushApprovals::APPROVED, $answer);
    }

    /**
     * The first answer stands. A Yes that read the ask before a No was stored — two devices,
     * or the lock screen's No racing the page's Yes — does not overwrite the refusal: the
     * database settles it, and the loser is told what was decided.
     */
    public function testARefusalCannotBeOverwrittenByARacingApproval(): void
    {
        // Arrange — the No lands first
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $number = (int) $approvals->status(self::OWNER)['number'];
        $token  = $this->tokenOf($approvals);
        $this->asPhone();
        $approvals->decide($token, self::OWNER, PushApprovals::DENIED);

        // Act — a Yes whose read saw the ask still open
        $racing = $this->approvals('waiting-browser', null, true);
        $answer = $racing->decide($token, self::OWNER, PushApprovals::APPROVED, $number);

        // Assert
        $this->assertSame(PushApprovals::DENIED, $answer);
        $row = (array) $this->db->queryBuilder()->table(PushApprovals::TABLE)->first()->fields;
        $this->assertSame(PushApprovals::DENIED, $row['decision']);
        $this->assertFalse($approvals->consume(self::OWNER));
    }

    /**
     * An approval is used within a minute of the answer, not of the ask: a Yes in the last
     * seconds of the two minutes still signs the waiting browser in, and one left unused for
     * longer than the window does not.
     */
    public function testTheApprovalIsUsableForAMinuteAfterTheAnswer(): void
    {
        // Arrange — answered two seconds before the ask would have expired
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $approvals->now += PushApprovals::TTL - 2;
        $this->approveFromPhone($approvals);

        // Act — the page's form arrives after the ask's own expiry
        $approvals->now += 5;
        $usedInTime = $approvals->consume(self::OWNER);

        // Arrange — a second ask, approved and then left longer than the window
        $approvals->start(self::OWNER);
        $this->approveFromPhone($approvals);
        $approvals->now += PushApprovals::CONSUME_WINDOW + 1;

        // Act
        $usedLate = $approvals->consume(self::OWNER);

        // Assert
        $this->assertTrue($usedInTime);
        $this->assertFalse($usedLate);
    }

    /**
     * An approval is used once even when two submits race: the second, whose read saw it
     * unused, is refused by the row itself.
     */
    public function testTwoRacingUsesGetOneSignIn(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $this->approveFromPhone($approvals);
        $id = (int) $_SESSION[PushApprovals::SESSION_KEY];

        // Act — the first use, then a second that read the row before the first wrote it
        $first  = $approvals->consume(self::OWNER);
        $second = (new \ReflectionMethod(PushApprovals::class, 'claim'))
            ->invoke($approvals, $id, 'consumed_at', ['consumed_at' => $approvals->now]);

        // Assert
        $this->assertTrue($first);
        $this->assertFalse($second, 'a use that finds it already used writes nothing');
    }

    /**
     * When the site keeps administrators on the second step, an administrator's devices —
     * trusted before the setting was turned on — are neither asked nor able to answer, and
     * the phone prompt is not offered to them.
     */
    public function testAnExcludedAdministratorIsNotAskedAndCannotAnswer(): void
    {
        // Arrange — an ask already out, then the exclusion turned on for this administrator
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $token  = $this->tokenOf($approvals);
        $number = (int) $approvals->status(self::OWNER)['number'];
        $saved  = Settings::getSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, null);
        Settings::setSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, '1', false);
        $admin = $this->approvals('waiting-browser', $this->devices(TrustedDevices::ADMIN_USERTYPE));

        try {
            // Act
            $recipients = $admin->recipients(self::OWNER);
            $this->asPhone();
            $answering = $admin->forAnswering($token, self::OWNER);
            $answer    = $admin->decide($token, self::OWNER, PushApprovals::APPROVED, $number);

            // Assert
            $this->assertSame([], $recipients);
            $this->assertFalse($admin->availableFor(self::OWNER));
            $this->assertNull($answering);
            $this->assertSame('invalid', $answer);
        } finally {
            Settings::setSetting(TrustedDevices::EXCLUDE_ADMINS_SETTING, $saved === null ? '' : (string) $saved, false);
        }
    }

    /**
     * Whether the phone prompt counts as the account's real second factor is the site's
     * `auth_push_counts_for_enrolment`. `strong` (the default) counts it only when a phone
     * that would be asked was trusted with a factor that counts itself — here a passkey, not
     * the owner's phone as the fixture trusts it, with nothing recorded; `always` counts any
     * phone; `never` none.
     */
    public function testThePromptCountsForEnrolmentAsTheSiteSays(): void
    {
        // Arrange
        $saved   = Settings::getSetting(PushApprovalSecondFactor::ENROLMENT_SETTING, null);
        $factor  = new PushApprovalSecondFactor($this->approvals());
        $answers = [];

        try {
            // Act — the owner's phone was trusted with nothing recorded
            foreach (['strong', 'always', 'never', 'nonsense'] as $policy) {
                Settings::setSetting(PushApprovalSecondFactor::ENROLMENT_SETTING, $policy, false);
                $answers[$policy] = $factor->countsForEnrolment(self::OWNER);
            }

            // A second phone, trusted with a passkey
            Settings::setSetting(PushApprovalSecondFactor::ENROLMENT_SETTING, 'strong', false);
            $id       = (int) $this->devices()->trustVia(self::OWNER, 'passkey');
            $endpoint = 'https://web.push.apple.com/strong' . bin2hex(random_bytes(4));
            Subscriptions::store(self::OWNER, ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a']], 'Safari');
            Subscriptions::linkTrustedDevice(self::OWNER, $endpoint, $id);
            unset($_COOKIE[TrustedDevices::COOKIE]);
            $strongPhone = $factor->countsForEnrolment(self::OWNER);
        } finally {
            Settings::setSetting(PushApprovalSecondFactor::ENROLMENT_SETTING, $saved === null ? '' : (string) $saved, false);
        }

        // Assert
        $this->assertSame(['strong' => false, 'always' => true, 'never' => false, 'nonsense' => false], $answers);
        $this->assertTrue($strongPhone);
        $this->assertFalse($factor->countsForEnrolment(self::OTHER), 'no phone at all');
    }

    /**
     * Three asks in ten minutes and no more — the answer to being bombarded with prompts
     * until one is tapped. And nothing is sent while the method is off or nobody can be asked.
     */
    public function testAsksAreBounded(): void
    {
        // Arrange
        $approvals = $this->approvals();

        // Act
        $answers = [];
        for ($i = 0; $i < PushApprovals::MAX_PER_WINDOW + 1; $i++) {
            $answers[] = $approvals->start(self::OWNER);
        }

        // Assert
        $this->assertSame([true, true, true, false], $answers);
        $this->assertCount(3, $approvals->sent);

        // Ten minutes later, again.
        $approvals->now += PushApprovals::WINDOW + 1;
        $this->assertTrue($approvals->start(self::OWNER));

        // Off, or nobody to ask
        Settings::setSetting(PushApprovals::ENABLED_SETTING, '0', false);
        $this->assertFalse($approvals->start(self::OWNER));
        $this->assertFalse($approvals->availableFor(self::OWNER));
        Settings::setSetting(PushApprovals::ENABLED_SETTING, '1', false);
        $this->assertFalse($approvals->start(self::OTHER), 'an account with no trusted phone');
        $this->assertTrue((new PushApprovalSecondFactor($approvals))->isEnrolledFor(self::OWNER));
        $this->assertFalse((new PushApprovalSecondFactor($approvals))->isEnrolledFor(self::OTHER));
    }

    /**
     * "No, it's not me" is recorded as a refusal and cannot be used to sign in; without a
     * waiting ask, the page is told there is none.
     */
    public function testARefusalIsFinal(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $this->assertSame('none', $approvals->status(self::OWNER)['state']);
        $approvals->start(self::OWNER);
        $this->asPhone();

        // Act
        $answer = $approvals->decide($this->tokenOf($approvals), self::OWNER, PushApprovals::DENIED);

        // Assert
        $this->assertSame(PushApprovals::DENIED, $answer);
        $this->assertFalse($approvals->consume(self::OWNER));
        $this->assertFalse($approvals->forAnswering($this->tokenOf($approvals), self::OWNER)['open']);
    }

    /**
     * Without its tables the service asks nobody and answers "nothing waiting" rather than
     * failing the sign-in page it is part of.
     */
    public function testMissingTablesAreNoAskRatherThanAnError(): void
    {
        // Arrange
        $approvals = $this->approvals();
        $approvals->start(self::OWNER);
        $this->db->schema()->dropTableIfExists(PushApprovals::TABLE);

        // Act & Assert
        $this->assertFalse($approvals->start(self::OWNER), 'an ask that cannot be counted is not sent');
        $this->assertSame('none', $approvals->status(self::OWNER)['state']);
        $this->assertFalse($approvals->acknowledge(str_repeat('a', 64)));
        $this->assertFalse($approvals->consume(self::OWNER));
        foreach (['claim' => [1, 'decision', ['decision' => 'denied']], 'update' => [1, ['decision' => 'denied']]] as $method => $args) {
            $this->assertFalse((new \ReflectionMethod(PushApprovals::class, $method))->invoke($approvals, ...$args), $method);
        }

        // The real seams answer the same way: this session is waiting on nothing here.
        $_SESSION[PushApprovals::SESSION_KEY] = 1;
        $this->assertSame('none', (new PushApprovals())->status(self::OWNER)['state']);

        // A subscription cannot be linked without its table, nor with nothing to link.
        $this->db->schema()->dropTableIfExists('pramnos.pushsubscriptions');
        $this->assertFalse(Subscriptions::linkTrustedDevice(self::OWNER, 'https://push.example/x', 1));
        $this->assertFalse(Subscriptions::linkTrustedDevice(self::OWNER, ' ', 1));
        $this->assertFalse(Subscriptions::linkTrustedDevice(0, 'https://push.example/x', 1));
    }

    /**
     * The factor's own face: its name is what forms carry, it is offered first, it sends,
     * and the real service is what it uses when none is given.
     */
    public function testTheFactorDescribesItself(): void
    {
        // Arrange
        $factor = new PushApprovalSecondFactor();

        // Act & Assert
        $this->assertSame('push', $factor->name());
        $this->assertSame('A notification to your phone', $factor->label());
        $this->assertGreaterThan(60, $factor->strength(), 'offered before the authenticator app');
        $this->assertTrue($factor->needsSending());
        $this->assertFalse($factor->send(self::OTHER), 'nobody to send to');
    }
}
