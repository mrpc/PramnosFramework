<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;

/**
 * Every log grew without limit, in every installation, until a disk noticed.
 *
 * `truncateLogFile()` has done size-capped rotation with backups all along and had **one
 * caller in the whole framework**: the log viewer's manual button. So nothing rotated
 * unless somebody opened a screen and pressed something.
 *
 * The fix rotates from `log()` itself rather than from a scheduled task, and the reason is
 * the reason a framework default exists at all: a sweep through `FrameworkSchedule` is the
 * tidier design and only works where something runs `schedule:run` — the installation that
 * reported this had the scheduler unrun for the life of the project. What is left has to
 * hold where nothing was set up.
 */
#[CoversClass(Logger::class)]
class LogRotationHappensOnWriteTest extends TestCase
{
    private mixed $previousMode = null;
    private string $file = '';

    protected function setUp(): void
    {
        // The output mode is a private static and the suite may be in stream mode; this
        // test is about what lands in a file. Restoring exactly what was there is the only
        // version that does not leak into unrelated tests — `null` means "resolve from the
        // environment", and `setOutputMode()` does not accept it.
        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $this->previousMode = $property->getValue();
        Logger::setOutputMode(Logger::OUTPUT_FILE);

        // A file name unique to this test, so nothing accumulated by an earlier run or an
        // earlier test can satisfy or defeat an assertion about size.
        $this->file = 'rotationprobe_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Logger::setMaxSize(null);
        Logger::setMaxBackups(null);

        foreach (glob($this->path() . '*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $property->setValue(null, $this->previousMode);
    }

    private function path(): string
    {
        return Logger::logDirectory() . DIRECTORY_SEPARATOR . $this->file . '.log';
    }

    /**
     * A file past the cap is rotated before the next line is appended to it.
     *
     * The assertion that matters is the size of the live file: a `.1` that exists beside a
     * live file still holding everything would be a copy, not a rotation.
     */
    public function testAFilePastTheCapIsRotatedOnTheNextWrite(): void
    {
        // Arrange — 200 bytes of cap and rather more than that on disk
        Logger::setMaxSize(200);
        Logger::setMaxBackups(3);
        file_put_contents($this->path(), str_repeat('x', 500) . "\n");

        // Act
        Logger::log('the line that trips it', $this->file);

        // Assert
        $this->assertFileExists($this->path() . '.1', 'the oversized file must be kept as .1');
        $this->assertSame(
            500 + 1,
            filesize($this->path() . '.1'),
            '.1 must be what was there, untouched'
        );
        // Not "under the cap": the new file opens with the rotation notice, which is itself
        // longer than a 200-byte cap. What matters is that the 500 bytes are gone from it.
        $this->assertStringNotContainsString(
            str_repeat('x', 500),
            (string) file_get_contents($this->path()),
            'the live file must start again, not carry the 500 bytes forward'
        );
        $this->assertStringContainsString(
            'the line that trips it',
            (string) file_get_contents($this->path()),
            'and the line that triggered the rotation must still be written'
        );
    }

    /**
     * A file under the cap is left alone.
     *
     * The other half of the contract, and the one that decides whether this is affordable:
     * the common case is one `stat` and a return.
     */
    public function testAFileUnderTheCapIsNotTouched(): void
    {
        // Arrange
        Logger::setMaxSize(10000);
        file_put_contents($this->path(), "small\n");

        // Act
        Logger::log('another line', $this->file);

        // Assert
        $this->assertFileDoesNotExist($this->path() . '.1');
        $this->assertStringContainsString('small', (string) file_get_contents($this->path()));
    }

    /**
     * `0` never rotates.
     *
     * An installation that already runs `logrotate` needs a way to say so, and two things
     * rotating the same file is worse than neither — the external tool renames the file the
     * framework is appending to, and the framework then renames the one it renamed.
     */
    public function testZeroMeansNeverRotate(): void
    {
        // Arrange
        Logger::setMaxSize(0);
        file_put_contents($this->path(), str_repeat('x', 5000) . "\n");

        // Act
        Logger::log('still appending', $this->file);

        // Assert
        $this->assertFileDoesNotExist($this->path() . '.1');
        $this->assertGreaterThan(5000, filesize($this->path()));
    }

    /**
     * The rotation notice goes into the file that was rotated.
     *
     * `truncateLogFile()` announced the rotation through `notice()` without passing the
     * file name, so rotating `oauth.log` wrote the announcement into
     * `pramnosframework.log` — the one place nobody looks when asking why a log starts
     * where it does.
     */
    public function testTheRotationNoticeLandsInTheRotatedFile(): void
    {
        // Arrange
        Logger::setMaxSize(200);
        file_put_contents($this->path(), str_repeat('x', 500) . "\n");

        // Act
        Logger::log('trigger', $this->file);

        // Assert
        $this->assertStringContainsString(
            'Log file rotated',
            (string) file_get_contents($this->path())
        );
    }

    /**
     * One write rotates once, even when the notice itself exceeds the cap.
     *
     * The notice is written through `log()`, which is the method that rotates, so a cap
     * small enough to be tripped by the notice is the shape that would cascade. It does
     * not, because `truncateLogFile()` renames the file away before announcing it — and
     * this test is honest about that being the mechanism: removing the re-entry guard in
     * `rotateIfNeeded()` does not redden it. The guard stays anyway, because otherwise the
     * only thing preventing unbounded recursion is the statement order inside another
     * method.
     */
    public function testTheNoticeDoesNotTriggerAnotherRotation(): void
    {
        // Arrange — a cap so small that the notice itself exceeds it
        Logger::setMaxSize(1);
        Logger::setMaxBackups(3);
        file_put_contents($this->path(), "over\n");

        // Act
        Logger::log('trigger', $this->file);

        // Assert — one rotation, not a cascade through every backup slot
        $this->assertFileExists($this->path() . '.1');
        $this->assertFileDoesNotExist($this->path() . '.2');
    }

    /**
     * Backups shift along and the oldest is dropped.
     *
     * Rotation that never discards is not rotation; it is the same unbounded growth spread
     * over more files.
     */
    public function testTheOldestBackupIsDropped(): void
    {
        // Arrange — two slots, and .1 and .2 already taken
        Logger::setMaxSize(200);
        Logger::setMaxBackups(2);
        file_put_contents($this->path() . '.1', "first\n");
        file_put_contents($this->path() . '.2', "oldest\n");
        file_put_contents($this->path(), str_repeat('x', 500) . "\n");

        // Act
        Logger::log('trigger', $this->file);

        // Assert — what was .1 is now .2, and `oldest` is gone
        $this->assertStringContainsString('first', (string) file_get_contents($this->path() . '.2'));
        $this->assertFileDoesNotExist($this->path() . '.3');
    }

    /**
     * The cap comes from the environment when nothing overrides it.
     *
     * Covers the resolution order rather than the rotation: an explicit override first,
     * then `PRAMNOS_LOG_MAX_SIZE`, then the constant, then the default.
     */
    public function testTheCapIsReadFromTheEnvironment(): void
    {
        // Arrange
        Logger::setMaxSize(null);
        putenv('PRAMNOS_LOG_MAX_SIZE=4096');

        try {
            // Act & Assert
            $this->assertSame(4096, Logger::getMaxSize());
        } finally {
            putenv('PRAMNOS_LOG_MAX_SIZE');
        }

        // And with nothing set at all, the documented default
        $this->assertSame(Logger::DEFAULT_MAX_SIZE, Logger::getMaxSize());
        $this->assertSame(Logger::DEFAULT_MAX_BACKUPS, Logger::getMaxBackups());
    }

    /**
     * A backup count from the environment, and garbage in it is ignored.
     *
     * `ctype_digit` rather than a cast: `getenv()` returning `off` would otherwise become
     * `0`, which for the size cap means *never rotate* — a typo silently disabling the
     * thing this whole file exists to make happen.
     */
    public function testGarbageInTheEnvironmentIsIgnored(): void
    {
        // Arrange
        Logger::setMaxSize(null);
        Logger::setMaxBackups(null);
        putenv('PRAMNOS_LOG_MAX_SIZE=off');
        putenv('PRAMNOS_LOG_MAX_BACKUPS=lots');

        try {
            // Act & Assert
            $this->assertSame(Logger::DEFAULT_MAX_SIZE, Logger::getMaxSize());
            $this->assertSame(Logger::DEFAULT_MAX_BACKUPS, Logger::getMaxBackups());
        } finally {
            putenv('PRAMNOS_LOG_MAX_SIZE');
            putenv('PRAMNOS_LOG_MAX_BACKUPS');
        }
    }
}
