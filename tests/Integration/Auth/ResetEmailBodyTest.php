<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The password-reset mail itself — 21 statements, never executed.
 *
 * Every existing test of this flow replaces `sendResetEmail()` with a recorder, which is right for
 * what those tests are about: the anti-enumeration property, the refusals, the single-use token.
 * The consequence is that the one thing this mail exists to deliver — a working link, in a message
 * a person will act on — had never been composed.
 *
 * Read through the `mails` audit row rather than through a stubbed mailer, because that is what
 * `send()` records and it is the same row an operator reads when somebody says the mail never
 * arrived. It is written whether the transport succeeded or not, which is what makes it usable
 * here: the test asserts what was composed, not that this machine can deliver mail.
 *
 * Three properties, and each of them is why a reset mail fails in the field rather than in a test:
 *
 * - **the link is in the body as text as well as a link.** Mail clients strip anchors, corporate
 *   gateways rewrite them, and a plain-text reader sees nothing at all — so the URL is printed
 *   where it can be copied. Only one of the two is enough to make the mail useless for somebody.
 * - **it says how long it lasts.** A person who opens the mail the next morning needs to know why
 *   the link is dead, or they conclude the site is broken rather than asking for another.
 * - **a transport failure is swallowed.** Not laziness: a 500 out of this would tell an
 *   enumerator whether the address matched an account, which is the whole thing the surrounding
 *   flow is built to hide. The token is already stored, so the request can be retried once mail
 *   is configured.
 *
 * Both backends: {@see ResetEmailBodyPostgreSQLTest}. The audit row is an insert and a read, and
 * the body is a `text` column holding HTML.
 */
#[CoversClass(Account::class)]
class ResetEmailBodyTest extends BaseTestCase
{
    private $db;

