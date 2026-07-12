<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Queue\Controllers\QueueController;

/**
 * Testable subclass of QueueController that:
 *   - bypasses the requireMinUserType() auth check (always grants access)
 *   - captures redirect() calls instead of calling header()
 *
 * This allows unit tests to exercise the action bodies without a session
 * or a real database connection.
 */
class TestableQueueController extends QueueController
{
    /** Last URL passed to redirect(), or null if not called yet. */
    public ?string $lastRedirect = null;

    /** @inheritDoc — bypass the auth guard in all unit tests */
    protected function requireMinUserType(int $minType): bool
    {
        // Always grant access — auth is tested structurally, not behaviourally here
        return false;
    }

    /** @inheritDoc — capture redirect target instead of issuing header() */
    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        $this->lastRedirect = (string) $url;
    }
}

/**
 * Unit tests for QueueController.
 *
 * Two groups of tests:
 *   1. Structural contracts — class hierarchy, auth registration, usertype guard.
 *      These run without any DB setup.
 *
 *   2. Action behaviour with a mocked QueryBuilder — verifies the redirects and
 *      the queries built by each action without a real database.  The actual SQL
 *      mutations are verified by the Integration suite
 *      (tests/Integration/Admin/QueueController*Test.php).
 *
 * Mocking strategy: inject a mock Database that returns a mock QueryBuilder.
 * The mock QB is configured to accept any fluent chain and return safe defaults.
 */
#[CoversClass(QueueController::class)]
class QueueControllerTest extends TestCase
{
    private TestableQueueController $ctrl;
    private QueryBuilder&\PHPUnit\Framework\MockObject\MockObject $qbMock;
    private Database&\PHPUnit\Framework\MockObject\MockObject $dbMock;

    protected function setUp(): void
    {
        // Ensure the sURL constant exists (required by redirect paths)
        if (!defined('sURL')) {
            define('sURL', 'http://localhost/');
        }

        // Build a fluent QueryBuilder mock — every method returns $this unless
        // overridden in a specific test.
        $this->qbMock = $this->createMock(QueryBuilder::class);
        $this->qbMock->method('table')->willReturnSelf();
        $this->qbMock->method('select')->willReturnSelf();
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('orderBy')->willReturnSelf();
        $this->qbMock->method('forPage')->willReturnSelf();
        $this->qbMock->method('count')->willReturn(0);
        $this->qbMock->method('getAll')->willReturn([]);
        $this->qbMock->method('update')->willReturn(1);

        // Database mock returns the QB mock from queryBuilder()
        $this->dbMock = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->dbMock->method('queryBuilder')->willReturn($this->qbMock);
        $this->dbMock->type = 'mysql';

        // Inject the DB mock into the static Factory registry so the controller
        // picks it up via Factory::getDatabase()
        $dbRef = &Database::getInstance();
        $dbRef = $this->dbMock;

        $_GET  = [];
        $_POST = [];

        $this->ctrl = new TestableQueueController(null);
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];

