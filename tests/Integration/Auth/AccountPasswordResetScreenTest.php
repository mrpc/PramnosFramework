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
 * The two screens that let somebody back into an account they cannot sign in to.
 *
 * {@see PasswordResetTokenTest} owns the token: that only its hash is stored, that resolving one
 * does not spend it, that issuing again invalidates the last, that an expired one is refused. All
 * of it below the controller. `forgotpassword()` and `resetpassword()` are the actions on top —
 * 43 statements between them, none executed — and they are where the decisions live.
 *
 * The one this file exists for is **anti-enumeration**. A form that answers differently for an
 * address with an account and one without is a way to ask the site who its users are, one address
 * at a time, and it is the reason the code says the same thing either way. That is easy to write
 * and easy to lose: a later "helpful" branch, an error only one path can produce, a redirect that
 * differs. Asserted by comparing the two answers rather than by reading one of them.
 *
 * Beside it, three refusals that each cost nothing to get wrong and everything to notice late:
 * a missing CSRF token, a failed human check — this form sends mail to an address somebody else
 * typed, which is the cheapest way to use a site to deliver unwanted mail — and a reset link that
 * has already been used.
 *
 * The render methods are replaced by a recorder. What they do with a context is a theme's
 * business; what puts a value in that context is the subject here, and a real render would need a
 * view stack to assert one string.
 *
 * Both backends: {@see AccountPasswordResetScreenPostgreSQLTest} re-runs it. Every step is a
 * write and a read of `userdetails`, and the expiry is compared against the clock.
 */
