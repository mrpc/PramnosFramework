<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\EmailsController;
use Pramnos\User\User;

class TestableEmailsController extends EmailsController
{
    public array $redirectedTo = [];

    /** The `WHERE` fragment the list is scoped with, for the test that asserts its shape. */
    public function exposeAddressCondition(): string
    {
        return $this->addressCondition();
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        if ($url === null) {
            $url = 'default_redirect';
        }
        $this->redirectedTo[] = $url;
        throw new \RuntimeException('redirect_quit');
    }


    /** The view handed out last, to read what the action gave it. */
    public ?object $lastView = null;

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new class {
            public mixed $mails;
            public mixed $mail;
            public mixed $total;
            public mixed $page;
            /** The list is a DataTable now; the real View takes this through `__set`. */
            public mixed $datatable;
            public mixed $success;
            public mixed $error;
            /** The address the list is scoped to, and the way back to all of it. */
            public mixed $scopedTo;
            public mixed $clearUrl;
            /** The message as the preview frame shows it: no pixel, links in a window of their own. */
            public mixed $preview;
            /** Everything knowable about one sent message, for the detail screen. */
            public mixed $report;
            /** Whether mail still goes to the recipient, and the form that changes it. */
            public mixed $bounce;
            /** The message's status, as the same badge the list draws. */
            public mixed $statusBadge;
            /** Where "← Back" goes. */
            public mixed $backUrl;

            public function display($view = '') {
                return 'mock html view for ' . $view;
            }
        };
        $this->lastView = $view;
        return $view;
    }
}

/**
 * A variant of the testable controller where redirect() records the URL but
 * does NOT throw. This allows execution to continue past the redirect call
 * so that the guard-clause `return null` / `return true` lines immediately
 * following it can be covered (lines 46, 80, 86, 97, 117, 123, 150).
 * Those lines are dead code in production (redirect() calls exit), but
 * they still appear as coverable statements for Xdebug/PHPUnit coverage.
 */
class TestableEmailsControllerSoft extends TestableEmailsController
{
    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = $url ?? 'default_redirect';
        // Intentionally does NOT throw — allows post-redirect returns to execute
    }
}

class EmailsControllerTest extends BaseTestCase
{
    private TestableEmailsController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        \Pramnos\Application\Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $singleton = null;

        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        /*
         * The mails table, from its migration.
         *
         * It was hand-rolled here with `CREATE TABLE IF NOT EXISTS`, and the shape disagreed with
         * the framework's: `date datetime NOT NULL`, where the shipped column is an **integer**
         * holding a Unix timestamp. Two consequences, and the second is the reason this is worth
         * a comment rather than a quiet edit.
         *
         * Whichever test touched the table first decided its shape for the whole run, so a class
         * that built it from the migration made the inserts below fail with «Data truncated for
         * column 'date'» — thirteen errors in a file that had not changed.
         *
         * And this class was asserting against a schema the framework does not have.
         * `EmailsController::data()` reads that column as `(int) $row[4]`, so a `datetime` of
         * `2023-01-01 10:00:00` becomes the integer `2023` — a date in January 1970. The test
         * passed because nothing looked at the rendered date.
         *
         * Dropped first, because the stale shape has to go for the migration to run at all.
         */
        $db->query('DROP TABLE IF EXISTS ' . $db->schema()->quoteTable('#PREFIX#mails'));
        $this->runMigrations(
            [
                \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
                \Pramnos\Framework\Migrations\Messaging\CreateBouncesTable::class,
            ],
            $db
        );
        \Pramnos\Email\Bounces::forget();
        \Pramnos\Email\Bounces::clear('test@test.com');

        // Insert mock data. `date` is a Unix timestamp, and every NOT NULL column is named.
        $db->query('DELETE FROM ' . $db->schema()->quoteTable('#PREFIX#mails'));

