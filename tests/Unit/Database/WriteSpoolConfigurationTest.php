<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\WriteSpool;

/**
 * How the write spool decides where to buffer, and how long to keep trying.
 *
 * `WriteSpoolTest` covers the buffering itself thoroughly — the grouping, the retries, the poison row,
 * the transformers — and it does so through a subclass that **overrides `directory()`**, which is the
 * right way to give it a scratch directory and the reason the real one had never run. A test that
 * replaces a method is not a test of it, and a coverage report says «this never executed» without
 * saying «because the test that would have run it stubbed it out».
 *
 * So this file drives the real class. What it decides matters more than its size suggests: the driver
 * chosen here is the difference between a row written now, a row written in a batch a second later,
 * and a row written on another server — and the whole point of the spool is that a caller cannot tell.
 */
#[CoversClass(WriteSpool::class)]
class WriteSpoolConfigurationTest extends TestCase
{
    private ?string $savedSetting = null;

    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', realpath(__DIR__ . '/../../../'));
        }

        WriteSpool::reset();
        WriteSpool::resetTransformers();
    }

    protected function tearDown(): void
    {
        WriteSpool::reset();
        WriteSpool::resetTransformers();
        Settings::setSetting('spool_driver', '', false);
        Settings::setSetting('spool_max_attempts', '', false);

        parent::tearDown();
    }

    /** The real `directory()`, which is `protected static`. */
    private function resolveDirectory(): ?string
    {
        return (new \ReflectionMethod(WriteSpool::class, 'directory'))->invoke(null);
    }

    /**
     * The spool directory is under `var/`, and it is created if it is not there.
     *
     * Created rather than required, because the alternative is a framework that buffers nothing on a
     * fresh checkout and gives no reason — every write falls through to the synchronous path, which is
     * correct and slower, and nothing anywhere says why.
     */
    public function testTheSpoolDirectoryIsResolvedAndCreated(): void
    {
        // Act
        $directory = $this->resolveDirectory();

        // Assert
        $this->assertIsString($directory);
        $this->assertDirectoryExists($directory);
        $this->assertDirectoryIsWritable($directory);
        $this->assertStringEndsWith('spool', $directory);
    }

    /**
     * It is resolved once, not per row.
     *
     * `append()` is called on the hot path of every request that writes an audit row, and the check
     * behind this is two filesystem calls — `is_dir` and `is_writable`. Asserted by taking the
     * directory away after the first answer: a second call that returned the same string cannot have
     * looked.
     */
    public function testTheDirectoryIsResolvedOnceAndThenRemembered(): void
    {
        // Arrange
        $first = $this->resolveDirectory();
        $this->assertIsString($first);

        /*
         * The answer must survive the directory going away — and it is put back immediately.
         *
         * Removing `var/spool` and leaving it removed is a process-wide side effect: every later test
         * in the run that buffers a row pays to recreate it, and the spool is on the hot path of
         * anything that writes an audit row. Measured: leaving it out cost the suite about thirty
         * seconds.
         */
        @rmdir($first);
        $second = $this->resolveDirectory();
        @mkdir($first, 0775, true);

        // Assert
        $this->assertSame($first, $second, 'the filesystem was consulted again');

        // …and it comes back after a reset, which is what `reset()` is for
        WriteSpool::reset();
        $this->assertDirectoryExists((string) $this->resolveDirectory());
    }

    /**
     * A configured driver is used as configured.
     *
     * The three are genuinely different deployments rather than preferences: `sync` writes now, `file`
     * buffers on this machine, `redis` buffers somewhere every machine can see. The last is the only
     * one that is correct behind a load balancer, and it is also the slowest per row — a syscall
     * against a TCP round trip — so it is a decision an installation makes and not something to infer
     * from Redis being reachable.
     *
     * @param string $configured
     */
    #[DataProvider('drivers')]
    public function testAConfiguredDriverIsHonoured(string $configured): void
    {
        // Arrange
        Settings::setSetting('spool_driver', $configured, false);
        WriteSpool::reset();

        // Act & Assert
        $this->assertSame($configured, WriteSpool::driver());
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return [
            'redis' => ['redis'],
            'file'  => ['file'],
            'sync'  => ['sync'],
        ];
    }

    /**
     * The setting is read case-insensitively and trimmed.
     *
     * It comes from a settings row or an environment file, both of which are edited by hand — so
     * `File` and ` file ` are what people write, and a driver that matched only the exact lower-case
     * token would silently fall back while the setting appeared to say otherwise.
     */
    public function testTheDriverSettingIsTrimmedAndLowercased(): void
    {
        // Arrange
        Settings::setSetting('spool_driver', '  SyNc  ', false);
        WriteSpool::reset();

        // Act & Assert
        $this->assertSame('sync', WriteSpool::driver());
    }

    /**
     * An unrecognised driver is ignored rather than used.
     *
     * A typo — `redi`, `files` — must not become the driver, because the value is passed nowhere that
     * would reject it: the spool would simply match none of its branches and behave in whatever way
     * the last `if` happened to leave. Falling back to the resolved default is the only answer that
     * keeps buffering working.
     */
    public function testAnUnrecognisedDriverFallsBackRatherThanBeingUsed(): void
    {
        // Arrange
        Settings::setSetting('spool_driver', 'postgres-maybe', false);
        WriteSpool::reset();

        // Act
        $driver = WriteSpool::driver();

        // Assert
        $this->assertNotSame('postgres-maybe', $driver);
        $this->assertContains($driver, ['redis', 'file', 'sync']);
    }

    /**
     * The retry limit comes from the setting, and `0` means «never park».
     *
     * `0` is not «do not retry» — it is the behaviour the spool had before the limit existed: retry for
     * ever. Which is the right reading, because a parked row is a row nobody is looking at, and an
     * installation that would rather keep trying than accumulate a parked pile should be able to say so
     * with the obvious value.
     */
    public function testTheRetryLimitIsConfigurableAndZeroMeansNeverPark(): void
    {
        // Arrange & Act & Assert — from the setting
        Settings::setSetting('spool_max_attempts', '7', false);
        WriteSpool::reset();
        $this->assertSame(7, WriteSpool::maxAttempts());

        // …zero survives, rather than being read as «unset»
        Settings::setSetting('spool_max_attempts', '0', false);
        WriteSpool::reset();
        $this->assertSame(0, WriteSpool::maxAttempts());

        // …a negative value is clamped rather than producing a negative comparison
        Settings::setSetting('spool_max_attempts', '-3', false);
        WriteSpool::reset();
        $this->assertSame(0, WriteSpool::maxAttempts());
    }

    /**
     * An explicit limit beats the setting, and `null` gives the setting back.
     *
     * `setMaxAttempts()` is what a command uses to say «drain everything once, whatever the
     * configuration says» — so it has to be reversible, or the process that called it changes the
     * behaviour of everything after it.
     */
    public function testAnExplicitLimitOverridesTheSettingAndCanBeGivenBack(): void
    {
        // Arrange
        Settings::setSetting('spool_max_attempts', '7', false);
        WriteSpool::reset();

        // Act & Assert
        WriteSpool::setMaxAttempts(2);
        $this->assertSame(2, WriteSpool::maxAttempts());

        WriteSpool::setMaxAttempts(-5);
        $this->assertSame(0, WriteSpool::maxAttempts(), 'a negative override was not clamped');

        WriteSpool::setMaxAttempts(null);
        $this->assertSame(7, WriteSpool::maxAttempts(), 'the setting did not come back');
    }

    /**
     * `resetTransformers()` forgets the registered ones *and* the framework's own registration flag.
     *
     * Both halves, and the flag is the one that matters. The framework registers its own transformers
     * once and records that it has; forgetting the callables without the flag leaves a process that
     * believes they are installed and has none — so rows go to the database untransformed, which for
     * the framework's own tables means columns arriving in the wrong shape rather than an error.
     */
    public function testResettingTransformersForgetsTheFrameworkRegistrationToo(): void
    {
        // Arrange
        WriteSpool::transform('probe_table', static fn (array $row): array => $row + ['seen' => 1]);

        $registered = new \ReflectionProperty(WriteSpool::class, 'frameworkRegistered');
        $registered->setValue(null, true);

        $transformers = new \ReflectionProperty(WriteSpool::class, 'transformers');
        $this->assertNotSame([], $transformers->getValue(), 'the fixture registered nothing');

        // Act
        WriteSpool::resetTransformers();

        // Assert
        $this->assertSame([], $transformers->getValue());
        $this->assertFalse(
            $registered->getValue(),
            'a process would believe the framework transformers are installed and have none'
        );
    }
}
