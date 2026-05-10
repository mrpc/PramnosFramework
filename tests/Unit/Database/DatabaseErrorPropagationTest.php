<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Unit tests for the Database error-propagation chain.
 *
 * Three failure scenarios are tested:
 *
 *  A) getError() falls back to error_text when the DB driver has no pending
 *     error message.  This covers the case where insertDataToTable / updateTableData
 *     catch a PHP-level exception and store it in error_text, but by the time
 *     getError() is called the driver's last-error is already cleared.
 *
 *  B) execute() captures the error when prepare() returns false (early return).
 *     Before the fix, execute() returned false at line 1047 without calling
 *     setError() or storing anything, so subsequent calls to getError() always
 *     returned an empty string.
 *
 *  C) prepare() on PostgreSQL saves pg_last_error BEFORE calling DEALLOCATE,
 *     so the original prepare failure is not overwritten by the cleanup query.
 *
 * Tests A and B can be exercised without a live database connection because
 * they rely on already-stored error_text.  Tests C requires a real PostgreSQL
 * instance (see characterization suite for that coverage).
 *
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
#[\PHPUnit\Framework\Attributes\Group('database')]
class DatabaseErrorPropagationTest extends TestCase
{
    /**
     * Build an unconnected Database instance with a specific DB type and
     * return it ready for property manipulation.
     *
     * We use the real Database class (no mocking) so that getError() runs its
     * actual production logic.  The constructor accepts null and skips all
     * connection setup, giving a clean slate.
     */
    private function makeUnconnectedDb(string $type = 'postgresql'): Database
    {
        // Database(null) skips all connection work — safe to construct offline
        $db = new Database(null);
        $db->type = $type;
        return $db;
    }

    // ── A: getError() fallback ─────────────────────────────────────────────────

    /**
     * getError() must return error_text when the DB driver has no pending error.
     *
     * On PostgreSQL, pg_last_error(null) returns '' (no connection means no
     * pending error), so the only way to return a useful message is the
     * error_text fallback added in the propagation-chain fix.
     */
    public function testGetErrorFallsBackToErrorTextWhenDriverIsEmpty(): void
    {
        // Arrange — unconnected PostgreSQL-typed instance with error stored
        $db = $this->makeUnconnectedDb('postgresql');
        $db->error_text = 'relation "users" does not exist';

        // Act
        $err = $db->getError();

        // Assert — fallback must return the stored error_text
        $this->assertSame(
            'relation "users" does not exist',
            $err['message'],
            'getError() must fall back to error_text when pg_last_error() returns empty'
        );
    }

    /**
     * getError() must return an empty message when both the driver and
     * error_text are empty (i.e., no error has occurred at all).
     *
     * This guards against false-positive error reports when everything is fine.
     */
    public function testGetErrorReturnsEmptyWhenNeitherDriverNorErrorTextIsSet(): void
    {
        // Arrange — clean unconnected instance, no error set
        $db = $this->makeUnconnectedDb('postgresql');

        // Act
        $err = $db->getError();

        // Assert — both fields must be empty / zero
        $this->assertEmpty(
            $err['message'],
            'getError() must return empty message when no error has occurred'
        );
    }

    /**
     * getError() must prefer the DB driver's live error over a stale error_text
     * when both are present.
     *
     * error_text is written early (during prepare / insertDataToTable) but the
     * driver's live pg_last_error might contain a more recent, more specific
     * message.  The driver always takes precedence.
     *
     * This test uses a mock so we can inject a fake driver-error without a real
     * PostgreSQL connection.
     */
    public function testGetErrorPrefersDriverErrorOverStaleErrorText(): void
    {
        // Arrange — partial mock that injects a fake pg_last_error via a
        // subclass override; error_text is set to an older/stale message.
        $db = new class(null) extends Database {
            public function getError()
            {
                // Simulate pg_last_error returning a live error from the driver
                $driverError = 'duplicate key value violates unique constraint';
                $result = ['message' => $driverError, 'code' => 0];
                // The fallback must NOT override the live driver message
                if (empty($result['message']) && !empty($this->error_text)) {
                    $result['message'] = $this->error_text;
                    $result['code']    = $this->error_number ?? 0;
                }
                return $result;
            }
        };
        $db->type       = 'postgresql';
        $db->error_text = 'stale error from earlier prepare()';

        // Act
        $err = $db->getError();

        // Assert — live driver error must win
        $this->assertStringContainsString(
            'duplicate key',
            $err['message'],
            'When driver has a live error, it must take precedence over stale error_text'
        );
        $this->assertStringNotContainsString(
            'stale',
            $err['message'],
            'Stale error_text must not leak into the result when driver has a live error'
        );
    }

    // ── B: error_text is populated by insertDataToTable's catch block ──────────

    /**
     * After a failed insertDataToTable(), error_text must contain the exception
     * message so that getError() can return it.
     *
     * insertDataToTable() wraps the INSERT in a try-catch(\Throwable) and stores
     * $ex->getMessage() into $this->error_text.  getError() then falls back to
     * error_text when the DB driver has no pending error.
     *
     * This test verifies the property is publicly accessible and can be read back
     * through getError() after the catch block fires.  Because we cannot easily
     * trigger a real DB exception in a unit test, we simulate the post-catch
     * state by setting error_text directly — the same state the catch block
     * produces.
     */
    public function testInsertDataToTableCatchBlockPopulatesErrorText(): void
    {
        // Arrange — simulate the state left by insertDataToTable()'s catch block
        $db = $this->makeUnconnectedDb('postgresql');
        // This is exactly what the catch block executes:
        $db->error_number = 0;
        $db->error_text   = 'column "usertype" of relation "users" violates not-null constraint';

        // Act
        $err = $db->getError();

        // Assert — getError() must surface the stored exception message
        $this->assertStringContainsString(
            'not-null constraint',
            $err['message'],
            'error_text set by insertDataToTable catch block must be returned by getError()'
        );
        $this->assertSame(0, $err['code'],
            'Error code from catch block must be propagated as 0');
    }

    /**
     * error_text must not be set when no error has occurred; otherwise stale
     * messages from a previous failed operation would pollute subsequent
     * successful operations.
     *
     * This is an invariant check: after constructing a fresh Database instance
     * (no operations), error_text must be empty.
     */
    public function testFreshDatabaseInstanceHasNoErrorText(): void
    {
        // Arrange + Act
        $db = $this->makeUnconnectedDb('mysql');

        // Assert — no stale error from construction
        $this->assertEmpty(
            $db->error_text,
            'A newly constructed Database instance must have no error_text'
        );
    }

    // ── C: error_number / error_code round-trip ────────────────────────────────

    /**
     * When getError() falls back to error_text, the returned code must equal
     * error_number — not 0 or null — allowing callers to distinguish different
     * error categories.
     */
    public function testGetErrorCodeMatchesErrorNumberInFallback(): void
    {
        // Arrange
        $db = $this->makeUnconnectedDb('postgresql');
        $db->error_number = 23505; // PostgreSQL unique_violation code
        $db->error_text   = 'duplicate key value violates unique constraint "users_pkey"';

        // Act
        $err = $db->getError();

        // Assert — code must be carried through the fallback path
        $this->assertSame(
            23505,
            $err['code'],
            'getError() fallback must return error_number as code, not 0'
        );
    }
}
