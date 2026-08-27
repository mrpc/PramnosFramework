<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;

/**
 * The constants a test run needs, defined where every project gets them.
 *
 * `Application::close()` calls `exit()` unless `PRAMNOS_TESTING` is defined, in
 * which case it throws. Under PHPUnit an `exit()` is not a failing test: the
 * process stops mid-run, the summary never prints, and whatever the dying page
 * wrote lands in the terminal looking like test output. This framework's own
 * bootstrap defined the constant, and the bootstrap it scaffolds for a project
 * did not — so a single database fault truncated a project's whole suite, with a
 * maintenance page where the results should have been.
 *
 * Defining it in `TestEnvironment::setup()` reaches every project, including the
 * ones already written, because their bootstrap already calls it. This test
 * cannot observe that from inside a run that has the constant either way, so it
 * asserts on the source — the point being that the framework must not depend on
 * its own bootstrap for something every project needs.
 */
class BootstrapConstantsTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Pramnos/Framework/Testing/TestEnvironment.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * `setup()` defines PRAMNOS_TESTING, so a project's bootstrap need not.
     */
    public function testSetupDefinesTheTestingConstant(): void
    {
        // Act
        $source = $this->source();

        // Assert
        $this->assertStringContainsString("define('PRAMNOS_TESTING', true)", $source);
    }

    /**
     * It is guarded, so a bootstrap that defines it first is not a fatal error.
     *
     * This framework's own bootstrap does exactly that.
     */
    public function testTheDefinitionIsGuarded(): void
    {
        // Act
        $source = $this->source();

        // Assert
        $this->assertStringContainsString("if (!defined('PRAMNOS_TESTING'))", $source);
    }

    /**
     * And the behaviour it buys is real: close() throws rather than exits.
     *
     * Without this the assertions above would only be checking a spelling.
     */
    public function testCloseThrowsInsteadOfEndingTheProcess(): void
    {
        // Arrange
        $this->assertTrue(defined('PRAMNOS_TESTING'), 'the constant must be defined by now');
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Application::close() called with msg: stopped');

        // Act
        $app->close('stopped');
    }

    /**
     * `setup()` also lowers the bcrypt cost, so a project's fixtures are cheap.
     *
     * The production cost is deliberately slow — that is its whole purpose — and a
     * suite pays it on every fixture that has a password. Measured here: **143 ms
     * per hash**, and two-factor enrolment hashes ten backup codes, so one
     * `startSetup()` + `completeSetup()` costs 1.4 s before the test does
     * anything.
     *
     * This framework's bootstrap has set it since the suite was first profiled;
     * the bootstrap it *scaffolds* did not. One project's 188 integration tests
     * took 69 s, of which 62 s belonged to the 23 that enrol two-factor
     * authentication. With the cost at 4 the same suite is 3.5 s.
     *
     * Nothing about the algorithm changes: a hash made at cost 4 is verified by
     * the same `password_verify()` the application calls.
     */
    public function testSetupLowersTheBcryptCostForProjects(): void
    {
        // Act
        $source = $this->source();

        // Assert
        $this->assertStringContainsString('PasswordHash::COST_ENV', $source);
        $this->assertStringContainsString("COST_ENV . '=4'", $source);
    }

    /**
     * It defers to a cost the environment already states.
     *
     * A test that is genuinely about the cost sets the variable itself, and
     * overwriting it would make that test assert against 4 rather than against
     * what it chose.
     */
    public function testAnExplicitCostIsNotOverwritten(): void
    {
        // Act
        $source = $this->source();

        // Assert
        $this->assertStringContainsString(
            'if (getenv(\\Pramnos\\Auth\\PasswordHash::COST_ENV) === false)',
            $source
        );
    }

    /**
     * And the cost is in force in this very run.
     *
     * The assertions above read the source; this one proves the effect, and it is
     * the one that fails if the mechanism changes shape.
     */
    public function testHashingIsCheapInTheSuite(): void
    {
        // Act
        $start = microtime(true);
        \Pramnos\Auth\PasswordHash::make('a-password-to-hash');
        $elapsed = (microtime(true) - $start) * 1000;

        // Assert — cost 4 is well under a millisecond; the production cost is ~143
        $this->assertLessThan(
            50.0,
            $elapsed,
            sprintf('hashing took %.1f ms — the suite is running at the production cost', $elapsed)
        );
    }
}
