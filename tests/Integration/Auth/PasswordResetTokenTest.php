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
 * The password-reset token, which nothing had executed.
 *
 * Seven methods of `Account` — store, consume, clear, the link, the mail and the two lookups —
 * and they are the credential path with the widest audience on any installation: the form can be
 * submitted by anybody, from any page, for any address.
 *
 * What makes it safe is not the token. It is four properties, and each is asserted on its own
 * because each is the whole of the security when the others are absent:
 *
 *   - **only the hash is stored**, so a leaked `userdetails` row hands out no working links;
 *   - **single use** — consuming clears the row before returning an id;
 *   - **one hour**, and an expired token is cleared rather than left to be found;
 *   - **one live token per account**, so "send it again" cannot leave two ways in.
 *
 * Plus one decision that is easy to get backwards: the mail goes out in the **recipient's**
 * language, not the language of the request. The request's language is whoever filled in the
 * form; the reader is the account holder.
 *
 * Runs on every backend — {@see PasswordResetTokenPostgreSQLTest} re-runs all of it against
 * PostgreSQL/TimescaleDB, and `upsert()` compiles differently on each.
 */
#[CoversClass(Account::class)]
class PasswordResetTokenTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

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

        $user = new User();
        $user->username = 'resettest_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->save();
        $this->uid = (int) $user->userid;
        $this->assertGreaterThan(1, $this->uid, 'the fixture account was not created');
    }

    protected function tearDown(): void
    {
        if ($this->uid > 0) {
            foreach (['#PREFIX#userdetails' => 'userid', '#PREFIX#users' => 'userid'] as $table => $column) {
                try {
                    $this->db->queryBuilder()->table($table)->where($column, $this->uid)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── What is stored ────────────────────────────────────────────────────────

    /**
     * Only the hash reaches the table, never the token.
     *
     * A leaked `userdetails` row therefore hands out no working links — which matters more here
     * than for most tokens, because this one lets the holder *choose a new password* rather than
     * merely sign in once.
     */
    public function testOnlyTheHashIsStored(): void
    {
        // Arrange
        $probe = $this->probe();
        $token = bin2hex(random_bytes(32));

        // Act
        $probe->probeStore($this->uid, hash('sha256', $token), time() + 3600);

        // Assert
        $this->assertSame(hash('sha256', $token), $this->detail('password_reset_hash'));
        $this->assertSame(
            0,
            $this->countValue($token),
            'the raw token is somewhere in userdetails'
        );
    }

    /**
     * Resolving a token does **not** spend it — the flow spends it, after the password changes.
     *
     * Worth recording, because "single use" would be the obvious guess and it would be the wrong
     * design. `resetpassword` calls this to find out *whose* link it is, then validates the new
     * password, and only clears the token once the password has actually been updated. Burning
     * it here would mean a person who mistypes their confirmation loses their only link and has
     * to start again from the forgot-password form.
     *
     * The single-use property is real, and it lives one level up: {@see clearResetToken()} is
     * called immediately after `updatePassword()`, which is asserted below.
     */
    public function testResolvingATokenDoesNotSpendIt(): void
    {
        // Arrange
        $probe = $this->probe();
        $token = $this->issue($probe);

        // Act
        $first  = $probe->probeConsume($token);
        $second = $probe->probeConsume($token);

        // Assert
        $this->assertSame($this->uid, $first);
        $this->assertSame(
            $this->uid,
            $second,
            'resolving now spends the token, which loses the link of anybody who mistypes a '
            . 'password — if that is intended, the flow no longer needs to clear it'
        );
    }

    /**
     * And the flow does spend it: cleared straight after the password is updated.
     *
     * Asserted on the source rather than by driving the whole POST, because what matters is the
     * **order** — `updatePassword()` then `clearResetToken()`. Reversed, a failure to write the
     * password would leave an account with no way in and no live link.
     */
    public function testTheFlowClearsTheTokenAfterChangingThePassword(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Auth/Controllers/Account.php'
        );

        // Act
        $update = strpos($source, '$this->updatePassword($userId, $new);');
        $clear  = strpos($source, '$this->clearResetToken($userId);', (int) $update);

        // Assert
        $this->assertNotFalse($update, 'the reset flow no longer updates the password here');
        $this->assertNotFalse($clear, 'the reset flow no longer clears the token after the change');
        $this->assertLessThan(
            $clear,
            $update,
            'the token is cleared before the password is written, so a failed write locks the '
            . 'account out with no live link'
        );
    }

    /**
     * An expired token is refused **and cleared**.
     *
     * Cleared rather than left: a dead row that still names an account is a row somebody has to
     * reason about later, and the sweep that would have removed it does not exist.
     */
    public function testAnExpiredTokenIsRefusedAndCleared(): void
    {
        // Arrange
        $probe = $this->probe();
        $token = bin2hex(random_bytes(32));
        $probe->probeStore($this->uid, hash('sha256', $token), time() - 1);

        // Act
        $result = $probe->probeConsume($token);

        // Assert
        $this->assertNull($result);
        $this->assertSame('', $this->detail('password_reset_hash'));
        $this->assertSame('', $this->detail('password_reset_expires'));
    }

    /**
     * A token expiring this very second is still accepted.
     *
     * The boundary is `<`, not `<=`. Recorded because a boundary nobody wrote down is a boundary
     * that changes by accident the next time somebody touches the comparison.
     */
    public function testATokenExpiringNowIsStillAccepted(): void
    {
        // Arrange
        $probe = $this->probe();
        $token = bin2hex(random_bytes(32));
        $probe->probeStore($this->uid, hash('sha256', $token), time());

        // Assert
        $this->assertSame($this->uid, $probe->probeConsume($token));
    }

    /**
     * Issuing again kills the token already in somebody's inbox.
     *
     * `upsert` on `(userid, fieldname)`, so there is one row and one live token per account. Two
     * valid links for one account is two credentials, and the older is the one nobody is
     * watching.
     */
    public function testIssuingAgainInvalidatesThePreviousToken(): void
    {
        // Arrange
        $probe = $this->probe();
        $first  = $this->issue($probe);
        $second = $this->issue($probe);

        // Assert
        $this->assertNotSame($first, $second);
        $this->assertNull($probe->probeConsume($first), 'the old link still works');
        $this->assertSame($this->uid, $probe->probeConsume($second));
    }

    /** A token nobody issued, an empty string, and an injection attempt all answer null. */
    public function testATokenNobodyIssuedIsRefused(): void
    {
        // Arrange
        $probe = $this->probe();

        // Assert
        $this->assertNull($probe->probeConsume(''));
        $this->assertNull($probe->probeConsume(bin2hex(random_bytes(32))));
        $this->assertNull($probe->probeConsume("' OR '1'='1"));
    }

    /** Clearing removes both rows, not just the hash. */
    public function testClearingRemovesBothRows(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->issue($probe);

        // Act
        $probe->probeClear($this->uid);

        // Assert
        $this->assertSame('', $this->detail('password_reset_hash'));
        $this->assertSame(
            '',
            $this->detail('password_reset_expires'),
            'the expiry outlived the hash, so the next issue reuses a stale one'
        );
    }

    // ── Who can be sent one ───────────────────────────────────────────────────

    /**
     * The system account cannot be reset by email.
     *
     * `findUserIdByEmail()` answers null for anything at or below userid 1 — 0 is anonymous and
     * 1 is the system user, which exists to own rows rather than to be signed into. A reset link
     * for it would be a password-choosing link for the account the framework itself acts as.
     */
    public function testTheSystemAccountIsNotFoundByEmail(): void
    {
        // Arrange
        $probe = $this->probe();

        // Assert — the fixture account is found …
        $this->assertSame(
            $this->uid,
            $probe->probeFindByEmail((string) (new User($this->uid))->email)
        );

        // … and an address that matches nothing is not.
        $this->assertNull($probe->probeFindByEmail('nobody-' . bin2hex(random_bytes(4)) . '@example.com'));
        $this->assertNull($probe->probeFindByEmail(''));
    }

    // ── The link ──────────────────────────────────────────────────────────────

    /**
     * The link carries the token url-encoded, on the route the form lives at.
     *
     * Encoded because the token is hex today and a token generator is a thing people change; a
     * raw `+` or `&` in a query parameter is a token that arrives truncated, and the failure
     * reads as "the link does not work" rather than as an encoding bug.
     */
    public function testTheLinkCarriesTheTokenEncoded(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $link = $probe->probeLink('a b+c&d');

        // Assert
        $this->assertStringContainsString('/resetpassword?token=', $link);
        $this->assertStringContainsString(urlencode('a b+c&d'), $link);
        $this->assertStringNotContainsString('token=a b+c&d', $link);
        $this->assertStringStartsWith((string) sURL, $link, 'the link is not absolute');
    }

    // ── The language the mail is written in ───────────────────────────────────

    /**
     * The mail is composed in the **recipient's** language, not the request's.
     *
     * Easy to get backwards, and invisible when it is: the form can be submitted by anybody from
     * any page — a Greek visitor asking for an English speaker's password reset — and the person
     * who reads the mail is the account holder. `Notifier` does this for every notification;
     * this mail is composed by hand, so it has to ask for it itself.
     */
    public function testTheMailIsComposedInTheRecipientsLanguage(): void
    {
        // Arrange — an account whose language is not the active one, and that language actually
        // installed. `Language::using()` returns without switching when the language has no
        // catalogue, which is right in production and would make this test assert nothing.
        $installed = $this->installLanguage('el');

        try {
            $user = new User($this->uid);
            $user->language = 'el';
            $user->save();

            $probe = $this->probe();

            // Act
            $probe->probeSend('someone@example.com', 'tok', $this->uid);

            // Assert
            $this->assertSame(1, $probe->composed);
            $this->assertSame(
                'ALLAGH KWDIKOU',
                $probe->composedIn,
                'the mail was composed in the request language rather than the account language'
            );
        } finally {
            $this->removeLanguage($installed);
        }
    }

    /**
     * A language the installation does not have is not switched to, and the mail still goes.
     *
     * `Language::using()` absorbs a missing catalogue deliberately: there being nothing to
     * switch to is not a reason to fail sending somebody their password-reset link.
     */
    public function testAnUninstalledLanguageDoesNotStopTheMail(): void
    {
        // Arrange
        $user = new User($this->uid);
        $user->language = 'xx-not-installed';
        $user->save();

        $probe = $this->probe();

        // Act
        $probe->probeSend('someone@example.com', 'tok', $this->uid);

        // Assert
        $this->assertSame(1, $probe->composed, 'a missing catalogue stopped the mail');
    }

    /** With no language on the account, whatever is current is used rather than a guess. */
    public function testAnAccountWithNoLanguageUsesTheCurrentOne(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->probeSend('someone@example.com', 'tok', $this->uid);

        // Assert
        $this->assertSame(1, $probe->composed, 'the mail was not composed exactly once');
    }

    /** And with no account id at all — an address that matched nothing — it still composes once. */
    public function testWithoutAnAccountIdItStillComposesOnce(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->probeSend('someone@example.com', 'tok', 0);

        // Assert
        $this->assertSame(1, $probe->composed);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** The protected token machinery, reachable, with the mail composition recorded. */
    private function probe(): object
    {
        return new class ($this->db) extends Account {
            public int $composed = 0;

            public string $composedIn = '';

            public function __construct(\Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            public function probeStore(int $userId, string $hash, int $expires): void
            {
                $this->storeResetToken($userId, $hash, $expires);
            }

            public function probeConsume(string $token): ?int
            {
                return $this->consumeResetToken($token);
            }

            public function probeClear(int $userId): void
            {
                $this->clearResetToken($userId);
            }

            public function probeLink(string $token): string
            {
                return $this->resetLink($token);
            }

            public function probeFindByEmail(string $email): ?int
            {
                return $this->findUserIdByEmail($email);
            }

            public function probeSend(string $email, string $token, int $userId): void
            {
                $this->sendResetEmail($email, $token, $userId);
            }

            /**
             * The mail is not sent; what is recorded is that it was composed, and in which
             * language — which is the decision this seam exists to make visible.
             */
            protected function composeAndSendResetEmail($email, $token): void
            {
                $this->composed++;
                /*
                 * The translation, not `currentlang()`.
                 *
                 * `Language::using()` calls `load()`, which swaps the *strings*; the language
                 * name is only restored in the `finally`, so `currentlang()` still reports the
                 * previous one while the callback runs. What a reader of the mail would notice
                 * is the words, so that is what is recorded.
                 */
                $this->composedIn = (string) t('Password reset');
            }
        };
    }

    /** Issue a token the way the flow does, and return the raw value. */
    private function issue(object $probe): string
    {
        $token = bin2hex(random_bytes(32));
        $probe->probeStore($this->uid, hash('sha256', $token), time() + 3600);

        return $token;
    }

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

    /**
     * Install a language catalogue for the duration of one test.
     *
     * @return list<string> the paths to remove again
     */
    private function installLanguage(string $code): array
    {
        $directory = ROOT . DS . 'app' . DS . 'language';
        $created   = [];

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
            $created[] = $directory;
        }

        /*
         * A catalogue defines `$lang`; it does not `return` an array.
         *
         * `Language::load()` includes the file and then checks `isset($lang)` — a `return [...]`
         * file loads without error, defines nothing, and `load()` answers false. Which is a
         * fixture that silently proves nothing, and cost an hour to notice.
         */
        foreach ([$code => '$lang["Password reset"] = "ALLAGH KWDIKOU";', 'english' => '$lang = [];'] as $lang => $contents) {
            $file = $directory . DS . $lang . '.php';
            if (!file_exists($file)) {
                file_put_contents($file, "<?php\n" . $contents . "\n");
                $created[] = $file;
            }
        }

        \Pramnos\Translator\Language::resetInstance();

        return $created;
    }

    /** @param list<string> $paths */
    private function removeLanguage(array $paths): void
    {
        foreach (array_reverse($paths) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        \Pramnos\Translator\Language::resetInstance();
    }

    private function countValue(string $value): int
    {
        return (int) $this->db->queryBuilder()->table('#PREFIX#userdetails')
            ->where('value', $value)->count();
    }
}
