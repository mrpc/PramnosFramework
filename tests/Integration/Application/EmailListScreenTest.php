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
     * Status 0 is a message that **failed** — `Mail::STATUS_FAILED` — and it read "Pending",
     * which tells an operator to wait for something that will not happen. Offering "send again"
     * on a message that already went is how somebody sends it twice, and a duplicate is the one
     * thing a recipient definitely notices. The resend is a form, not a link: it changes data.
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
        $this->assertStringContainsString('>Sent<', (string) $sentRow[5]);
        $this->assertStringContainsString('>Queued<', (string) $queuedRow[5]);
        $this->assertStringContainsString('>Failed<', (string) $pendingRow[5], 'a failed message reads as one');
        $this->assertStringNotContainsString('Pending', (string) $pendingRow[5]);

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
        // A POST form with the session's token: a link anybody can make an administrator follow.
        $this->assertMatchesRegularExpression('~<form[^>]*method="post"[^>]*action="[^"]*emails/resend/' . $idOf($pendingRow) . '"~i', $actions($pendingRow));
    }

    /**
     * A failed message carries why in its badge's title, from whatever wrote `extrainfo` last:
     * the reason as text, the outbox's JSON (its `last_error`), or an application's serialized
     * array (its `error`). The reason is the first thing an operator needs and was nowhere on
     * the list. A sent message's `extrainfo` is not an error and is not shown as one.
     */
    public function testAFailedMessageCarriesItsErrorInTheBadgesTitle(): void
    {
        // Arrange
        $this->seedMail('text@example.test', 'Text', 0, null, '550 <mailbox> unknown');
        $this->seedMail('json@example.test', 'Json', 0, null, '{"outbox":{"attempts":3},"last_error":"Connection refused"}');
        $this->seedMail('php@example.test', 'Php', 0, null, serialize(['error' => 'Quota exceeded']));
        $this->seedMail('went@example.test', 'Went', 1, null, 'relayed by mx1');

        // Act
        $text = (string) $this->rowFor('text@example.test')[5];
        $json = (string) $this->rowFor('json@example.test')[5];
        $php  = (string) $this->rowFor('php@example.test')[5];
        $went = (string) $this->rowFor('went@example.test')[5];

        // Assert — escaped, since the reason comes from a remote server.
        $this->assertStringContainsString('title="550 &lt;mailbox&gt; unknown"', $text);
        $this->assertStringContainsString('title="Connection refused"', $json);
        $this->assertStringContainsString('title="Quota exceeded"', $php);
        $this->assertStringNotContainsString('title=', $went, 'a sent message showed an error');
    }

    /**
     * An application's own statuses and badge are the list's: `statuses()` names a status the
     * framework does not have, and `statusBadge()` redraws one it does.
     */
    public function testAnApplicationsStatusesAndBadgeAreTheLists(): void
    {
        // Arrange
        $this->seedMail('canceled@example.test', 'Canceled', 3);
        $this->seedMail('drawn@example.test', 'Drawn', 1);
        $controller = $this->hooked();

        // Act
        $canceled = $this->rowFor('canceled@example.test', $controller);
        $drawn    = $this->rowFor('drawn@example.test', $controller);

        // Assert
        $this->assertStringContainsString('>Canceled<', (string) $canceled[5], 'the application\'s status has no word');
        $this->assertSame('<b>delivered</b>', (string) $drawn[5], 'the override did not draw the cell');
    }

    /**
     * `extraColumns()` adds the application's columns after Status, escaped, and a column's
     * filter searches it. A name that is not a column name is dropped, because the list builds
     * its query from them.
     */
    public function testAnApplicationsColumnsAreShownEscapedAndSearched(): void
    {
        // Arrange
        $this->seedMail('cols@example.test', 'Columns', 1, null, '', '<b>boss</b>@example.test');
        $this->seedMail('cols2@example.test', 'Other', 1, null, '', 'quiet@example.test');
        $controller = $this->hooked();

        // Act — the row, then a search on the new column alone.
        $row = $this->rowFor('cols@example.test', $controller);
        $_POST = ['sEcho' => 1, 'iDisplayStart' => 0, 'iDisplayLength' => 10, 'bSearchable_6' => 'true', 'sSearch_6' => '<b>boss</b>@example.test'];
        $found = $this->rowsFor('', $controller);

        // Assert — id … status, the one valid column, opens, actions.
        $this->assertCount(9, $row, 'the column with an invalid name was not dropped');
        $this->assertSame('&lt;b&gt;boss&lt;/b&gt;@example.test', (string) $row[6]);
        $this->assertCount(1, $found, 'the column\'s filter did not narrow the list');
        $this->assertStringContainsString('Columns', (string) $found[0][2]);
    }

    /**
     * A message that never went is queued again in place, and dated now — so the outbox's
     * deadline counts from the resend, not from a first attempt days ago, which would expire it
     * again before it was tried.
     */
    public function testAnUnsentMessageIsQueuedAgainInPlaceAndRedated(): void
    {
        // Arrange
        $this->seedMail('again@example.test', 'Again', 0, 1000000000);
        $id = $this->idOf('again@example.test');
        $controller = $this->controller();
        $_GET['_option'] = $id;
        $before = time();

        // Act
        $controller->resend();

        // Assert
        $rows = $this->stored('again@example.test');
        $this->assertCount(1, $rows, 'a copy was made of a message that never went');
        $this->assertSame(2, (int) $rows[0]['status']);
        $this->assertGreaterThanOrEqual($before, (int) $rows[0]['date'], 'the deadline still counts from the first attempt');
        $this->assertStringEndsWith('emails/show/' . $id, (string) $controller->redirectedTo);
    }

    /**
     * A sent message, where the application allows it, is queued as a **new** row with the same
     * body, names and outbox settings and none of its retries — and the first stays sent: the
     * history has to say it went twice.
     */
    public function testASentMessageTheApplicationAllowsIsQueuedAsACopy(): void
    {
        // Arrange
        $this->seedMail('twice@example.test', 'Twice', 1, 1000000000, '{"outbox":{"cc":["c@example.test"]},"last_error":"x"}');
        $id = $this->idOf('twice@example.test');
        $controller = $this->hooked();
        $_GET['_option'] = $id;

        // Act
        $controller->resend();

        // Assert
        $rows = $this->stored('twice@example.test');
        $this->assertCount(2, $rows);
        [$first, $copy] = $rows;
        $this->assertSame(1, (int) $first['status'], 'the record of the first sending changed');
        $this->assertSame(1000000000, (int) $first['date']);
        $this->assertSame(2, (int) $copy['status']);
        $this->assertSame('Body', (string) $copy['content']);
        $this->assertSame('Probe', (string) $copy['fromname']);
        $this->assertSame('Twice', (string) $copy['subject']);
        $this->assertSame(['outbox' => ['cc' => ['c@example.test']]], json_decode((string) $copy['extrainfo'], true));
        $this->assertStringEndsWith('emails/show/' . $copy['id'], (string) $controller->redirectedTo);
    }

    /**
     * Where `mayResend()` says no — a sent message, by default — nothing is queued, whatever
     * the request: the button is not drawn, and a hand-made request is refused the same way.
     */
    public function testAMessageThatMayNotBeResentIsLeftAlone(): void
    {
        // Arrange
        $this->seedMail('once@example.test', 'Once', 1, 1000000000);
        $id = $this->idOf('once@example.test');
        $controller = $this->controller();
        $_GET['_option'] = $id;

        // Act
        $controller->resend();

        // Assert
        $rows = $this->stored('once@example.test');
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['status']);
        $this->assertSame(1000000000, (int) $rows[0]['date']);
        $this->assertContains('This email is not sent again.', $_SESSION['_errors'] ?? []);
    }

    /** A resend of a row that is gone says so, and changes nothing. */
    public function testAResendOfAMissingRowSaysSo(): void
    {
        // Arrange
        $controller = $this->controller();
        $_GET['_option'] = 2147483000;

        // Act
        $controller->resend();

        // Assert
        $this->assertContains('That record no longer exists.', $_SESSION['_errors'] ?? []);
        $this->assertStringEndsWith('emails', (string) $controller->redirectedTo);
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

    /** The controller with the usertype floor under the test's control, and its redirect kept. */
    private function controller(bool $refused = false): object
    {
        return new class ($refused, $this->db) extends EmailsController {
            /** Where the action last sent the browser. */
            public ?string $redirectedTo = null;

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

            /** Kept rather than followed: a test process does not end. */
            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo = (string) $url;
            }
        };
    }

    /** The controller as an application extends it: every hook this screen has, overridden. */
    private function hooked(): object
    {
        return new class ($this->db) extends EmailsController {
            /** Where the action last sent the browser. */
            public ?string $redirectedTo = null;

            public function __construct(\Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            protected function requireMinUserType($type): bool
            {
                return false;
            }

            /** Kept rather than followed: a test process does not end. */
            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo = (string) $url;
            }

            /** A status of the application's own. */
            protected function statuses(): array
            {
                return parent::statuses() + [3 => 'Canceled'];
            }

            /** A sent message drawn the application's way; the rest as the framework draws them. */
            protected function statusBadge(int $status, array $row): string
            {
                return $status === 1 ? '<b>delivered</b>' : parent::statusBadge($status, $row);
            }

            /** One real column, and one name that is not a column and has to be dropped. */
            protected function extraColumns(): array
            {
                return ['From' => 'frommail', 'Hostile' => 'frommail; DROP TABLE mails'];
            }

            /** Everything may go again — "it never arrived". */
            protected function mayResend(array $row): bool
            {
                return true;
            }
        };
    }

    /** The id of the one seeded row for an address. */
    private function idOf(string $address): int
    {
        return (int) $this->stored($address)[0]['id'];
    }

    /**
     * The rows stored for an address, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function stored(string $address): array
    {
        return array_values((array) $this->db->queryBuilder()->table('#PREFIX#mails')
            ->where('tomail', $address)->orderBy('id')->get()->fetchAll());
    }

    /**
     * The decoded rows the list returns for a `tomail` filter.
     *
     * Through the action rather than by querying, because the cells are what is under test and
     * they are built in the loop the action runs.
     *
     * @return list<array<int, mixed>>
     */
    private function rowsFor(string $address, ?object $controller = null): array
    {
        $_GET = $address === '' ? [] : ['tomail' => $address];
        Request::resetInstance();

        $answer  = ($controller ?? $this->controller())->data();
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
    private function rowFor(string $address, ?object $controller = null): array
    {
        $rows = $this->rowsFor($address, $controller);

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
        ?int $when = null,
        string $extrainfo = '',
        string $from = 'no-reply@example.test'
    ): void {
        // Every NOT NULL column named: on PostgreSQL an omitted one with no default is a
        // violation rather than an empty string.
        $this->db->queryBuilder()->table('#PREFIX#mails')->insert([
            'status'     => $status,
            'frommail'   => $from,
            'fromname'   => 'Probe',
            'tomail'     => $to,
            'toname'     => 'Probe',
            'subject'    => $subject,
            'content'    => 'Body',
            'date'       => $when ?? time(),
            'module'     => self::MODULE,
            'moduleinfo' => '',
            'extrainfo'  => $extrainfo,
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
