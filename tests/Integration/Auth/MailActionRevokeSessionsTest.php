<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\AuthServerServiceProvider;
use Pramnos\Email\MailAction;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The one-click handler that ends every session on an account.
 *
 * The handler a "this wasn't me" button needs, and the reason the mail-action infrastructure was
 * built rather than the feature being left out. It runs with **no session** — the caller is a
 * mailbox provider's server posting a signed token — so everything it does has to work from
 * nothing but a user id.
 *
 * Against a real database, because what it means to end a session here is two writes in two
 * tables and the whole point is that both happen: ending the `sessions` row leaves a live bearer
 * token, and revoking the token leaves a session the tracker still believes in.
 */
#[CoversClass(AuthServerServiceProvider::class)]
#[CoversClass(MailAction::class)]
class MailActionRevokeSessionsTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL only, like the rest of the auth suite.');
        }

        User::setupDb();

        /*
         * The real migration, not a hand-written CREATE TABLE.
         *
         * The first version of this test invented the schema — `sid`, `userid`, `logout`,
         * `date` — and passed in isolation. In the full suite another test had already created
         * the real `sessions` table, so `CREATE TABLE IF NOT EXISTS` did nothing and the insert
         * failed on `Unknown column 'date'`. A fixture that describes a table the application
         * does not have is a test of nothing.
         */
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Core\CreateSessionsTable::class,
        ], $this->db);

        $user = new User();
        $user->username = 'revoke_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->setPassword('Secr3t!pass');
        $user->save();
        $this->uid = (int) $user->userid;

        MailAction::reset();

        // The provider's own registration, rather than a copy of it: a test that registered its
        // own closure would pass while the shipped one was broken.
        (new class (Application::getInstance()) extends AuthServerServiceProvider {
            public function expose(): void
            {
                $this->registerMailActions();
            }
        })->expose();
    }

    protected function tearDown(): void
    {
        MailAction::reset();

        if ($this->uid > 0) {
            $this->db->queryBuilder()->table('#PREFIX#sessions')
                ->where('userid', $this->uid)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')
                ->where('userid', $this->uid)->delete();
        }

        parent::tearDown();
    }

    /**
     * Open sessions for the fixture user, in whatever columns this table actually has.
     *
     * Read rather than assumed, and that is not defensiveness. Four fixtures in this suite each
     * declare a `sessions` table with `CREATE TABLE IF NOT EXISTS` and a **different** set of
     * columns, so whichever test runs first decides the shape for the whole run: one has no
     * `visitorid`, another has no `sid`, one keys on `visitorid`. A fixture written against any
     * one of them passes alone and fails in the suite — which is exactly what happened twice
     * while writing this.
     *
     * So the row is built from the intersection of what `revokeOtherSessions()` needs and what
     * is there. If the columns it needs are missing, the test says so instead of failing
     * obscurely.
     */
    private function openSessions(int $count): void
    {
        $columns = $this->sessionColumns();

        foreach (['userid', 'logout'] as $required) {
            if (!in_array($required, $columns, true)) {
                $this->markTestSkipped(
                    'The `sessions` table in this test database has no `' . $required . '` '
                    . 'column — another suite\'s fixture defined it. Nothing to revoke.'
                );
            }
        }

        $candidate = [
            'sid'       => substr(bin2hex(random_bytes(16)), 0, 32),
            'visitorid' => bin2hex(random_bytes(8)),
            'userid'    => $this->uid,
            'logout'    => 0,
            'guest'     => 0,
            'time'      => time(),
            'agent'     => 'phpunit',
            'url'       => '/',
            'history'   => '',
            'host_addr' => '127.0.0.1',
            'uname'     => 'phpunit',
        ];

        for ($i = 0; $i < $count; $i++) {
            $candidate['sid']       = substr(bin2hex(random_bytes(16)), 0, 32);
            $candidate['visitorid'] = bin2hex(random_bytes(8));

            $this->db->queryBuilder()->table('#PREFIX#sessions')->insert(
                array_intersect_key($candidate, array_flip($columns))
            );
        }
    }

    /**
     * The columns this installation's `sessions` table really has.
     *
     * @return list<string>
     */
    private function sessionColumns(): array
    {
        $result  = $this->db->query('SHOW COLUMNS FROM `' . $this->db->prefix . 'sessions`');
        $columns = [];

        while ($result && $result->fetch()) {
            $columns[] = (string) ($result->fields['Field'] ?? '');
        }

        return array_values(array_filter($columns));
    }

    private function openSessionCount(): int
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('userid', $this->uid)
            ->where('logout', 0)
            ->get();

        return (int) ($result->numRows ?? 0);
    }

    /**
     * The action is registered by the feature, without anything else being wired.
     *
     * Registered and unused: the framework's own new-sign-in alert does not offer it, because
     * that message carries no link at all. The capability being present is the point.
     */
    public function testTheFeatureRegistersTheHandler(): void
    {
        // Assert
        $this->assertTrue(MailAction::has('revoke-sessions'));
    }

    /**
     * A POST ends every open session on the account.
     */
    public function testItEndsEverySession(): void
    {
        // Arrange
        $this->openSessions(3);
        $this->assertSame(3, $this->openSessionCount());

        // Act
        $result = MailAction::dispatch(
            MailAction::token('revoke-sessions', ['user' => $this->uid]),
            true
        );

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertSame(0, $this->openSessionCount());
        $this->assertStringContainsString('signed out', $result['message']);
        $this->assertStringContainsString(
            'Change your password',
            $result['message'],
            'the next step, because ending sessions does not change the password that leaked'
        );
    }

    /**
     * The count of ended sessions is a number.
     *
     * `revokeOtherSessions()` documents "how many session rows were ended" and returned a
     * `Result` cast to int — which raised a warning and produced a meaningless number. Nothing
     * broke visibly, because the sessions *were* ended correctly and only the count was wrong;
     * that is why it survived. Found by asserting the count rather than the effect.
     */
    public function testTheNumberOfEndedSessionsIsReported(): void
    {
        // Arrange
        $this->openSessions(3);

        // Act
        $ended = (new User($this->uid))->revokeOtherSessions(null);

        // Assert
        $this->assertSame(3, $ended);
        $this->assertSame(0, $this->openSessionCount());
        $this->assertSame(
            0,
            (new User($this->uid))->revokeOtherSessions(null),
            'and nothing left to end is zero, not a stale count'
        );
    }

    /**
     * Pressing it twice is a success both times.
     *
     * The idempotence the contract demands. Gmail retries, and a reader may press twice —
     * "there were no sessions left to end" is the desired state, not a failure. Returning false
     * would turn the second press into a 500 and, on a provider that retries 500s, into a loop.
     */
    public function testPressingItTwiceIsStillASuccess(): void
    {
        // Arrange
        $this->openSessions(2);
        $token = MailAction::token('revoke-sessions', ['user' => $this->uid]);

        // Act
        $first  = MailAction::dispatch($token, true);
        $second = MailAction::dispatch($token, true);

        // Assert
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(0, $this->openSessionCount());
    }

    /**
     * A GET does not end anything.
     *
     * A link scanner in a mail gateway follows links. If a GET acted, a scanned message would
     * sign somebody out of everything and they would never learn why.
     */
    public function testAGetDoesNotEndAnything(): void
    {
        // Arrange
        $this->openSessions(2);

        // Act
        $result = MailAction::dispatch(
            MailAction::token('revoke-sessions', ['user' => $this->uid]),
            false
        );

        // Assert
        $this->assertSame(405, $result['status']);
        $this->assertSame(2, $this->openSessionCount(), 'nothing was ended');
    }

    /**
     * A token naming an account that does not exist fails, and ends nothing.
     */
    public function testATokenForAnUnknownAccountFails(): void
    {
        // Arrange
        $this->openSessions(1);

        // Act
        $result = MailAction::dispatch(
            MailAction::token('revoke-sessions', ['user' => 99999999]),
            true
        );

        // Assert
        $this->assertSame(500, $result['status']);
        $this->assertSame(1, $this->openSessionCount());
    }

    /**
     * The guest and system accounts are refused.
     *
     * `revokeOtherSessions()` refuses them too, and a token naming one is a token that was built
     * wrong — so it fails loudly rather than reporting success for something it did not do.
     */
    public function testTheReservedAccountsAreRefused(): void
    {
        // Act & Assert
        foreach ([0, 1] as $reserved) {
            $result = MailAction::dispatch(
                MailAction::token('revoke-sessions', ['user' => $reserved]),
                true
            );

            $this->assertSame(500, $result['status'], 'user ' . $reserved . ' is not revocable');
        }
    }
}
