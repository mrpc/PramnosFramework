<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\EmailsController;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Http\Request;

/**
 * The rows behind the email log — 39 of this controller's 43 uncovered statements, all in `data()`.
 *
 * A list screen reading a table an operator uses to answer "did that message go out?", and every
 * cell in it is built by hand from a database row. Four of those decisions are worth a test:
 *
 *   - **the address filter is quoted by the driver.** `Datasource::getList()` takes SQL rather
 *     than bindings, and the address arrives in a query string — building that fragment by
 *     concatenation is how a list screen becomes an injection point;
 *   - **and matched case-insensitively**, which is a claim about two engines: `=` is
 *     case-sensitive on PostgreSQL, so the same filter would match `Someone@example.com` on one
 *     and not the other, and a filter that silently matches nothing looks exactly like an account
 *     nothing was ever sent to;
 *   - **every cell a person typed is escaped.** A subject line is written by whoever composed the
 *     message and rendered into an admin page as HTML;
 *   - **the date is formatted.** The column is a Unix integer, and printed raw it is a number
 *     nobody reads — which is what this screen did.
 *
 * Both backends: {@see EmailListScreenPostgreSQLTest} re-runs it, and the case-insensitive match
 * is the reason.
 */
#[CoversClass(EmailsController::class)]
class EmailListScreenTest extends BaseTestCase
{
    private $db;

    private const MODULE = 'emaillist-probe';

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

        $this->clearProbe();

        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $this->clearProbe();

        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Request::resetInstance();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The address filter ────────────────────────────────────────────────────

    /**
     * The filter matches an address whatever its case.
     *
     * The claim that needs both engines. `=` is case-sensitive on PostgreSQL and not on a
     * default MySQL collation, so a filter written as `tomail = %s` would work in development
     * and quietly match nothing in production — and "nothing was sent to this person" is exactly
     * what an operator concludes from an empty list.
     */
    public function testTheAddressFilterIgnoresCase(): void
    {
        // Arrange
        $this->seedMail('Someone@Example.test', 'A message for somebody');

        // Act & Assert
        foreach (['someone@example.test', 'SOMEONE@EXAMPLE.TEST', 'Someone@Example.test'] as $typed) {
            $rows = $this->rowsFor($typed);

            $this->assertCount(
                1,
                $rows,
                'the filter missed the address when typed as ' . $typed
            );
        }
    }

    /**
     * A quote in the address does not reach the SQL as one.
     *
     * `Datasource::getList()` takes a `WHERE` fragment rather than bindings, so this value is
     * quoted by the driver on the way in. It comes off a query string, which makes the
     * alternative — concatenation — an injection point on an administration screen.
     *
     * Asserted by the absence of a raised query and the presence of an empty result: an injected
     * fragment either errors or returns rows it should not.
     */
    public function testAQuoteInTheAddressIsQuotedRatherThanConcatenated(): void
    {
        // Arrange
        $this->seedMail('ordinary@example.test', 'Should not be returned');

        // Act
        $rows = $this->rowsFor("' OR 1=1 --");

        // Assert
        $this->assertSame(
            [],
            $rows,
            'an injected filter returned rows, so the address is concatenated into the SQL'
        );
    }

    /**
     * The filter excludes everything else.
     *
     * Asserted from the exclusion rather than from an unfiltered count: `mails` is shared with the
     * rest of the suite and the list is paginated, so "how many rows are there in total" is not a
     * number this test can know — while "the other address is not in this answer" is exactly what
     * a filter means.
     */
    public function testTheFilterExcludesEveryOtherAddress(): void
    {
        // Arrange
        $this->seedMail('one@example.test', 'First');
        $this->seedMail('two@example.test', 'Second');

        // Act
        $rows = $this->rowsFor('one@example.test');

        // Assert
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('First', (string) $rows[0][2]);
        $this->assertStringNotContainsString(
            'Second',
            implode(' ', array_map('strval', $rows[0])),
            'the filter returned a row for another address'
        );
    }

    // ── The cells ─────────────────────────────────────────────────────────────

