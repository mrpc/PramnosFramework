<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Email\Unsubscribe;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * How the unsubscribe service fails — all 50 of its uncovered statements, and the whole of its
 * design.
 *
 * {@see UnsubscribeRecordTest} covers what happens when everything works: the suppression row, the
 * `all` list, the case-insensitive match, the repeat, the undo, the consent trail. Every one of
 * those is the happy path, and what was left uncovered was every `catch` block and every
 * empty-input guard in the file.
 *
 * That is not a gap in the corners. This class's documented behaviour *is* its failure
 * behaviour, and one of those branches is the opposite of how the rest of the framework fails:
 *
 * > **Answers true when it cannot tell.** Sending to somebody who unsubscribed is the one mistake
 * > a mailbox provider counts against every future message, including the transactional mail this
 * > method is never asked about. A message not sent during a database outage is a message the next
 * > run sends.
 *
 * `isOptedOut()` fails **closed**. Nothing had ever run that branch, so nothing would have noticed
 * a later change making it fail open — and the symptom of that change is not an error anywhere: it
 * is mail going to people who asked us to stop, during an outage, followed by a deliverability
 * problem that outlives the outage by months.
 *
 * The rest of the catches are the mirror image, and equally deliberate: **nothing may make an
 * unsubscribe fail**. Not a consent table the installation does not have, not an application's own
 * handler raising, not a missing preference row. The suppression record is what decides delivery,
 * and it is written first.
 *
 * Both backends. {@see UnsubscribeFailurePathsPostgreSQLTest} re-runs the class, which also makes
 * it the first PostgreSQL coverage this service has had — the existing test declares itself MySQL
 * only, and `pramnos.emailoptouts` is a schema on one engine and a table prefix on the other.
 */
#[CoversClass(Unsubscribe::class)]
class UnsubscribeFailurePathsTest extends BaseTestCase
{
    private $db;

    private string $address = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        \Pramnos\Application\Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->createOptOutTable();

