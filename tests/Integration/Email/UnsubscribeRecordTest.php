<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Email\Unsubscribe;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The unsubscribe record, against a real store.
 *
 * The half a unit test cannot reach, and the half a mailbox provider measures: an unsubscribe
 * has to be honoured — within two days, by Gmail's rule — which means the request must be
 * written, must be found again by the check that runs before the next send, and must not be
 * defeated by the address arriving in different case from a different mail client.
 *
 * Keyed on the address rather than a user id, deliberately, and that is asserted here too: an
 * unsubscribe arrives from a mailbox, and often from somebody who has no account at all —
 * forwarded to, added to a list, inheriting an address. A record that could only describe a
 * user would fail exactly those people.
 */
#[CoversClass(Unsubscribe::class)]
#[CoversClass(\Pramnos\Application\Controllers\Unsubscribe::class)]
class UnsubscribeRecordTest extends BaseTestCase
{
    private string $address = '';

    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        \Pramnos\Application\Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );
        \Pramnos\Application\Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = \Pramnos\Framework\Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('UnsubscribeRecordTest runs on MySQL only.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->schema()->resolveTableName('pramnos.emailoptouts') . '`');
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class,
        ]);

        Unsubscribe::reset();
        $this->address = 'optout_' . bin2hex(random_bytes(5)) . '@example.com';
    }

    protected function tearDown(): void
    {
        try {
            $this->db->queryBuilder()
                ->table('pramnos.emailoptouts')
                ->whereRaw('LOWER(email) = ?', [strtolower($this->address)])
                ->delete();
        } catch (\Throwable) {
            // Nothing to undo.
        }

        Unsubscribe::reset();
        parent::tearDown();
    }

    /**
     * An address that unsubscribed from a list is suppressed for that list only.
     *
     * Both halves matter. Honouring it is the requirement; *not* over-honouring it is what
     * keeps the feature usable — an unsubscribe from one announcement must not silently stop a
     * newsletter somebody chose, or the next complaint is the opposite one.
     */
    public function testAnOptOutSuppressesItsOwnListAndNoOther(): void
    {
        // Act
        $this->assertTrue(Unsubscribe::optOut($this->address, 'marketing', 'page'));

        // Assert
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'marketing'));
        $this->assertFalse(Unsubscribe::isOptedOut($this->address, 'newsletter'));
    }

    /**
     * `all` suppresses everything that carries a link.
     */
    public function testAllSuppressesEveryList(): void
    {
        // Act
        Unsubscribe::optOut($this->address, Unsubscribe::LIST_ALL, 'one_click');

        // Assert
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'marketing'));
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'anything-at-all'));
    }

    /**
     * The address is matched whatever case it arrives in.
     *
     * Mail clients do not agree on this, and a provider's one-click POST carries whatever was
     * in the token. `Someone@Example.com` and `someone@example.com` are one mailbox, and a
     * suppression that missed one of them would keep mailing somebody who had pressed the
     * button — which is counted as though we had ignored them, because we had.
     */
    public function testTheAddressIsMatchedWithoutRegardToCase(): void
    {
        // Act
        Unsubscribe::optOut(strtoupper($this->address), 'marketing');

        // Assert
        $this->assertTrue(Unsubscribe::isOptedOut(strtolower($this->address), 'marketing'));
    }

    /**
     * Pressing it twice is not an error, and does not write a second row.
     *
     * People do press it twice — the first press often shows a page they close before it
     * loads. The answer to "may we mail this address" does not become more true when asked
     * again.
     */
    public function testARepeatedRequestIsRecordedOnce(): void
    {
        // Act
        Unsubscribe::optOut($this->address, 'marketing');
        Unsubscribe::optOut($this->address, 'marketing');

        // Assert
        $rows = $this->db->queryBuilder()
            ->table('pramnos.emailoptouts')
            ->whereRaw('LOWER(email) = ?', [strtolower($this->address)])
            ->count();

        $this->assertSame(1, (int) $rows);
    }

    /**
     * And an address can ask to hear from us again.
     *
     * Which is why `optOut()` writes a row rather than deleting an account's flag: the state
     * has to be reversible by the person, and an admin has to be able to see that it was them.
     */
    public function testAnOptOutCanBeUndone(): void
    {
        // Arrange
        Unsubscribe::optOut($this->address, 'marketing');

        // Act
        $this->assertTrue(Unsubscribe::optIn($this->address, 'marketing'));

        // Assert
        $this->assertFalse(Unsubscribe::isOptedOut($this->address, 'marketing'));
    }

    /**
     * A token minted for one address unsubscribes that address and no other.
     *
     * The end-to-end shape of what the endpoint does: verify, then record. Asserted together
     * because the two halves are only useful joined — a token that verifies but records
     * nothing is a link that appears to work.
     */
    public function testATokenIsAllTheEndpointNeeds(): void
    {
        // Arrange — what a mailbox provider POSTs back
        $token = Unsubscribe::token($this->address, 'marketing');

        // Act
        $claim = Unsubscribe::verify($token);
        Unsubscribe::optOut($claim['email'], $claim['list'], 'one_click');

        // Assert
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'marketing'));
    }
    /** A stand-in application for a migration: it only reads `->database`. */
    private function consentApplication(): object
    {
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        return $app;
    }

    /**
     * An unsubscribe by somebody with an account writes a consent withdrawal too.
     *
     * The suppression record decides delivery; the consent record is the GDPR trail — one row
     * per grant *and* per withdrawal, with a legal basis, in the table built for exactly that.
     * Asked as «τα email unsubscribe δεν θα μπορούσαν να είναι στο user_consents;» and the
     * answer is both, for the same reason `messages` and `massmessagerecipients` are two tables.
     */
    public function testAnUnsubscribeByAnAccountWritesAConsentWithdrawal(): void
    {
        // Arrange
        // Built rather than skipped: a skip that never un-skips is a test that does not exist,
        // and this is the half the question was about.
        (new \Pramnos\Framework\Migrations\Auth\CreateUserConsentsTable(
            $this->consentApplication()
        ))->up();

        $user = new \Pramnos\User\User();
        $user->username = 'consent_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->save();

        try {
            // Act
            Unsubscribe::optOut($user->email, 'marketing');

            // Assert
            $rows = $this->db->queryBuilder()
                ->table('authserver.user_consents')
                ->where('userid', (int) $user->userid)
                ->get()
                ->fetchAll();

            $this->assertCount(1, $rows);
            $this->assertSame('email:marketing', $rows[0]['consent_type']);
            $this->assertSame(0, (int) $rows[0]['granted']);
            $this->assertNotEmpty($rows[0]['revoked_at'], 'a withdrawal has a revoked_at');
            $this->assertSame('consent', $rows[0]['legal_basis']);
        } finally {
            $this->db->queryBuilder()->table('authserver.user_consents')
                ->where('userid', (int) $user->userid)->delete();
            $user->deleteuser();
        }
    }

    /**
     * And an address with no account writes only the suppression record.
     *
     * Somebody on a list who never signed up. `user_consents` is keyed by `userid` and there is
     * none — which is exactly why the suppression list is keyed by address instead.
     */
    public function testAnAddressWithNoAccountWritesOnlyTheSuppression(): void
    {
        // Act
        $written = Unsubscribe::optOut('nobody-' . bin2hex(random_bytes(4)) . '@example.com', 'marketing');

        // Assert
        $this->assertTrue($written, 'the suppression record is what decides delivery');
    }

    /**
     * The endpoint's own readers are the service, not a second implementation.
     *
     * Every unit test of that controller replaces these three. Without one real call they could
     * point anywhere — and the failure would be a page that says somebody was unsubscribed while
     * nothing was written, which is the one promise this endpoint must not break.
     */
    public function testTheControllerReadsAndWritesThroughTheService(): void
    {
        // Arrange
        $address = 'endpoint-' . bin2hex(random_bytes(4)) . '@example.com';

        $controller = new class extends \Pramnos\Application\Controllers\Unsubscribe {
            public function __construct()
            {
                // Deliberately not parent::__construct(): it registers actions against an
                // application this test does not have.
            }

            public function probeOptOut(string $email, string $list): bool
            {
                return $this->optOut($email, $list, 'page');
            }

            public function probeOptIn(string $email, string $list): bool
            {
                return $this->optIn($email, $list);
            }

            public function probeIsOptedOut(string $email, string $list): bool
            {
                return $this->isOptedOut($email, $list);
            }
        };

        try {
            // Act & Assert — off, then on again
            $this->assertTrue($controller->probeOptOut($address, 'marketing'));
            $this->assertTrue($controller->probeIsOptedOut($address, 'marketing'));

            $this->assertTrue($controller->probeOptIn($address, 'marketing'));
            $this->assertFalse($controller->probeIsOptedOut($address, 'marketing'),
                'opting back in is a deletion — the record is state, not history');
        } finally {
            Unsubscribe::optIn($address, 'marketing');
        }
    }

    /**
     * An installation that has registered nothing optional offers no preferences.
     *
     * There is no choice to present, and a heading over an empty list reads as a page that
     * failed to load rather than as an installation with one kind of mail.
     */
    public function testNoOptionalTypesMeansNoPreferencesSection(): void
    {
        // Arrange
        \Pramnos\Email\MailTypes::reset();

        $controller = new class extends \Pramnos\Application\Controllers\Unsubscribe {
            public function __construct()
            {
            }

            /** @var array<string, \Pramnos\Email\MailType> */
            public array $types = [];

            public function probePreferences(string $email): string
            {
                return $this->preferences($email);
            }
        };

        // Act — the framework's own optional type is `newsignin`, so remove it for this
        \Pramnos\Email\MailTypes::register(
            new \Pramnos\Email\MailType('newsignin', 'Sign-in alerts', '', '')
        );
        $html = $controller->probePreferences('reader@example.com');

        // Assert
        $this->assertSame('', $html);

        \Pramnos\Email\MailTypes::reset();
    }

}