    /**
     * The id and the subject both open the message.
     *
     * A row whose only way in is a button in the last cell makes the rest of the row a target
     * people click at and nothing happens — and the subject is what a person actually aims for,
     * because it is what they recognise.
     */
    public function testTheIdAndTheSubjectBothOpenTheMessage(): void
    {
        // Arrange
        $this->seedMail('reader@example.test', 'Something recognisable');

        // Act
        $row = $this->rowFor('reader@example.test');

        // Assert — the same message, from either cell. The id is read out of the markup rather
        // than carried in from the insert: what matters is that the two links agree, not what the
        // number is.
        $this->assertMatchesRegularExpression(
            '~emails/show/(\d+)"[^>]*>\1</a>~',
            (string) $row[0],
            'the id cell is not a link to its own message'
        );

        preg_match('~emails/show/(\d+)~', (string) $row[0], $fromId);
        $this->assertStringContainsString(
            'emails/show/' . ($fromId[1] ?? 'x'),
            (string) $row[2],
            'the subject links somewhere other than the id does'
        );
        $this->assertStringContainsString('Something recognisable', (string) $row[2]);
    }

    /**
     * Anything a person typed is escaped.
     *
     * A subject is written by whoever composed the message — an operator, or an application
     * assembling one from user input — and it is rendered into an administration page as HTML.
     * The address and the module name are on the same footing.
     */
    public function testEveryCellAPersonTypedIsEscaped(): void
    {
        // Arrange
        $address = 'x"><script>alert(1)</script>@example.test';
        $this->seedMail($address, '<script>alert("subject")</script>');

        // Act
        $row = $this->rowFor($address);

        // Assert
        $rendered = implode(' ', array_map('strval', $row));
        $this->assertStringNotContainsString('<script>', $rendered, 'a script tag reached the page');
        $this->assertStringContainsString('&lt;script&gt;', $rendered, 'nothing was escaped');
    }

    /**
     * The date is a date, not the integer the column holds.
     *
     * `mails.date` is a Unix timestamp. Printed raw it is a ten-digit number in a column headed
     * "Date", which is what this screen showed — legible to nobody, and the one column an
     * operator scans when they are looking for what went out this morning.
     */
    public function testTheDateIsFormattedRatherThanPrintedRaw(): void
    {
        // Arrange — 14 February 2026, 09:30 UTC.
        $when = 1771061400;
        $this->seedMail('dated@example.test', 'Dated', 1, $when);

        // Act
        $row = $this->rowFor('dated@example.test');

        // Assert
        $this->assertSame(date('Y-m-d H:i', $when), (string) $row[4]);
        $this->assertStringNotContainsString((string) $when, (string) $row[4]);
    }

    /** A message with no date shows nothing rather than 1970. */
    public function testAMessageWithNoDateShowsNothing(): void
    {
        // Arrange
        $this->seedMail('undated@example.test', 'Undated', 2, 0);

        // Act
        $row = $this->rowFor('undated@example.test');

        // Assert
        $this->assertSame('', (string) $row[4], 'a message with no date was dated to 1970');
    }

    /**
     * Each status reads as a word, and only an unsent message offers a resend.
     *
     * Offering "send again" on a message that already went is how somebody sends it twice, and a
     * duplicate is the one thing a recipient definitely notices.
     */
    public function testTheStatusReadsAsAWordAndOnlyAnUnsentOneCanBeResent(): void
    {
        // Arrange
        $this->seedMail('sent@example.test', 'Sent', 1);
        $this->seedMail('queued@example.test', 'Queued', 2);
        $this->seedMail('pending@example.test', 'Pending', 0);

        // Act
        $sentRow    = $this->rowFor('sent@example.test');
        $queuedRow  = $this->rowFor('queued@example.test');
        $pendingRow = $this->rowFor('pending@example.test');

        // Assert — the words.
        $this->assertStringContainsString('Sent', (string) $sentRow[5]);
        $this->assertStringContainsString('Queued', (string) $queuedRow[5]);
        $this->assertStringContainsString('Pending', (string) $pendingRow[5]);

        // …and the actions. The id comes out of the row's own link, so the assertion is that the
        // resend points at *this* message rather than that it matches a number the test carried in.
        $actions = static fn (array $row): string => (string) $row[count($row) - 1];
        $idOf    = static function (array $row): string {
            preg_match('~emails/show/(\d+)~', (string) $row[0], $m);

            return $m[1] ?? 'x';
        };

        $this->assertStringNotContainsString(
            'emails/resend/',
            $actions($sentRow),
            'a message that already went out offers to go again'
        );
        $this->assertStringContainsString(
            'emails/resend/' . $idOf($queuedRow),
            $actions($queuedRow)
        );
        $this->assertStringContainsString(
            'emails/resend/' . $idOf($pendingRow),
            $actions($pendingRow)
        );
    }

