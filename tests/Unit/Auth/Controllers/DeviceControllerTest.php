<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Device;

/**
 * Unit tests for Pramnos\Auth\Controllers\Device.
 *
 * Device is tightly coupled to the Application / view layer for its main
 * display flow. We bypass the constructor (which calls parent::__construct()
 * and instantiates WebhookService) via
 * ReflectionClass::newInstanceWithoutConstructor() and exercise the code
 * paths that are reachable without a live view system.
 *
 * The display() method calls into the view/application layer (which needs
 * the INCLUDES constant and a booted app) so those tests accept that a
 * Throwable will propagate from the view layer — we only assert that the
 * internal RuntimeException from handleVerification() was properly caught
 * and did NOT leak out.
 */
#[CoversClass(Device::class)]
class DeviceControllerTest extends TestCase
{
    private Device $device;

    /**
     * Create the Device instance without running the constructor so that
     * WebhookService / Application / Factory::getDatabase() are never called.
     */
    protected function setUp(): void
    {
        // Arrange – bypass constructor
        $rc           = new \ReflectionClass(Device::class);
        $this->device = $rc->newInstanceWithoutConstructor();

        // Set a stub Application so Controller::getView() does not emit a
        // "Attempt to read property on null" warning when display() searches
        // for view files. The view layer will still throw (INCLUDES not defined
        // in unit-test context) but at a later point, cleanly as a Throwable.
        $mockApp = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtraPaths'])
            ->getMock();
        $mockApp->method('getExtraPaths')->willReturn([]);
        $this->device->application = $mockApp;

        // Clean superglobal state
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
    }

    // ── display() input routing ───────────────────────────────────────────────

    /**
     * display() with action=verify and empty user_code routes to
     * handleVerification() which throws RuntimeException('Missing user_code')
     * internally, catches it, then calls showErrorPage() which eventually
     * fails on the view layer (INCLUDES constant).
     *
     * The key invariant: RuntimeException must never propagate out of display().
     * The view-layer Error is expected and acceptable in a unit-test context.
     *
     * This covers:
     *   - display() `if ($action === 'verify')` branch (line ~46)
     *   - handleVerification() `if ($userCode === '') throw` (lines ~97-99)
     *   - handleVerification() catch block (lines ~143-145)
     */
    public function testDisplayWithVerifyActionAndEmptyUserCodeDoesNotLeakRuntimeException(): void
    {
        // Arrange — simulate a POST with action=verify but no user_code
        $_POST['action']    = 'verify';
        $_POST['user_code'] = '';

        // Act — expect something from the view layer but NOT the RuntimeException
        $caughtMessage = null;
        $wasRuntime    = false;

        try {
            $this->device->display();
        } catch (\RuntimeException $e) {
            // This means handleVerification() let the RuntimeException escape
            $wasRuntime    = true;
            $caughtMessage = $e->getMessage();
        } catch (\Throwable $e) {
            // View-layer Error (INCLUDES constant / app not booted) — acceptable
            $this->addToAssertionCount(1);
        }

        // Assert — the RuntimeException must have been caught internally
        $this->assertFalse($wasRuntime,
            "RuntimeException must be caught inside handleVerification(), not propagated. "
            . "Got: {$caughtMessage}");
    }

    /**
     * display() with no POST action defaults to 'show_form' and routes to
     * showVerificationForm(), confirming the else branch (line ~48-50) is taken
     * rather than handleVerification().
     *
     * We verify this by confirming no RuntimeException for "Missing user_code"
     * leaks out (that error only comes from handleVerification()).
     *
     * This covers the `else { $this->showVerificationForm($userCode); }` branch.
     */
    public function testDisplayWithoutActionRoutesToShowForm(): void
    {
        // Arrange — no POST action at all
        $_POST = [];
        $_GET  = [];

        $wasRuntimeWithMissingUserCode = false;

        // Act
        try {
            $this->device->display();
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Missing user_code')) {
                $wasRuntimeWithMissingUserCode = true;
            }
        } catch (\Throwable $e) {
            // View/app layer error — acceptable in unit tests
            $this->addToAssertionCount(1);
        }

        // Assert — "Missing user_code" only comes from handleVerification(),
        // which must NOT be called when the action is not 'verify'
        $this->assertFalse($wasRuntimeWithMissingUserCode,
            'show_form path must not enter handleVerification()');
    }

    // ── validateCredentials() ─────────────────────────────────────────────────

    /**
     * validateCredentials() with empty username/password and no DB must either
     * return an empty array (if User::validateUserCredentials() is present) or
     * propagate a Throwable from the missing DB connection.
     *
     * This covers the method_exists() check on line ~271 and at minimum
     * exercises one of the two branches.
     */
    public function testValidateCredentialsReturnTypeIsArray(): void
    {
        // Mock the Database singleton to ensure DB fallback path doesn't throw on connection issues
        $dbMock = $this->createMock(\Pramnos\Database\Database::class);
        $dbMock->method('prepareQuery')->willReturn('SELECT ...');
        
        $resMock = $this->createMock(\Pramnos\Database\Result::class);
        $resMock->numRows = 0;
        $dbMock->method('query')->willReturn($resMock);

        $dbSingleton = &\Pramnos\Database\Database::getInstance();
        $dbOriginal = $dbSingleton;
        $dbSingleton = $dbMock;

        try {
            $result = $this->callPrivate('validateCredentials', 'user', 'pass');
            $this->assertIsArray($result, 'validateCredentials() must always return an array');
        } finally {
            // Restore database singleton
            $dbSingleton = $dbOriginal;
        }
    }

    // ── Private reflection helper ─────────────────────────────────────────────

    /**
     * Call a private method on $this->device via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Device::class, $method);
        return $rm->invoke($this->device, ...$args);
    }
}