        foreach ([[1, 1, 'test@test.com', 'Test', 'Subject 1'], [2, 0, 'fail@test.com', 'Fail', 'Subject 2']] as $row) {
            [$id, $status, $to, $toName, $subject] = $row;

            $db->queryBuilder()->table('#PREFIX#mails')->insert([
                'id'         => $id,
                'status'     => $status,
                'frommail'   => 'no-reply@test.com',
                'fromname'   => 'Test',
                'tomail'     => $to,
                'toname'     => $toName,
                'subject'    => $subject,
                'content'    => 'Body',
                'date'       => 1672567200 + $id,
                'module'     => 'system',
                'moduleinfo' => '',
                'extrainfo'  => '',
                'path'       => '',
                'hash'       => md5((string) $id),
            ]);
        }

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestableEmailsController($app);

        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        // Unset regardless of type — any leftover themeObject would cause
        // Document::render() to call loadTheme() on an incomplete mock in
        // later tests (e.g. TestClientTest), making those tests fail.
        if (isset($doc->themeObject)) {
            unset($doc->themeObject);
        }
        $_SESSION = [];
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    private function setMockUser(int $usertype): void
    {
        $_SESSION['logged'] = true;
        $_SESSION['login'] = true;
        $_SESSION['userid'] = 2;
        $_SESSION['uid'] = 2;
        $_SESSION['usertype'] = $usertype;
        $_SESSION['sessionid'] = 'dummy_session_id';

        $user = new User(0);
        $user->userid = 2;
        $user->usertype = $usertype;
        
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $user->language = $lang ? $lang->currentlang() : 'en';

        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = $user;
        }
    }

    public function testRequireMinUserTypeRedirectsWhenBelowRequired(): void
    {
        $this->setMockUser(97); // Required is the default floor, 98

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testRequireMinUserTypeRedirectsWhenNullUser(): void
    {
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_SESSION = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testDisplayShowsEmails(): void
    {
        $this->setMockUser(98);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('Email History', $doc->title);
    }

    /**
     * Opened from an account, the screen filters to that account's address.
     *
     * And the filter has to reach the **datatable's own source**, not only the page: the
     * table fetches its rows itself, so a filter that lives in the page's query string alone
     * vanishes on the first sort or page change — and the operator is then reading
     * everybody's mail believing it is one person's.
     */
    public function testTheListCanBeScopedToOneAddress(): void
    {
        // Arrange
        $this->setMockUser(98);
        $_GET['tomail'] = 'Someone@Example.COM';

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        try {
            // Act
            ob_start();
            try {
                $this->controller->display();
            } finally {
                ob_get_clean();
            }

            // Assert — the condition is quoted by the driver rather than concatenated, and
            // it is case-insensitive, because `=` is case-sensitive on PostgreSQL and a
            // filter matching nothing looks exactly like an account nothing was sent to.
            $condition = $this->controller->exposeAddressCondition();
            $this->assertStringContainsString('LOWER(tomail)', $condition);
            $this->assertStringContainsString('someone@example.com', strtolower($condition));

            // …and a hostile address comes back as a value rather than as SQL. The filter
            // arrives in a query string, so this is the assertion that matters: the fragment
            // goes to `Datasource::getList()` as text, and building it by concatenation is
            // how a list screen becomes an injection point.
            $_GET['tomail'] = "x' OR '1'='1";
            $hostile = $this->controller->exposeAddressCondition();
            $this->assertStringNotContainsString("OR '1'='1'", $hostile);
            $this->assertMatchesRegularExpression("/LOWER\\('.*'\\)/", $hostile);
        } finally {
            unset($_GET['tomail']);
        }
    }

    public function testShowDisplaysEmailPreview(): void
    {
        $this->setMockUser(98);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $_GET['_option'] = 1;
            $output = $this->controller->show(1);
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertStringContainsString('Email Preview — Subject 1', $doc->title);
    }

    /**
     * A message whose body an application kept in a file — `content` empty, `path` naming the
     * gzipped body relative to the root — is shown with that body, not as "No body content.".
     */
    public function testShowReadsABodyKeptInAFile(): void
    {
        // Arrange
        $relative = '/var/pf-emailshow-' . bin2hex(random_bytes(3)) . '.html.gz';
        file_put_contents(ROOT . $relative, gzencode('<p>Kept on disk</p>'));
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()->table('#PREFIX#mails')->insert([
            'id' => 3, 'status' => 1, 'frommail' => 'no-reply@test.com', 'fromname' => 'Test', 'tomail' => 'kept@test.com',
            'toname' => 'Kept', 'subject' => 'Subject 3', 'content' => '', 'date' => 1672567203, 'module' => 'system',
            'moduleinfo' => '', 'extrainfo' => '', 'path' => $relative, 'hash' => md5('<p>Kept on disk</p>'),
        ]);
        $this->setMockUser(98);
        \Pramnos\Framework\Factory::getDocument()->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        // Act
        ob_start();
        try {
            $_GET['_option'] = 3;
            $output = (string) $this->controller->show(3);
        } finally {
            $output .= (string) ob_get_clean();
            @unlink(ROOT . $relative);
        }

        // Assert
        $this->assertSame('mock html view for show', $output);
        $this->assertSame('<p>Kept on disk</p>', $this->controller->lastView->mail['content'], 'the view is handed the body');
        $this->assertSame('<p>Kept on disk</p>', $this->controller->lastView->report->body(), 'and so is the report');
    }

    /**
     * The message's page says whether mail still goes to its recipient, and carries the form that
     * blocks it — posted with the session's token to this message's bounce action.
     */
    public function testShowSaysWhetherMailGoesToTheRecipient(): void
    {
        // Arrange
        $this->setMockUser(98);
        \Pramnos\Framework\Factory::getDocument()->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        // Act
        ob_start();
        try {
            $_GET['_option'] = 1;
            $this->controller->show(1);
        } finally {
            ob_end_clean();
        }
        $bounce = $this->controller->lastView->bounce;

        // Assert
        $this->assertIsArray($bounce);
        $this->assertNull($bounce['refusal'], 'nothing recorded: mail goes');
        $this->assertStringEndsWith('emails/bounce/1', $bounce['action']);
        $this->assertSame(\Pramnos\Http\Session::getInstance()->getCsrfToken(), $bounce['csrf']);
    }

    /**
     * Posted from the message's page, `block` stops all mail to its recipient with the reason
     * given, and `clear` lets it go again; each says so and returns to the message.
     */
    public function testTheRecipientIsBlockedAndCleared(): void
    {
        // Arrange
        $this->setMockUser(98);
        $soft = new TestableEmailsControllerSoft(Application::getInstance());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['_option']           = 1;
        $_POST                     = ['_csrf_token' => \Pramnos\Http\Session::getInstance()->getCsrfToken(), 'op' => 'block', 'reason' => 'asked by phone'];

        // Act
        $soft->bounce(1);
        $blocked = \Pramnos\Email\Bounces::refusal('test@test.com');
        $_POST['op'] = 'clear';
        $soft->bounce(1);
        $cleared = \Pramnos\Email\Bounces::status('test@test.com');
        $_POST['op'] = 'unknown';
        $soft->bounce(1);

        // Assert
        $this->assertStringContainsString('asked by phone', (string) $blocked);
        $this->assertNull($cleared);
        $this->assertContains('No mail goes to test@test.com now.', $_SESSION['_messages'] ?? []);
        $this->assertContains('Mail goes to test@test.com again.', $_SESSION['_messages'] ?? []);
        $this->assertContains('Nothing was changed for test@test.com.', $_SESSION['_errors'] ?? [], 'an unknown op changes nothing');
        $this->assertCount(3, array_filter($soft->redirectedTo, static fn (string $url): bool => str_ends_with($url, 'emails/show/1')));
    }

    /**
     * Without the session's token, or by GET, nothing is changed; a message that is not there
     * returns to the list.
     */
    public function testABounceChangeNeedsTheTokenAndTheMessage(): void
    {
        // Arrange
        $this->setMockUser(98);
        $soft = new TestableEmailsControllerSoft(Application::getInstance());
        $_GET['_option'] = 1;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf_token' => 'forged', 'op' => 'block'];

        // Act
        $soft->bounce(1);
        $_POST['_csrf_token'] = \Pramnos\Http\Session::getInstance()->getCsrfToken();
        $_GET['_option']      = 999;
        $soft->bounce(999);

        // Assert
        $this->assertNull(\Pramnos\Email\Bounces::status('test@test.com'), 'a forged form blocks nobody');
        $this->assertContains('That form had expired. Please try again.', $_SESSION['_errors'] ?? []);
        $this->assertContains('That record no longer exists.', $_SESSION['_errors'] ?? []);
        $this->assertSame(adminUrl('emails'), end($soft->redirectedTo), 'back to the list');
    }

    /**
     * Below the administration floor the action changes nothing; with no message id it returns
     * to the list; and a page shown where there is no bounces table offers no form.
     */
    public function testTheBounceActionsEdges(): void
    {
        // Arrange
        $soft = new TestableEmailsControllerSoft(Application::getInstance());
        $this->setMockUser(1);

        // Act — not an administrator
        $soft->bounce(1);
        $refused = $soft->redirectedTo;

        // Act — no id
        $this->setMockUser(98);
        $soft->redirectedTo = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf_token' => \Pramnos\Http\Session::getInstance()->getCsrfToken(), 'op' => 'block'];
        $soft->bounce(0);
        $noId = $soft->redirectedTo;

        // Act — no table
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->query('DROP TABLE ' . $db->schema()->quoteTable('#PREFIX#bounces'));
        \Pramnos\Email\Bounces::forget();
        \Pramnos\Framework\Factory::getDocument()->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        ob_start();
        try {
            $_GET['_option'] = 1;
            $this->controller->show(1);
        } finally {
            ob_end_clean();
            $this->runMigrations([\Pramnos\Framework\Migrations\Messaging\CreateBouncesTable::class], $db);
            \Pramnos\Email\Bounces::forget();
        }

        // Assert
        $this->assertNotEmpty($refused, 'sent away');
        $this->assertNull(\Pramnos\Email\Bounces::status('test@test.com'), 'and nobody blocked');
        $this->assertSame([adminUrl('emails')], $noId);
        $this->assertNull($this->controller->lastView->bounce, 'no table, no form');
    }

    public function testShowRedirectsWhenInvalidId(): void
    {
        $this->setMockUser(98);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->show(0);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            // The message, not a query parameter: `?error=…` was in the URL and nothing read it.
            $this->assertContains(
                'The id in that link is not valid.',
                $_SESSION['_errors'] ?? []
            );
        }
    }

    public function testShowRedirectsWhenNotFound(): void
    {
        $this->setMockUser(98);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $_GET['_option'] = 999;
            $this->controller->show(999);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            // The message, not a query parameter: `?error=…` was in the URL and nothing read it.
            $this->assertContains(
                'That record no longer exists.',
                $_SESSION['_errors'] ?? []
            );
        }
    }

    /**
     * The status filter's options are the controller's `statuses()`: an application that adds
     * a status of its own sees it in the dropdown, under its own word, beside the framework's —
     * and a column from `extraColumns()` gets a header in its place.
     */
    public function testTheStatusFilterOffersTheStatusesTheControllerNames(): void
    {
        // Arrange
        $this->setMockUser(98);
        $controller = new class (Application::getInstance()) extends TestableEmailsController {
            /** One status of the application's own. */
            protected function statuses(): array
            {
                return parent::statuses() + [3 => 'Canceled'];
            }

            /** A column of the application's own, which gets a header of its own. */
            protected function extraColumns(): array
            {
                return ['Sender' => 'frommail'];
            }
        };

        // Act
        $controller->display();
        $columns = $controller->lastView->datatable->aoColumns;
        $filter  = (string) $columns['Status']->footer;

        // Assert — the framework's three, with 0 as Failed rather than "Pending", and the fourth.
        $this->assertStringContainsString('Any status', $filter);
        $this->assertMatchesRegularExpression('~value="0"[^>]*>Failed<~', $filter);
        $this->assertMatchesRegularExpression('~value="3"[^>]*>Canceled<~', $filter);
        $this->assertStringNotContainsString('Pending', $filter);
        // The application's column sits after Status and before Opens.
        $this->assertSame(['ID', 'Recipient', 'Subject', 'Module', 'Date', 'Status', 'Sender', 'Opens', 'Actions'], array_keys($columns));
    }

    /**
     * The detail screen draws the list's badge: a failed message reads as failed, with the
     * reason in its title, rather than a hard-coded "Pending".
     */
    public function testShowGivesTheViewTheStatusBadge(): void
    {
        // Arrange
        $this->setMockUser(98);
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#mails')
            ->where('id', 2)->update(['extrainfo' => 'Mailbox unavailable']);
        $_GET['_option'] = 2;

        // Act
        $this->controller->show(2);
        $badge = (string) $this->controller->lastView->statusBadge;

        // Assert
        $this->assertStringContainsString('>Failed</span>', $badge);
        $this->assertStringContainsString('title="Mailbox unavailable"', $badge);
    }

    public function testResendUpdatesStatus(): void
    {
        $this->setMockUser(98);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $_GET['_option'] = 2;
            $this->controller->resend(2); // failed email
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            // The message, not a query parameter: `?message=…` was in the URL and nothing read it.
            $this->assertContains(
                'Queued again.',
                $_SESSION['_messages'] ?? []
            );

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('mails')->where('id', 2)->first();
            $this->assertEquals(2, $result->fields['status']);
        }
    }

    public function testResendRedirectsWhenInvalidId(): void
    {
        $this->setMockUser(98);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->resend(0);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            // The message, not a query parameter: `?error=…` was in the URL and nothing read it.
            $this->assertContains(
                'The id in that link is not valid.',
                $_SESSION['_errors'] ?? []
            );
        }
    }

    /**
     * display() with a status GET parameter must add a WHERE clause to the query.
     *
     * The filterStatus branch (lines 59-62 in EmailsController) adds a WHERE
     * condition only when $_GET['status'] is present.  Without this test the
     * branch stays uncovered and a future regression (e.g. filter silently
     * ignored) would go undetected.  We set status=1 (sent) and verify the page
     * renders without error — the actual SQL filtering is an integration concern
     * covered by the query-builder tests.
     */
    public function testDisplayWithStatusFilterRendersPage(): void
    {
        // Arrange — admin user, status filter set in GET
        $this->setMockUser(98);
        $_GET['status'] = '1'; // filter for sent emails

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        // Act — display() reads $_GET['status'] and adds WHERE status=1
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }

        if (empty($output)) {
            $output = $obOutput;
        }

        // Assert — page rendered without redirect; filter branch was executed
        $this->assertNotEmpty($output,
            'display() must render the emails view even when a status filter is applied');
        $this->assertEmpty($this->controller->redirectedTo,
            'display() must not redirect when a valid status filter is set');
        $this->assertSame('Email History', $doc->title,
            'display() must set the correct page title when filtered');
    }

    /**
     * display() / show() / resend() must return null/true immediately after
     * the redirect() call when the user lacks permission (lines 46, 80, 117, 150).
     * Uses TestableEmailsControllerSoft so that redirect() does not throw,
     * allowing execution to reach the guard-clause returns that follow it.
     * In production redirect() calls exit(), making these returns unreachable —
     * but they are counted as coverable statements by Xdebug.
     */
    public function testGuardClauseReturnsCoveredViaDisplayWithNoThrowRedirect(): void
    {
        // Arrange — no current user → requireMinUserType fires redirect → return true (150)
        //           → display() sees true → return null (46)
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_SESSION = [];

        $soft = new TestableEmailsControllerSoft($app);

        // Act — display() enters requireMinUserType, redirect is recorded (no throw),
        // return true (150) executes, then return null (46) executes
        $result = $soft->display();

        // Assert — redirect was issued and display() returned null
        $this->assertNull($result,
            'display() must return null after the guard-clause redirect fires');
        $this->assertNotEmpty($soft->redirectedTo,
            'display() must issue a redirect when user has no permission');
    }

    /**
     * show() must return null immediately after redirect when the user lacks
     * permission (lines 80, 150). Uses the soft-redirect mock for the same
     * reason as testGuardClauseReturnsCoveredViaDisplayWithNoThrowRedirect.
     */
    public function testGuardClauseReturnsCoveredViaShowWithNoThrowRedirect(): void
    {
        // Arrange — no current user
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_SESSION = [];

        $soft = new TestableEmailsControllerSoft($app);

        // Act
        $_GET['_option'] = 1;
        $result = $soft->show(1);

        // Assert — guard-clause return null (80) was reached after redirect (150)
        $this->assertNull($result,
            'show() must return null after the guard-clause redirect fires');
        $this->assertNotEmpty($soft->redirectedTo,
            'show() must issue a redirect when user has no permission');
    }

    /**
     * resend() must return (void, line 117) and requireMinUserType must return
     * true (line 150) when the user lacks permission. Uses soft-redirect mock.
     */
    public function testGuardClauseReturnsCoveredViaResendWithNoThrowRedirect(): void
    {
        // Arrange — no current user
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_SESSION = [];

        $soft = new TestableEmailsControllerSoft($app);

        // Act — resend() must reach its guard-clause return (117) after redirect fires
        $_GET['_option'] = 1;
        $soft->resend(1);

        // Assert — redirect was issued (no exception); the return at line 117 was reached
        $this->assertNotEmpty($soft->redirectedTo,
            'resend() must issue a redirect when user has no permission');
    }
}