        /*
         * `secret()` keeps a generated signing key in the settings store, so the store has to
         * exist for that path to be reachable — see testTheSigningKeyIsKeptSoLinksKeepWorking.
         *
         * And `Settings` has to be pointed at *this* connection. Its database handle is static
         * and set once, so in a full-suite run it is whatever an earlier class left there: the
         * PostgreSQL lane migrated a `settings` table on PostgreSQL and then watched the write go
         * to MySQL, reporting a missing table with a MySQL error message. It passed under
         * `--filter` and failed in the suite, which is the signature of static state.
         */
        Settings::setDatabase($this->db, false);
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Core\CreateSettingsTable::class,
        ], $this->db);

        Unsubscribe::reset();
        $this->address = 'failpath_' . bin2hex(random_bytes(5)) . '@example.com';
    }

    protected function tearDown(): void
    {
        /*
         * Rebuilt, not merely cleaned.
         *
         * Several tests below drop the table on purpose — that is how the outage is arranged —
         * and every other test in the suite that touches an opt-out expects it to be there. A
         * teardown that only deleted rows would leave the next class without a table, which is
         * the failure this file is about, arriving where nobody asked for it.
         */
        try {
            $this->createOptOutTable();
            $this->db->queryBuilder()->table('pramnos.emailoptouts')
                ->whereRaw('LOWER(email) = ?', [strtolower($this->address)])
                ->delete();
        } catch (\Throwable $exception) {
            // Nothing to undo.
        }

        Unsubscribe::reset();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The one that fails closed ─────────────────────────────────────────────

    /**
     * With no opt-out table to read, an address is treated as unsubscribed.
     *
     * The branch this whole file exists for, and the only place in the framework where "we do not
     * know" answers *yes*. Sending during an outage to somebody who asked us to stop is counted
     * by every mailbox provider against every future message from the domain — including the
     * password resets this method is never consulted about. A message not sent is a message the
     * next run sends; a complaint recorded is permanent.
     */
    public function testWhenItCannotTellItSaysUnsubscribed(): void
    {
        // Arrange — the outage.
        $this->dropOptOutTable();

        // Act & Assert
        $this->assertTrue(
            Unsubscribe::isOptedOut($this->address, 'marketing'),
            'an unreadable opt-out table let a message through'
        );
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, Unsubscribe::LIST_ALL));
    }

    /** And with the table there, the same address is not suppressed. */
    public function testWithTheTableThereTheSameAddressIsNotSuppressed(): void
    {
        // Act & Assert
        $this->assertFalse(
            Unsubscribe::isOptedOut($this->address, 'marketing'),
            'the fail-closed answer is being given when the table can be read'
        );
    }

    // ── The ones that must never fail ─────────────────────────────────────────

    /**
     * A consent table the installation does not have does not stop the unsubscribe.
     *
     * `authserver.user_consents` belongs to the `auth` feature, so an installation without it has
     * none — and an installation that has it may still have an address with no account. Either
     * way the suppression record is what decides delivery, and it is written before the consent
     * event is attempted.
     */
    public function testAMissingConsentTableDoesNotStopTheUnsubscribe(): void
    {
        // Arrange — no consent table on this connection.
        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('authserver.user_consents')
        );

        // Act
        $recorded = Unsubscribe::optOut($this->address, 'marketing', 'page');

        // Assert
        $this->assertTrue($recorded, 'the unsubscribe reported failure over a consent trail');
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'marketing'));
    }

    /**
     * An application's own handler raising does not stop the unsubscribe either.
     *
     * A handler is arbitrary application code — a call to a service that is down, a table that
     * has not been migrated yet. The request still has to be honoured within two days, and the
     * row that honours it was already written.
     */
    public function testAHandlerThatRaisesDoesNotStopTheUnsubscribe(): void
    {
        // Arrange
        $reached = false;
        Unsubscribe::handle('digest', function (string $email, string $list) use (&$reached): void {
            $reached = true;

            throw new \RuntimeException('the digest service is down');
        });

        // Act
        $recorded = Unsubscribe::optOut($this->address, 'digest', 'one_click');

        // Assert
        $this->assertTrue($reached, 'the handler was never called');
        $this->assertTrue($recorded, 'a raising handler made the unsubscribe report failure');
        $this->assertTrue(
            Unsubscribe::isOptedOut($this->address, 'digest'),
            'a raising handler cost the suppression record'
        );
    }

    /**
     * Nor does a failure while turning the account's own preference off.
     *
     * `newsignin` is backed by a checkbox on the privacy screen, and honouring the unsubscribe
     * means flipping it — a row elsewhere suppressing the mail while the switch still said "on"
     * would be a screen that lies. But the preference lives in `userdetails`, and if that cannot
     * be written the unsubscribe still has to hold: the suppression record is the one that decides.
     */
    public function testAFailureFlippingThePreferenceDoesNotStopTheUnsubscribe(): void
    {
        // Arrange — an account whose preference store has gone.
        User::setupDb();
        $user = new User();
        $user->username = 'failpath_' . bin2hex(random_bytes(4));
        $user->email    = $this->address;
        $user->save();

        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#userdetails')
        );

        try {
            // Act
            $recorded = Unsubscribe::optOut($this->address, 'newsignin', 'one_click');

            // Assert
            $this->assertTrue($recorded);
            $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'newsignin'));
        } finally {
            User::setupDb();

            try {
                $this->db->queryBuilder()->table('#PREFIX#users')
                    ->where('userid', (int) $user->userid)->delete();
            } catch (\Throwable $exception) {
                // Nothing to undo.
            }
            User::clearUserCache();
        }
    }

    /**
     * When the record itself cannot be written, that *is* reported.
     *
     * The one failure a caller has to be able to see. Everything downstream of the row is
     * best-effort, and the row is not: an endpoint that answered "done" while writing nothing
     * would leave a person unsubscribing again, and again, from mail that keeps arriving.
     */
    public function testAFailureToWriteTheRecordIsReported(): void
    {
        // Arrange
        $this->dropOptOutTable();

        // Act & Assert
        $this->assertFalse(
            Unsubscribe::optOut($this->address, 'marketing', 'page'),
            'the unsubscribe reported success without a table to write to'
        );
    }

    /** An opt-in that cannot be written is reported too, for the same reason. */
    public function testAFailureToClearIsReported(): void
    {
        // Arrange
        $this->dropOptOutTable();

        // Act & Assert
        $this->assertFalse(
            Unsubscribe::optIn($this->address, 'marketing'),
            'opting back in reported success with no table to clear'
        );
    }

    // ── Nothing is not an address ─────────────────────────────────────────────

    /**
     * An empty address is refused everywhere, and writes nothing.
     *
     * A blank address reaches this from a malformed token, a truncated mailto, or an application
     * calling it with a field somebody left empty. A suppression row with no address matches
     * nothing and is never removed; worse, `optOut('')` answering true would have an endpoint
     * report a request honoured that was not.
     */
    public function testAnEmptyAddressIsRefusedEverywhere(): void
    {
        // Act & Assert
        foreach (['', '   ', "\t"] as $blank) {
            $this->assertFalse(
                Unsubscribe::optOut($blank, 'marketing'),
                'an unsubscribe was reported for ' . var_export($blank, true)
            );
            $this->assertFalse(
                Unsubscribe::optIn($blank, 'marketing'),
                'an opt-in was reported for ' . var_export($blank, true)
            );
            $this->assertFalse(
                Unsubscribe::isOptedOut($blank, 'marketing'),
                'a blank address was reported as unsubscribed'
            );
        }

        $this->assertSame(
            0,
            $this->rowsForBlankAddresses(),
            'a suppression row was written with no address in it'
        );
    }

    /**
     * A blank address yields no token, so no header points at nothing.
     *
     * `optOut()`, `optIn()` and `isOptedOut()` all refuse a blank address, and `token()` was the
     * one entry point that did not — it signed one. That token *verifies*, so the endpoint reads
     * `['email' => '', 'list' => 'all']` out of it, calls `optOut('')`, is refused, and shows the
     * reader a failure for a link this code generated. `url()` and `mailto()` turn an empty token
     * into an omitted header, which is the honest outcome: no address, no unsubscribe link.
     */
    public function testABlankAddressYieldsNoToken(): void
    {
        // Act & Assert
        foreach (['', '   ', "\t"] as $blank) {
            $this->assertSame(
                '',
                Unsubscribe::token($blank),
                'a token was signed for ' . var_export($blank, true)
            );
        }

        $this->assertNotSame(
            '',
            Unsubscribe::token($this->address),
            'the guard is refusing a real address too'
        );
    }

    /**
     * A token naming somebody else does not verify.
     *
     * The property that makes an unstored token safe: the address and the list travel *inside*
     * it, so without the signature check anybody could unsubscribe a stranger by editing a URL.
     * Written as the attack rather than as a mutation — decode, put another address in, keep the
     * signature, re-encode — which is deterministic and is what somebody would actually try.
     *
     * The first version flipped the token's last character and asserted it stopped verifying.
     * That failed in a full run and passed under a filter, because base64 carries six bits per
     * character: the last one holds padding bits that `base64_decode` discards, so changing it
     * often decodes to the identical bytes. A test of the signature has to change the *payload*,
     * not the encoding of it.
     */
    public function testATokenNamingSomebodyElseDoesNotVerify(): void
    {
        // Arrange
        $token = Unsubscribe::token($this->address, 'marketing');
        $this->assertNotSame('', $token, 'precondition: a token was issued');
        $this->assertIsArray(Unsubscribe::verify($token), 'precondition: it verifies');

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $parts   = explode('|', (string) $decoded);
        $this->assertCount(3, $parts, 'precondition: the payload is address, list and signature');

        // Act — the same signature, over a different person.
        $forged = rtrim(strtr(base64_encode(
            'somebody.else@example.com|' . $parts[1] . '|' . $parts[2]
        ), '+/', '-_'), '=');

        // Assert
        $this->assertNull(
            Unsubscribe::verify($forged),
            'a token edited to name another address still verified'
        );
        $this->assertNull(Unsubscribe::verify(''), 'an empty token verified');
        $this->assertNull(Unsubscribe::verify('not-a-token'), 'a made-up token verified');
        $this->assertNull(
            Unsubscribe::verify(rtrim(strtr(base64_encode(
                $parts[0] . '|' . $parts[1] . '|' . str_repeat('0', strlen($parts[2]))
            ), '+/', '-_'), '=')),
            'a token with the signature replaced still verified'
        );
    }

    // ── The signing key ───────────────────────────────────────────────────────

    /**
     * With no salt configured, the generated key is kept so that links keep working.
     *
     * A per-request key would sign tokens nothing can verify afterwards, which for an unsubscribe
     * link means every one of them stops working the moment it is clicked — and the reader's next
     * move is the spam button. So the key is stored on first use, and the same token verifies
     * across calls.
     */
    public function testTheSigningKeyIsKeptSoLinksKeepWorking(): void
    {
        // Arrange — an installation with no securitySalt.
        $salt = Settings::getSetting('securitySalt');
        Settings::setSetting('securitySalt', '');

        try {
            // Act
            $token = Unsubscribe::token($this->address, 'marketing');
            $stored = Settings::getSetting(Unsubscribe::SECRET_SETTING);

            // Assert
            $this->assertNotSame('', (string) $stored, 'no signing key was kept');
            $this->assertIsArray(
                Unsubscribe::verify($token),
                'a token signed with the generated key does not verify on the next call'
            );
        } finally {
            Settings::setSetting('securitySalt', (string) $salt);
        }
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    private function createOptOutTable(): void
    {
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class,
        ], $this->db);
    }

    /** The outage: no table to read or write. */
    private function dropOptOutTable(): void
    {
        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('pramnos.emailoptouts')
        );
    }

    private function rowsForBlankAddresses(): int
    {
        try {
            return (int) $this->db->queryBuilder()
                ->table('pramnos.emailoptouts')
                ->whereRaw("TRIM(email) = ?", [''])
                ->count();
        } catch (\Throwable $exception) {
            return 0;
        }
    }
}