    private string $recipient = '';

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

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
        ], $this->db);

        // Its own address per test, so the audit row this test reads is the one it wrote.
        $this->recipient = 'reset_mail_' . bin2hex(random_bytes(4)) . '@example.test';
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        if ($this->recipient !== '') {
            try {
                $this->db->queryBuilder()->table('#PREFIX#mails')
                    ->where('tomail', $this->recipient)->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }

        parent::tearDown();
    }

    /** A controller exposing the mail composer, with nothing else replaced. */
    private function probe(): object
    {
        return new class extends Account {
            public function __construct()
            {
            }

            public function callCompose(string $email, string $token): void
            {
                $this->composeAndSendResetEmail($email, $token);
            }

            public function callResetLink(string $token): string
            {
                return $this->resetLink($token);
            }
        };
    }

    /** The audit row this mail left behind, or null. */
    private function recorded(): ?array
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#mails')
            ->where('tomail', $this->recipient)
            // `id`, not `mailid` — read off the migration rather than guessed.
            ->orderBy('id', 'desc')
            ->first();

        if (!$result || $result->numRows === 0) {
            return null;
        }

        return (array) $result->fields;
    }

    // ── What the message says ─────────────────────────────────────────────────

    /**
     * The mail is composed, addressed and recorded.
     *
     * The audit row is the assertion that anything happened at all: without it every claim below
     * would be about a message that was never built, and the flow deliberately reports nothing.
     */
    public function testTheMailIsComposedAndRecorded(): void
    {
        // Act
        $this->probe()->callCompose($this->recipient, 'a-raw-reset-token');
        $row = $this->recorded();

        // Assert
        $this->assertNotNull($row, 'no mail was composed, and the flow is silent by design');
        $this->assertSame($this->recipient, $row['tomail']);
        $this->assertNotSame('', trim((string) $row['subject']), 'a mail with no subject');
        $this->assertSame('auth', $row['module'], 'the mail is not attributable in the audit log');
    }

    /**
     * The link is in the body twice: once to click and once to read.
     *
     * Mail clients strip anchors, corporate gateways rewrite them, and a plain-text reader sees no
     * anchor at all — so the URL is printed as text where it can be copied into a browser. Either
     * one alone makes the mail useless for somebody, which is why both are asserted.
     */
    public function testTheLinkIsBothClickableAndReadable(): void
    {
        // Arrange
        $probe = $this->probe();
        $link  = $probe->callResetLink('a-raw-reset-token');

        // Act
        $probe->callCompose($this->recipient, 'a-raw-reset-token');
        $body = (string) ($this->recorded()['content'] ?? '');

        // Assert
        $escaped = htmlspecialchars($link, ENT_QUOTES);
        $this->assertStringContainsString('href="' . $escaped . '"', $body, 'nothing to click');
        $this->assertSame(
            2,
            substr_count($body, $escaped),
            'the URL appears once, so either the anchor or the readable copy is missing'
        );
    }

    /**
     * The token reaches the link intact and URL-encoded.
     *
     * The whole message is one URL, and a token that arrived mangled is a link that refuses a
     * person who did everything right — the failure that reads as "the reset does not work" and is
     * reported as such.
     */
    public function testTheTokenSurvivesIntoTheLink(): void
    {
        // Arrange — the shape a raw token has, plus a character that must be encoded
        $token = 'abc123+/=token';
        $probe = $this->probe();

        // Act
        $probe->callCompose($this->recipient, $token);
        $body = (string) ($this->recorded()['content'] ?? '');

        // Assert
        $this->assertStringContainsString('token=' . urlencode($token), $body);
        $this->assertStringNotContainsString('token=' . $token, $body, 'the token was not encoded');
    }

    /**
     * It says the link expires, and roughly when.
     *
     * Somebody opening the mail the next morning has to be told why the link no longer works, or
     * the conclusion is that the site is broken rather than that the link is old — and the useful
     * next step, asking for another, is the one they will not take.
     */
    public function testItSaysTheLinkExpires(): void
    {
        // Act
        $this->probe()->callCompose($this->recipient, 'a-raw-reset-token');
        $body = strtolower((string) ($this->recorded()['content'] ?? ''));

        // Assert
        $this->assertStringContainsString('valid for', $body, 'the mail does not say it expires');
        $this->assertStringContainsString('hour', $body);
    }

    /**
     * It tells somebody who did not ask that they can ignore it.
     *
     * This form can be submitted by anybody about any address, so a share of these messages reach
     * people who did nothing. Without that line the mail reads as a break-in notice, and the
     * support request it generates is the predictable one.
     */
    public function testItTellsAnUnexpectedRecipientToIgnoreIt(): void
    {
        // Act
        $this->probe()->callCompose($this->recipient, 'a-raw-reset-token');
        $body = strtolower((string) ($this->recorded()['content'] ?? ''));

        // Assert
        $this->assertStringContainsString('did not request', $body);
        $this->assertStringContainsString('ignore', $body);
    }

    /**
     * The brand name is escaped where it is interpolated.
     *
     * It comes from configuration, so nothing hostile reaches it today — and it is written into an
     * HTML body, which is where trusting a value stops being free. An apostrophe in a company name
     * is the ordinary case that makes this visible.
     */
    public function testTheBrandNameIsEscaped(): void
    {
        // Arrange
        $application = Application::currentInstance();
        $original = (array) $application->applicationInfo;
        $info = $original;
        $info['auth'] = is_array($info['auth'] ?? null) ? $info['auth'] : [];
        $info['auth']['brand'] = ['name' => "O'Brien & <b>Co</b>"];
        $application->applicationInfo = $info;

        try {
            // Act
            $this->probe()->callCompose($this->recipient, 'a-raw-reset-token');
            $body = (string) ($this->recorded()['content'] ?? '');

            // Assert
            $this->assertStringNotContainsString('<b>Co</b>', $body, 'the brand name broke out');

            if (str_contains($body, 'Brien')) {
                $this->assertStringContainsString('&amp;', $body, 'the ampersand was not escaped');
            }
        } finally {
            $application->applicationInfo = $original;
        }
    }

    /**
     * A mail that cannot be sent does not raise, and leaves the audit row behind.
     *
     * Deliberate, and the reason is the flow around it: an exception here becomes a 500, and a 500
     * on the forgot-password form tells whoever submitted it whether the address matched an
     * account — which is exactly what the identical-answer property elsewhere exists to hide. The
     * token is already stored, so the person can be sent another once mail works.
     *
     * Asserted by composing with no transport reachable, which is the normal state of this
     * environment: the status column may say either thing, and what must hold is that the call
     * returned and the attempt was recorded.
     */
    public function testAMailThatCannotBeSentDoesNotRaise(): void
    {
        // Arrange
        $original = Settings::getSetting('smtp');
        Settings::setSetting('smtp', ['host' => 'unroutable.invalid', 'port' => 1]);

        try {
            // Act & Assert — the assertion is that nothing is thrown
            $this->probe()->callCompose($this->recipient, 'a-raw-reset-token');

            $row = $this->recorded();
            $this->assertNotNull($row, 'a failed send left no trace for an operator to find');
            $this->assertContains((int) $row['status'], [0, 1]);
        } finally {
            Settings::setSetting('smtp', $original);
        }
    }
}
