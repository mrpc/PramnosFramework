<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;

/**
 * A failed list query must not end an API request.
 *
 * `Model::_getList()` catches a query failure, logs it, and — with
 * `$displayerroroutput` at its default of `true` — calls
 * `$this->controller->application->showError()`, which **exits**.
 *
 * For a page that is defensible: there is nothing useful to render without the list.
 * For an API it is not. The framework has an error envelope for exactly this
 * (`ApiListResponse::error()`), and the caller never reaches it, because the process
 * is gone. The two lines below it — set `sqlError`, return an empty list — are what
 * the caller was written against and were unreachable on the one path that needs them.
 *
 * So the page path is unchanged and a client that asked for JSON is answered the way
 * its caller knows how to answer.
 *
 * These tests drive the decision rather than the query, because provoking a real query
 * failure needs a database and this is about which branch is taken, not about SQL.
 */
class ListErrorDoesNotKillAnApiRequestTest extends TestCase
{
    /** @var array<string, string|null> `$_SERVER` keys these tests forge */
    private array $serverBackup = [];

    /**
     * Clears the request headers the decision reads.
     *
     * @return void
     */
    protected function setUp(): void
    {
        foreach (['HTTP_ACCEPT', 'HTTP_X_REQUESTED_WITH'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
    }

    /**
     * Restores `$_SERVER`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    /**
     * A JSON client is recognised through the application, not through a second copy.
     *
     * `clientWantsJson()` became public so `Model` could ask rather than re-implement
     * the header test. A second implementation would drift, and the drift would show
     * up as one of the two paths answering HTML.
     *
     * @return void
     */
    public function testTheDecisionIsReadableFromTheApplication(): void
    {
        // Arrange
        $app = Application::getInstance();

        // Act & Assert — no Accept at all is a page
        $this->assertFalse($app->clientWantsJson());

        // Act & Assert — an API client
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertTrue($app->clientWantsJson());
    }

    /**
     * The guard is a public method, so `Model` cannot be the one that drifts.
     *
     * A structural check: `clientWantsJson()` must remain reachable from outside
     * `Application`. If it goes back to `protected`, `Model::_getList()` fatals on
     * every failed query — a change that would look like a tidy-up and would only
     * show itself on the error path, which is the path nobody exercises.
     *
     * @return void
     */
    public function testTheGuardStaysReachableFromModel(): void
    {
        // Act
        $method = new \ReflectionMethod(Application::class, 'clientWantsJson');

        // Assert
        $this->assertTrue(
            $method->isPublic(),
            'Model::_getList() calls this on the application; making it protected '
            . 'turns every failed list query into a fatal error.'
        );
    }

    /**
     * `_getList()` reports a failure through `sqlError` rather than through exiting.
     *
     * Driven with a model whose table does not exist, so the query genuinely fails,
     * and an `Accept` that names JSON so the page branch is skipped. Without the fix
     * this test does not fail — it *dies*, because `showError()` calls `exit()`
     * (under `PRAMNOS_TESTING` it throws instead, which is what makes the difference
     * observable at all).
     *
     * @return void
     */
    public function testAFailedQueryLeavesSqlErrorAndReturnsAnEmptyList(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $model = new class (new Controller()) extends Model {
            /** @var string A table no migration creates */
            protected $_dbtable = 'a_table_that_does_not_exist_anywhere';

            /**
             * Exposes the protected listing helper.
             *
             * @return array<int, mixed>
             */
            public function listThem(): array
            {
                return $this->_getList();
            }

            /**
             * Exposes the recorded error.
             *
             * @return string|null
             */
            public function error(): ?string
            {
                return $this->sqlError;
            }
        };

        // Act
        $rows = $model->listThem();

        // Assert — the caller is still running and can report the failure itself
        $this->assertSame([], $rows);
        $this->assertNotNull(
            $model->error(),
            'A failed list query must record why, so ApiListResponse::error() has '
            . 'something to say.'
        );
    }
}