#[CoversClass(Account::class)]
class AccountPasswordResetScreenTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private const EMAIL = 'reset_screen_probe@example.test';

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
        $user->username = 'reset_screen_' . bin2hex(random_bytes(4));
        $user->email    = self::EMAIL;
        $user->save();
        $this->uid = (int) $user->userid;

        $withPassword = new User($this->uid);
        $withPassword->setPassword('the-old-password-3!');
        $withPassword->save();

        \Pramnos\Http\RequestIdentity::reset();
        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();

        if ($this->uid > 0) {
            foreach (['#PREFIX#userdetails', '#PREFIX#users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }

        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
        User::clearUserCache();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── Asking for a link ─────────────────────────────────────────────────────

    /**
     * A known address and an unknown one produce the same answer.
     *
     * The property the whole action is shaped around, asserted by comparing rather than by
     * reading: any difference at all — a different context key, an error only one path can
     * produce — turns this form into a way to ask the site who its users are.
     *
     * The difference that must exist is on the *inside*: a token is written for the account that
     * exists and not for the one that does not.
     */
    public function testTheAnswerIsTheSameWhetherOrNotTheAddressHasAnAccount(): void
    {
        // Arrange & Act — a real address.
        $known = $this->probe();
        $this->postWithToken(['email' => self::EMAIL]);
        $known->forgotpassword();

        // …and one nobody has.
        $unknown = $this->probe();
        $this->postWithToken(['email' => 'nobody_at_all@example.test']);
        $unknown->forgotpassword();

        // Assert
        $this->assertSame(
            $known->rendered,
            $unknown->rendered,
            'the answer differs, so the form says which addresses have accounts'
        );
        $this->assertSame('sent', $known->rendered[0]['ctx']['message'] ?? null);
        $this->assertNotSame('', $this->storedTokenHash(), 'no reset token was issued');
    }

    /** An address with no account writes nothing at all. */
    public function testAnUnknownAddressIssuesNoToken(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken(['email' => 'nobody_at_all@example.test']);

        // Act
        $probe->forgotpassword();

        // Assert
        $this->assertSame([], $probe->mailed, 'mail was sent for an address with no account');
        $this->assertSame('', $this->storedTokenHash());
    }

    /**
     * A POST with no anti-CSRF token issues nothing.
     *
     * The form mails an address the submitter chose. Without the token, a page anybody writes can
     * make a signed-out visitor's browser ask this site to send mail, as often as it likes.
     */
    public function testAForgotPostWithoutTheTokenIssuesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['email' => self::EMAIL];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->forgotpassword();

        // Assert
        $this->assertSame('invalid_token', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame([], $probe->mailed);
        $this->assertSame('', $this->storedTokenHash());
    }

    /**
     * A failed human check issues nothing, and the address is kept so the form can be re-shown.
     *
     * The check is what stands between this form and being used to deliver mail to strangers.
     * Keeping the typed address in the context is the other half: a refusal that also clears the
     * field trains people to give up rather than to try again.
     */
    public function testAFailedHumanCheckIssuesNothing(): void
    {
        // Arrange
        $probe = $this->probe(humanCheckPasses: false);
        $this->postWithToken(['email' => self::EMAIL]);

        // Act
        $probe->forgotpassword();

        // Assert
        $this->assertSame('human_check', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame(self::EMAIL, $probe->rendered[0]['ctx']['email'] ?? null);
        $this->assertSame([], $probe->mailed);
        $this->assertSame('', $this->storedTokenHash());
    }

    /** Something that is not an address is refused before anything is looked up. */
    public function testAMalformedAddressIsRefused(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act & Assert
        foreach (['', 'not-an-address', 'a@b', '@example.test'] as $attempt) {
            $this->postWithToken(['email' => $attempt]);
            $probe->forgotpassword();
        }

        $errors = array_column(array_column($probe->rendered, 'ctx'), 'error');
        $this->assertSame(
            ['invalid_email', 'invalid_email', 'invalid_email', 'invalid_email'],
            $errors,
            'something that is not an address was accepted'
        );
        $this->assertSame([], $probe->mailed);
    }

    /** A GET renders the form and issues nothing. */
    public function testAGetJustRendersTheForm(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->forgotpassword();

        // Assert
        $this->assertSame('forgot', $probe->rendered[0]['view'] ?? null);
        $this->assertSame([], $probe->rendered[0]['ctx']);
        $this->assertSame('', $this->storedTokenHash());
    }

    /** Somebody already signed in is sent on rather than shown the form. */
    public function testASignedInVisitorIsSentOn(): void
    {
        // Arrange
        \Pramnos\Http\RequestIdentity::seal(new User($this->uid), 'test');
        $probe = $this->probe();

        // Act
        $probe->forgotpassword();

        // Assert
        $this->assertSame([], $probe->rendered, 'the form was shown to somebody already signed in');
        $this->assertNotSame([], $probe->redirects);
    }

    // ── Using the link ────────────────────────────────────────────────────────

    /**
     * The whole round trip: ask for a link, use it, sign in with the new password.
     *
     * The only assertion that catches the two halves disagreeing — a token issued in one shape
     * and looked up in another passes every test on either side of the boundary and none across
     * it.
     */
    public function testALinkIssuedByOneScreenIsAcceptedByTheOther(): void
    {
        // Arrange
        $token = $this->issueToken();

        // Act
        $probe = $this->probe();
        $this->postWithToken([
            'token'            => $token,
            'password'         => 'a-brand-new-password-9!',
            'confirm_password' => 'a-brand-new-password-9!',
        ]);
        $probe->resetpassword();

        // Assert
        $this->assertSame([], $probe->rendered, 'the reset was refused: ' . json_encode($probe->rendered));
        $this->assertNotSame([], $probe->redirects);
        $this->assertTrue(
            (new User($this->uid))->verifyPassword('a-brand-new-password-9!'),
            'the new password does not work'
        );
        $this->assertSame('', $this->storedTokenHash(), 'the token was not spent');
    }

    /**
     * The same link does not work twice.
     *
     * A reset link lives in a mailbox, and a mailbox outlives the reset. Once it has been used,
     * the only thing standing between whoever reads that mail later and the account is this.
     */
    public function testALinkCannotBeUsedTwice(): void
    {
        // Arrange
        $token = $this->issueToken();
        $probe = $this->probe();
        $this->postWithToken([
            'token'            => $token,
            'password'         => 'a-brand-new-password-9!',
            'confirm_password' => 'a-brand-new-password-9!',
        ]);
        $probe->resetpassword();

        // Act — the same link again, with a password of the attacker's choosing.
        $again = $this->probe();
        $this->postWithToken([
            'token'            => $token,
            'password'         => 'chosen-by-somebody-else-1!',
            'confirm_password' => 'chosen-by-somebody-else-1!',
        ]);
        $again->resetpassword();

        // Assert
        $this->assertSame('invalid_reset_link', $again->rendered[0]['ctx']['error'] ?? null);
        $this->assertFalse(
            (new User($this->uid))->verifyPassword('chosen-by-somebody-else-1!'),
            'a spent reset link set the password a second time'
        );
    }

    /**
     * A reset POST without the anti-CSRF token changes nothing **and does not spend the token**.
     *
     * Two failures in one branch if it is wrong. The obvious one is the reset going through; the
     * quiet one is the token being consumed on the way to refusing, which would leave the account
     * holder with a link that no longer works and no idea why.
     */
    public function testAResetWithoutTheTokenChangesNothingAndKeepsTheLinkUsable(): void
    {
        // Arrange
        $token = $this->issueToken();
        $probe = $this->probe();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'token'            => $token,
            'password'         => 'a-brand-new-password-9!',
            'confirm_password' => 'a-brand-new-password-9!',
        ];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->resetpassword();

        // Assert
        $this->assertSame('invalid_token', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertFalse((new User($this->uid))->verifyPassword('a-brand-new-password-9!'));
        $this->assertNotSame('', $this->storedTokenHash(), 'the refusal spent the reset link');
    }

    /**
     * A password the policy refuses re-offers the same link.
     *
     * The token is resolved before the policy is checked and only cleared on success, which is
     * what makes "try again with a longer one" possible. Spending it here would mean one typo
     * costs a second trip through the mailbox.
     */
    public function testAPolicyFailureLeavesTheLinkUsable(): void
    {
        // Arrange
        $token = $this->issueToken();
        $probe = $this->probe();
        $this->postWithToken([
            'token'            => $token,
            'password'         => 'x',
            'confirm_password' => 'x',
        ]);

        // Act
        $probe->resetpassword();

        // Assert
        $this->assertNotSame('', (string) ($probe->rendered[0]['ctx']['error'] ?? ''));
        $this->assertSame(
            $token,
            $probe->rendered[0]['ctx']['token'] ?? null,
            'the form was re-shown without the link it needs'
        );
        $this->assertNotSame('', $this->storedTokenHash(), 'a rejected password spent the link');

        // …and the link still works.
        $second = $this->probe();
        $this->postWithToken([
            'token'            => $token,
            'password'         => 'a-brand-new-password-9!',
            'confirm_password' => 'a-brand-new-password-9!',
        ]);
        $second->resetpassword();
        $this->assertTrue((new User($this->uid))->verifyPassword('a-brand-new-password-9!'));
    }

    /** A link nobody issued is refused, and the form is offered without one. */
    public function testAnInventedLinkIsRefused(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken([
            'token'            => str_repeat('f', 64),
            'password'         => 'a-brand-new-password-9!',
            'confirm_password' => 'a-brand-new-password-9!',
        ]);

        // Act
        $probe->resetpassword();

        // Assert
        $this->assertSame('invalid_reset_link', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame('', $probe->rendered[0]['ctx']['token'] ?? null);
        $this->assertFalse((new User($this->uid))->verifyPassword('a-brand-new-password-9!'));
    }

    /**
     * Arriving with no token at all lands on the "ask for a link" form.
     *
     * Somebody following a truncated link, or an old bookmark. Showing a password form with no
     * token behind it would collect a new password and then refuse it.
     */
    public function testArrivingWithNoTokenOffersTheForgotForm(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->resetpassword();

        // Assert
        $this->assertSame('forgot', $probe->rendered[0]['view'] ?? null);
        $this->assertSame('invalid_reset_link', $probe->rendered[0]['ctx']['error'] ?? null);
    }

    /** A GET carrying a token renders the new-password form with it. */
    public function testAGetWithATokenRendersTheResetForm(): void
    {
        // Arrange
        $probe = $this->probe();
        $_GET['token'] = 'whatever-was-in-the-link';

        // Act
        $probe->resetpassword();

        // Assert
        $this->assertSame('reset', $probe->rendered[0]['view'] ?? null);
        $this->assertSame('whatever-was-in-the-link', $probe->rendered[0]['ctx']['token'] ?? null);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the two things a test cannot let happen replaced.
     *
     * The renders record their context instead of reaching a theme, and the mail is recorded
     * instead of sent. Everything between — the CSRF check, the address lookup, the token write,
     * the policy, the password update — is real, and it is where the 43 uncovered statements were.
     */
    private function probe(bool $humanCheckPasses = true): object
    {
        return new class ($this->db, $humanCheckPasses) extends Account {
            /** @var list<array{view: string, ctx: array}> */
            public array $rendered = [];

            /** @var list<string> the addresses a reset mail was composed for */
            public array $mailed = [];

            public array $redirects = [];

            public array $messages = [];

            public function __construct(\Pramnos\Database\Database $db, private bool $human)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            protected function renderForgot(array $ctx): mixed
            {
                $this->rendered[] = ['view' => 'forgot', 'ctx' => $ctx];

                return null;
            }

            protected function renderReset(array $ctx): mixed
            {
                $this->rendered[] = ['view' => 'reset', 'ctx' => $ctx];

                return null;
            }

            protected function humanCheckPasses(string $form): bool
            {
                return $this->human;
            }

            protected function sendResetEmail(string $email, string $token, int $userId = 0): void
            {
                $this->mailed[] = $email;
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
        };
    }

    /**
     * Ask for a link the way a person does, and return the token that was mailed.
     *
     * Through the action rather than by writing a row, so what the reset screen is handed is what
     * the forgot screen actually issues — which is the half of the round trip a fixture that
     * writes its own token would quietly replace.
     */
    private function issueToken(): string
    {
        $captured = null;
        $probe = new class ($this->db, $captured) extends Account {
            public string $token = '';

            public function __construct(\Pramnos\Database\Database $db, &$captured)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            protected function renderForgot(array $ctx): mixed
            {
                return null;
            }

            protected function humanCheckPasses(string $form): bool
            {
                return true;
            }

            protected function sendResetEmail(string $email, string $token, int $userId = 0): void
            {
                $this->token = $token;
            }
        };

        $this->postWithToken(['email' => self::EMAIL]);
        $probe->forgotpassword();

        $this->assertNotSame('', $probe->token, 'no reset link was issued');

        return $probe->token;
    }

    /** The stored hash of the outstanding reset token, or '' when there is none. */
    private function storedTokenHash(): string
    {
        $row = $this->db->queryBuilder()
            ->table('#PREFIX#userdetails')
            ->select(['value'])
            ->where('userid', $this->uid)
            ->where('fieldname', 'password_reset_hash')
            ->first();

        return (string) ($row->fields['value'] ?? '');
    }

    /** A POST carrying the session's anti-CSRF token. */
    private function postWithToken(array $fields): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields + [$session->getToken() => $session->getFingerprint()];
        \Pramnos\Http\Request::resetInstance();
    }
}