    // ── The gate ──────────────────────────────────────────────────────────────

    /** Below the floor there is no list at all. */
    public function testBelowTheFloorThereIsNoList(): void
    {
        // Arrange
        $this->seedMail('gated@example.test', 'Gated');
        $controller = $this->controller(refused: true);

        // Act
        $answer = $controller->data();

        // Assert
        $this->assertNull($answer, 'the list was served below the usertype floor');
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** The controller with the usertype floor under the test's control. */
    private function controller(bool $refused = false): object
    {
        return new class ($refused, $this->db) extends EmailsController {
            public function __construct(private bool $refused, \Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            protected function requireMinUserType($type): bool
            {
                return $this->refused;
            }
        };
    }

    /**
     * The decoded rows the list returns for a `tomail` filter.
     *
     * Through the action rather than by querying, because the cells are what is under test and
     * they are built in the loop the action runs.
     *
     * @return list<array<int, mixed>>
     */
    private function rowsFor(string $address): array
    {
        $_GET = $address === '' ? [] : ['tomail' => $address];
        Request::resetInstance();

        $answer  = $this->controller()->data();
        $decoded = json_decode((string) $answer->getBody(), true);

        $key  = array_key_exists('data', (array) $decoded) ? 'data' : 'aaData';
        $rows = (array) (($decoded[$key]) ?? []);

        // Only this test's own rows: the table is shared with everything else in the suite.
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => str_contains((string) ($row[3] ?? ''), self::MODULE)
        ));
    }

    /**
     * The one row for a seeded address.
     *
     * By address rather than by scanning an unfiltered list: `mails` is shared with the rest of
     * the suite and the list is paginated, so "the first page of everything" is not a place a
     * seeded row can be relied on to appear. Each test seeds its own address for that reason.
     *
     * @return array<int, mixed>
     */
    private function rowFor(string $address): array
    {
        $rows = $this->rowsFor($address);

        $this->assertCount(1, $rows, 'the seeded message is not in the list for ' . $address);

        return $rows[0];
    }

    /**
     * One mail row.
     *
     * Returns nothing: `getInsertId()` disagreed with the row that came back — auto-increment
     * gaps from the deletes this fixture does, most likely — and every assertion here is better
     * off reading the id out of the markup it is checking anyway. What the row *is* matters; what
     * number it got does not.
     */
    private function seedMail(
        string $to,
        string $subject,
        int $status = 1,
        ?int $when = null
    ): void {
        // Every NOT NULL column named: on PostgreSQL an omitted one with no default is a
        // violation rather than an empty string.
        $this->db->queryBuilder()->table('#PREFIX#mails')->insert([
            'status'     => $status,
            'frommail'   => 'no-reply@example.test',
            'fromname'   => 'Probe',
            'tomail'     => $to,
            'toname'     => 'Probe',
            'subject'    => $subject,
            'content'    => 'Body',
            'date'       => $when ?? time(),
            'module'     => self::MODULE,
            'moduleinfo' => '',
            'extrainfo'  => '',
            'path'       => '',
            'hash'       => md5($to . $subject . random_int(1, PHP_INT_MAX)),
        ]);

    }

    private function clearProbe(): void
    {
        try {
            $this->db->queryBuilder()->table('#PREFIX#mails')
                ->where('module', self::MODULE)->delete();
        } catch (\Throwable $exception) {
            // No table on a lane mid-migration; nothing to clear.
        }

    }
}