        // Restore the DB singleton so subsequent tests (especially integration
        // tests) don't inherit the mock.
        $dbRef = &Database::getInstance();
        $dbRef = null;
    }

    // =========================================================================
    // 1. Structural contracts
    // =========================================================================

    /**
     * QueueController must extend the framework base Controller.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new QueueController(null);

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Controller::class, $ctrl);
    }

    /**
     * All six actions must be auth-protected.
     * An unprotected clear() would allow anonymous denial-of-service via
     * bulk-deletion of all pending background jobs.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new QueueController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        foreach (['display', 'retry', 'retryall', 'delete', 'clear', 'stats'] as $action) {
            $this->assertContains(
                $action, $authActions,
                "QueueController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * requiredUserType must be >= 80 (manager level).
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new QueueController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');

        // Assert
        $this->assertGreaterThanOrEqual(80, $prop->getValue($ctrl));
    }

    /**
     * All expected action methods must exist.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new QueueController(null);

        // Assert
        foreach (['display', 'retry', 'retryall', 'delete', 'clear', 'stats'] as $action) {
            $this->assertTrue(method_exists($ctrl, $action));
        }
    }

    // =========================================================================
    // 2. Action behaviour with mocked QueryBuilder
    // =========================================================================

    /**
     * display() must not throw and must call the QueryBuilder fluent chain.
     * Covers lines 50-79 in QueueController::display().
     *
     * The getView() call inside display() will fail without a full app stack,
     * so we verify that execution reaches at least as far as the QB chain by
     * asserting that the mock's getAll() is invoked.
     */
    public function testDisplayBuildsQueryWithoutFilters(): void
    {
        // Arrange — no GET filters
        $_GET = [];

        // Assert — getAll() is invoked once by the display() fluent chain
        $this->qbMock->expects($this->atLeastOnce())
            ->method('getAll')
            ->willReturn([]);

        // Act — display() will run the QB chain then fail trying to render the view.
        // We catch any view-related exception so the QB assertion is checked.
        try {
            $this->ctrl->display();
        } catch (\Throwable $e) {
            // View rendering may fail in unit test context — that is acceptable.
        }
    }

    /**
     * display() applies WHERE clauses when ?status and ?type GET params are set.
     * Covers lines 67-71 of QueueController::display() — the filter branches.
     *
     * We count how many times where() is called; with two filters it must be
     * called at least twice.
     */
    public function testDisplayAppliesStatusAndTypeFilters(): void
    {
        // Arrange — two GET filters
        $_GET = ['status' => 'failed', 'type' => 'email'];

        // Assert — where() must be called for both filters
        $this->qbMock->expects($this->atLeast(2))
            ->method('where')
            ->willReturnSelf();

        // Act
        try {
            $this->ctrl->display();
        } catch (\Throwable $e) {
            // View rendering may fail — acceptable
        }
    }

    /**
     * retry() with id=0 must redirect to the error URL immediately.
     * Covers lines 93-96 (the invalid-id guard) of QueueController::retry().
     */
    public function testRetryWithZeroIdRedirectsToErrorUrl(): void
    {
        // Act — invalid id
        $this->ctrl->retry(0);

        // Assert — redirect issued to error URL
        $this->assertNotNull($this->ctrl->lastRedirect,
            'retry(0) must issue a redirect');
        $this->assertStringContainsString('error=invalid_id', $this->ctrl->lastRedirect,
            'retry(0) must redirect with error=invalid_id');
    }

    /**
     * retry() with a valid positive id must call update() on the QB and then redirect.
     * Covers lines 99-111 of QueueController::retry().
     */
    public function testRetryWithValidIdCallsUpdateAndRedirects(): void
    {
        // Assert — update() must be invoked
        $this->qbMock->expects($this->once())
            ->method('update')
            ->with(['status' => 'pending', 'error' => null, 'lockedby' => null, 'lockexpires' => null])
            ->willReturn(1);

        // Act
        $this->ctrl->retry(42);

        // Assert — redirect to success URL
        $this->assertNotNull($this->ctrl->lastRedirect);
        $this->assertStringContainsString('message=retried', $this->ctrl->lastRedirect,
            'retry() with valid id must redirect with message=retried');
    }

    /**
     * retryall() must call update() resetting all failed jobs and then redirect.
     * Covers lines 120-135 of QueueController::retryall().
     */
    public function testRetryallCallsUpdateAndRedirects(): void
    {
        // Assert — update() is invoked on the QB
        $this->qbMock->expects($this->once())
            ->method('update')
            ->with(['status' => 'pending', 'error' => null, 'lockedby' => null, 'lockexpires' => null])
            ->willReturn(5);

        // Act
        $this->ctrl->retryall();

        // Assert — redirect to success URL
        $this->assertNotNull($this->ctrl->lastRedirect);
        $this->assertStringContainsString('message=retried_all', $this->ctrl->lastRedirect,
            'retryall() must redirect with message=retried_all');
    }

    /**
     * delete() with id=0 must redirect to the error URL without touching the DB.
     * Covers the invalid-id guard at lines 149-152 of QueueController::delete().
     */
    public function testDeleteWithZeroIdRedirectsToErrorUrl(): void
    {
        // Assert — update() must NOT be called
        $this->qbMock->expects($this->never())->method('update');

        // Act
        $this->ctrl->delete(0);

        // Assert
        $this->assertNotNull($this->ctrl->lastRedirect);
        $this->assertStringContainsString('error=invalid_id', $this->ctrl->lastRedirect,
            'delete(0) must redirect with error=invalid_id');
    }

    /**
     * delete() with a valid id must call update(status=deleted) and redirect.
     * Covers lines 154-161 of QueueController::delete().
     */
    public function testDeleteWithValidIdCallsUpdateAndRedirects(): void
    {
        // Assert — update() must mark the row as 'deleted'
        $this->qbMock->expects($this->once())
            ->method('update')
            ->with(['status' => 'deleted'])
            ->willReturn(1);

        // Act
        $this->ctrl->delete(7);

        // Assert
        $this->assertNotNull($this->ctrl->lastRedirect);
        $this->assertStringContainsString('message=deleted', $this->ctrl->lastRedirect,
            'delete() must redirect with message=deleted');
    }

    /**
     * clear() with a disallowed status ('pending') must redirect to error without
     * touching the database.
     * Covers lines 177-180 of QueueController::clear() — the invalid-status guard.
     */
    public function testClearWithDisallowedStatusRedirectsToError(): void
    {
        // Arrange — 'pending' is in the disallowed list
        $_POST = ['status' => 'pending'];

        // Assert — update() must NOT run
        $this->qbMock->expects($this->never())->method('update');

        // Act
        $this->ctrl->clear();

        // Assert
        $this->assertStringContainsString('error=invalid_status', $this->ctrl->lastRedirect,
            "clear() with status='pending' must redirect with error=invalid_status");

        unset($_POST['status']);
    }

    /**
     * clear() with 'failed' status must call update() and redirect to success.
     * Covers lines 182-188 of QueueController::clear() — the allowed-status path.
     */
    public function testClearWithFailedStatusCallsUpdateAndRedirects(): void
    {
        // Arrange
        $_POST = ['status' => 'failed'];

        // Assert — update() is invoked with status='deleted' (soft-delete)
        $this->qbMock->expects($this->once())
            ->method('update')
            ->with(['status' => 'deleted'])
            ->willReturn(3);

        // Act
        $this->ctrl->clear();

        // Assert
        $this->assertStringContainsString('message=cleared', $this->ctrl->lastRedirect,
            "clear() with status='failed' must redirect with message=cleared");

        unset($_POST['status']);
    }

    /**
     * clear() with 'completed' status must also be allowed.
     * Covers the second allowed value in the allowed-status list.
     */
    public function testClearWithCompletedStatusIsAllowed(): void
    {
        // Arrange
        $_POST = ['status' => 'completed'];

        $this->qbMock->expects($this->once())->method('update')->willReturn(2);

        // Act
        $this->ctrl->clear();

        // Assert — no error, success redirect
        $this->assertStringContainsString('message=cleared', $this->ctrl->lastRedirect);

        unset($_POST['status']);
    }

    /**
     * clear() with missing POST status must redirect to error (empty string is disallowed).
     */
    public function testClearWithMissingStatusRedirectsToError(): void
    {
        // Arrange — no POST data
        $_POST = [];

        $this->qbMock->expects($this->never())->method('update');

        // Act
        $this->ctrl->clear();

        // Assert
        $this->assertStringContainsString('error=invalid_status', $this->ctrl->lastRedirect);
    }

    /**
     * stats() must build four count queries (one per status) and echo valid JSON.
     * Covers lines 202-243 of QueueController::stats().
     *
     * The avg-processing-time query may throw in the mock context; that is
     * caught inside stats() so the JSON output must still be produced.
     */
    public function testStatsOutputsValidJson(): void
    {
        // Arrange — mock count() to return a predictable integer
        $this->qbMock->method('count')->willReturn(5);

        // Mock the raw query used for average processing time so it does not
        // throw an unhandled exception (the stats() method catches it).
        $this->dbMock->method('query')->willThrowException(new \Exception('no table'));

        // Act — capture the JSON output
        ob_start();
        $this->ctrl->stats();
        $output = ob_get_clean();

        // Assert — valid JSON with expected keys
        $data = json_decode($output, true);
        $this->assertIsArray($data, 'stats() must output valid JSON');
        $this->assertArrayHasKey('counts', $data,
            "stats() JSON must contain a 'counts' key");
        $this->assertArrayHasKey('avg_processing_ms', $data,
            "stats() JSON must contain an 'avg_processing_ms' key");

        // counts must include all four statuses
        foreach (['pending', 'processing', 'completed', 'failed'] as $status) {
            $this->assertArrayHasKey($status, $data['counts'],
                "stats() counts must include '$status'");
        }
    }

    /**
     * stats() with an empty DB table must output zero counts.
     */
    public function testStatsWithEmptyTableOutputsZeroCounts(): void
    {
        // Arrange — all counts are 0
        $this->qbMock->method('count')->willReturn(0);
        $this->dbMock->method('query')->willThrowException(new \Exception('no table'));

        // Act
        ob_start();
        $this->ctrl->stats();
        $output = ob_get_clean();

        // Assert
        $data = json_decode($output, true);
        foreach (['pending', 'processing', 'completed', 'failed'] as $status) {
            $this->assertSame(0, $data['counts'][$status],
                "stats() must report 0 for '$status' when table is empty");
        }
        $this->assertNull($data['avg_processing_ms'],
            'stats() must report null avg_processing_ms when query throws');
    }

    /**
     * stats() must include avg_processing_ms when the raw query returns a valid
     * numeric avg_ms result.
     *
     * Covers lines 220-224 of QueueController::stats() — the success branch of the
     * avg processing time query (numRows > 0, avg_ms != null → rounded float).
     */
    public function testStatsIncludesAvgProcessingMsWhenQueryReturnsData(): void
    {
        // Arrange — mock the raw avg query result
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields  = ['avg_ms' => '1234.56'];

        $this->qbMock->method('count')->willReturn(1);
        $this->dbMock->method('query')->willReturn($mockResult);
        $this->dbMock->type = 'mysql';

        // Act
        ob_start();
        $this->ctrl->stats();
        $output = ob_get_clean();

        // Assert
        $data = json_decode($output, true);
        $this->assertIsFloat($data['avg_processing_ms'],
            'stats() must return a float avg_processing_ms when the query returns data');
        $this->assertSame(1234.56, $data['avg_processing_ms'],
            'stats() must round avg_ms to 2 decimal places');
    }

    /**
     * stats() uses PostgreSQL-specific SQL when db->type is 'postgresql'.
     *
     * Covers lines 219-221 of QueueController::stats() — the postgresql branch
     * of the avg-processing-time query.  The distinction matters because the
     * SQL syntax differs between MySQL and PostgreSQL.
     */
    public function testStatsUsesPostgresqlQueryWhenDbTypeIsPostgresql(): void
    {
        // Arrange — PostgreSQL DB type
        $this->dbMock->type = 'postgresql';

        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields  = ['avg_ms' => '500.00'];

        // Assert — query() must be called with PostgreSQL-specific EXTRACT syntax
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->stringContains('EXTRACT'))
            ->willReturn($mockResult);

        // Act
        ob_start();
        $this->ctrl->stats();
        ob_get_clean();
    }

    // =========================================================================
    // 3. Auth-denied paths — requireMinUserType returns true (redirect issued)
    // =========================================================================

    /**
     * When requireMinUserType() returns true, all actions must abort without
     * executing the DB logic.
     *
     * We use a separate controller that simulates an unauthorized user by always
     * returning true from requireMinUserType(), which triggers the redirect path.
     *
     * This covers lines 50, 89, 120, 144, 170 of QueueController (the early-return
     * guard at the start of each action method).
     */
    public function testAllActionsAbortWhenAuthDenied(): void
    {
        // Arrange — a controller where requireMinUserType() always denies access
        $deniedCtrl = new class(null) extends QueueController {
            public ?string $lastRedirect = null;

            protected function requireMinUserType(int $minType): bool
            {
                // Always deny access — simulates an unauthorized user
                $this->redirect(sURL);
                return true;
            }

            public function redirect($url = null, $quit = true, $code = '302'): void
            {
                $this->lastRedirect = (string) $url;
            }
        };

        // Assert — none of the actions must touch the DB
        $this->qbMock->expects($this->never())->method('update');
        $this->qbMock->expects($this->never())->method('getAll');

        // Act — call all actions; each must return early after the auth redirect
        try { $deniedCtrl->display(); } catch (\Throwable $e) { }
        $deniedCtrl->retry(1);
        $deniedCtrl->retryall();
        $deniedCtrl->delete(1);
        $_POST['status'] = 'failed';
        $deniedCtrl->clear();
        unset($_POST['status']);
        ob_start();
        $deniedCtrl->stats();
        ob_get_clean();

        // Assert — a redirect was issued for each call
        $this->assertNotNull($deniedCtrl->lastRedirect,
            'An auth-denied action must issue a redirect');
    }
}
