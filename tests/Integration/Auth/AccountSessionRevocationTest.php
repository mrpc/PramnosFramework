<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * Signing another device out, and the promise the export screen makes.
 *
 * `revokesession()` is the control somebody uses after losing a laptop, and nothing executed it.
 * It is POST-only with the anti-CSRF token, and scoped to the caller's own account: a session id
 * is a string in a form, and an unscoped update would let anybody sign anybody out.
 *
 * The export itself is covered by {@see AccountExportTest} — sections populated, secrets
 * excluded, the extensibility hook. What is asserted here is one thing that test does not: that
 * the **labels** the screen lists before somebody presses the button match the sections the file
 * actually contains. A label with no section is a promise the file does not keep, and the reader
 * cannot tell whether the data is missing or was never held.
 *
 * Runs on every backend — {@see AccountSessionRevocationPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB.
 */
#[CoversClass(Account::class)]
class AccountSessionRevocationTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private int $otherUid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
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

        User::setupDb();

        /*
         * Only `sessions`, and recreated rather than ensured.
         *
         * Nothing here reads the export's eleven tables any more — `AccountExportTest` owns
         * that — and creating them would be worse than useless: several other tests build
         * `usertokens`, `tokenactions` and `applications` with **their own minimal shapes** using
         * `CREATE TABLE IF NOT EXISTS`, so whichever runs first decides the columns and the loser
         * fails on an insert. Exactly the conflict that cost this iteration a flake.
         *
         * `sessions` is dropped first for the same reason, so this test owns the shape it asserts
         * against whatever ran before it.
         */
        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#sessions')
        );
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Core\CreateSessionsTable::class,
        ], $this->db);

        foreach (['uid', 'otherUid'] as $property) {
            $user = new User();
            $user->username = 'export_' . bin2hex(random_bytes(4));
            $user->email    = $user->username . '@example.com';
            $user->save();
            $this->$property = (int) $user->userid;
        }
        // `setPassword()` hashes onto the loaded row; it does not write. The save is what
        // persists it, and the row has to exist first so the hash is salted with the real id.
        $withPassword = new User($this->uid);
        $withPassword->setPassword('an-actual-password-1!');
        $withPassword->save();

        /*
         * A signed-in caller, because `revokesession` is auth-registered.
         *
         * `RequestIdentity::seal()` is how a request settles who it is — the same mechanism an
         * API call uses — so this needs no session cookie and no login flow. Without it
         * `getCurrentUser()` answers `false` and the action reads `->userid` on it, which is a
         * state the dispatcher makes unreachable in production and a warning in a test.
         */
        \Pramnos\Http\RequestIdentity::seal(new User($this->uid), 'test');

        $_POST   = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
        \Pramnos\Event\Event::forget('account.data_export');
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Event\Event::forget('account.data_export');

        foreach ([$this->uid, $this->otherUid] as $userId) {
            if ($userId <= 0) {
                continue;
            }
            foreach (['#PREFIX#sessions', '#PREFIX#userdetails', '#PREFIX#users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $userId)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }

        $_POST   = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The export ────────────────────────────────────────────────────────────

    /**
     * Every section the labels promise is a section the export contains.
     *
     * The labels are what the screen lists before somebody presses the button. A label with no
     * section is a promise the file does not keep, and the reader has no way to tell whether the
     * data is missing or was never held.
     */
    public function testTheLabelsAndTheSectionsAgree(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $labels   = $probe->probeLabels();
        $export   = $probe->probeExport($this->uid);
        $sections = array_diff(array_keys($export), ['export_date', 'userid']);

        // Assert
        $this->assertSameSize(
            $labels,
            $sections,
            'the screen lists ' . count($labels) . ' sections and the file has ' . count($sections)
        );
    }

    // ── Revoking a session ────────────────────────────────────────────────────

    /**
     * A POST with the token signs the named session out.
     */
    public function testARevokeSignsTheSessionOut(): void
    {
        // Arrange
        $sid   = 'sid-' . bin2hex(random_bytes(6));
        $this->seedSession($this->uid, $sid);
        $probe = $this->probe();
        $this->postWithToken(['sid' => $sid]);

        // Act
        $probe->revokesession();

        // Assert
        $this->assertSame(1, $this->logoutFlag($sid), 'the session was not signed out');
        $this->assertNotSame([], $probe->messages);
    }

    /**
     * A GET revokes nothing, token or not.
     *
     * Somebody else's link, a prefetch, an unfurled URL in a chat client — any of them would sign
     * a person out of their own session on a GET.
     */
    public function testAGetRevokesNothing(): void
    {
        // Arrange
        $sid = 'sid-' . bin2hex(random_bytes(6));
        $this->seedSession($this->uid, $sid);
        $probe = $this->probe();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = ['sid' => $sid];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->revokesession();

        // Assert
        $this->assertSame(0, $this->logoutFlag($sid));
        $this->assertSame([], $probe->messages);
    }

    /** And a POST without the token revokes nothing either. */
    public function testAPostWithoutTheTokenRevokesNothing(): void
    {
        // Arrange
        $sid = 'sid-' . bin2hex(random_bytes(6));
        $this->seedSession($this->uid, $sid);
        $probe = $this->probe();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['sid' => $sid];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->revokesession();

        // Assert
        $this->assertSame(0, $this->logoutFlag($sid));
    }

    /**
     * Somebody else's session cannot be revoked, even with a valid token.
     *
     * The session id is a string in a form. Scoping the update to the caller's own `userid` is
     * what makes guessing one useless — without it, this control would sign other people out.
     */
    public function testAnotherAccountsSessionCannotBeRevoked(): void
    {
        // Arrange
        $sid = 'sid-' . bin2hex(random_bytes(6));
        $this->seedSession($this->otherUid, $sid);
        $probe = $this->probe();
        $this->postWithToken(['sid' => $sid]);

        // Act
        $probe->revokesession();

        // Assert
        $this->assertSame(
            0,
            $this->logoutFlag($sid),
            "another account's session was signed out"
        );
    }

    /** An empty session id does nothing and says nothing. */
    public function testAnEmptySessionIdDoesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken(['sid' => '']);

        // Act
        $probe->revokesession();

        // Assert
        $this->assertSame([], $probe->messages, 'nothing was revoked, so nothing is reported');
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** The controller with the redirect, the flash bag and the signed-in account replaced. */
    private function probe(): object
    {
        return new class ($this->db, $this->uid) extends Account {
            public array $messages = [];

            public array $errors = [];

            public array $redirects = [];

            public function __construct(\Pramnos\Database\Database $db, private int $userId)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            public function probeExport(int $userId): array
            {
                return $this->buildExportData($userId);
            }

            public function probeLabels(): array
            {
                return $this->exportSectionLabels();
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }
        };
    }

    /** A POST carrying the anti-CSRF token the form would have carried. */
    private function postWithToken(array $fields): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields + [$session->getToken() => $session->getFingerprint()];
        \Pramnos\Http\Request::resetInstance();
    }

    private function seedSession(int $userId, string $sid): void
    {
        // Every NOT NULL column named, because on PostgreSQL an omitted one without a default
        // is a violation rather than an empty string — the same lesson as
        // `messages.attachmenttext` earlier today. And `time`, not `lastseen`: that is what the
        // column has been called since the table was created, and what the export selects.
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => 'visitor-' . bin2hex(random_bytes(4)),
            'sid'       => $sid,
            'userid'    => $userId,
            'logout'    => 0,
            'time'      => time(),
            'agent'     => 'phpunit',
            'url'       => '/',
            'history'   => '',
        ]);
    }

    private function logoutFlag(string $sid): int
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#sessions')
            ->select(['logout'])->where('sid', $sid)->first();

        return (int) ($row->fields['logout'] ?? -1);
    }

    private function passwordOf(int $userId): string
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#users')
            ->select(['password'])->where('userid', $userId)->first();

        return (string) ($row->fields['password'] ?? '');
    }
}
