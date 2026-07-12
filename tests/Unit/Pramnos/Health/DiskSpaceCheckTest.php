<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Health\Checks\DiskSpaceCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthStatus;

/**
 * Unit tests for DiskSpaceCheck.
 *
 * The check wraps disk_free_space()/disk_total_space() and returns
 * HealthCheckResult::ok, ::degraded, or ::down depending on thresholds.
 * It must also handle non-existent paths gracefully (false return from both
 * disk_*_space() functions) and never let any Throwable propagate.
 */
#[CoversClass(DiskSpaceCheck::class)]
class DiskSpaceCheckTest extends TestCase
{
    /**
     * getName() must return 'disk_space' so the health endpoint and dashboard
     * can reference the check by a predictable, stable key. Changing this string
     * is a breaking change for any caller that reads the JSON output by key.
     */
    public function testGetNameReturnsDiskSpace(): void
    {
        // Arrange
        $check = new DiskSpaceCheck();

        // Act + Assert
        $this->assertSame('disk_space', $check->getName());
    }

    /**
     * run() on a valid path (current working directory) must return a
     * HealthCheckResult. The exact status depends on actual disk usage, so this
     * test only verifies that the method returns without throwing and produces
     * a HealthCheckResult with the correct name key.
     */
    public function testRunOnValidPathReturnsHealthCheckResult(): void
    {
        // Arrange — use '.', which always has disk stats in the Docker container
        $check = new DiskSpaceCheck('.');

        // Act
        $result = $check->run();

        // Assert
        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame('disk_space', $result->name);
    }

    /**
     * When disk_free_space() returns false (non-existent or inaccessible path),
     * run() must return HealthCheckResult::down with a status of Down rather
     * than throwing or returning null. This covers the false-return guard at
     * lines 42-44 that protects the subsequent arithmetic from operating on false.
     */
    public function testRunReturnsDownForNonExistentPath(): void
    {
        // Arrange — a path that definitely does not exist on the filesystem;
        // disk_free_space('/nonexistent/...') returns false with an E_WARNING
        $check = new DiskSpaceCheck('/nonexistent_pramnos_diskcheck_' . uniqid());

        // Act — the false-return guard (lines 42-44) must fire
        $result = $check->run();

        // Assert — must return a Down result, not throw
        $this->assertInstanceOf(HealthCheckResult::class, $result,
            'run() must return a HealthCheckResult even for invalid paths');
        $this->assertSame(HealthStatus::Down, $result->status,
            'An inaccessible path must produce a Down result');
        $this->assertSame('disk_space', $result->name);
    }

    /**
     * When free space is below the "down" threshold, run() must return a result
     * with status Down. Achieved by setting an unreachably high downThresholdMb
     * so that any real disk appears critically low.
     */
    public function testRunReturnsDownWhenBelowDownThreshold(): void
    {
        // Arrange — threshold = 2 PB (no real disk exceeds this)
        $check = new DiskSpaceCheck('.', PHP_INT_MAX, PHP_INT_MAX);

        // Act
        $result = $check->run();

        // Assert
        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame(HealthStatus::Down, $result->status,
            'Free space below the down threshold must produce a Down result');
    }

    /**
     * When free space is between the degraded and down thresholds, run() must
     * return a result with status Degraded. Achieved by setting
     * degradedThresholdMb = PHP_INT_MAX and downThresholdMb = 1 so that any
     * disk with ≥ 1 MB free (but less than PHP_INT_MAX MB) is Degraded.
     */
    public function testRunReturnsDegradedWhenBetweenThresholds(): void
    {
        // Arrange — down = 1 MB; degraded = absurdly high; real disk is in between
        $check = new DiskSpaceCheck('.', PHP_INT_MAX, 1);

        // Act
        $result = $check->run();

        // Assert
        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame(HealthStatus::Degraded, $result->status,
            'Free space below degraded threshold (but above down) must produce Degraded');
    }

    /**
     * When free space is above both thresholds, run() must return a result
     * with status Ok. Verified by setting both thresholds to 0 MB so any
     * available disk registers as healthy.
     */
    public function testRunReturnsOkWhenAboveThresholds(): void
    {
        // Arrange — both thresholds = 0 so even 1 MB free is "ok"
        $check = new DiskSpaceCheck('.', 0, 0);

        // Act
        $result = $check->run();

        // Assert
        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame(HealthStatus::Ok, $result->status,
            'Free space above both thresholds must produce an Ok result');
    }

    /**
     * The details array in the result must contain free_mb, total_mb, and
     * used_pct keys so the dashboard can display disk usage statistics.
     */
    public function testRunDetailsContainsRequiredKeys(): void
    {
        // Arrange
        $check = new DiskSpaceCheck('.', 0, 0);

        // Act
        $result = $check->run();

        // Assert — three metadata keys are required
        $this->assertArrayHasKey('free_mb',  $result->details, 'details must include free_mb');
        $this->assertArrayHasKey('total_mb', $result->details, 'details must include total_mb');
        $this->assertArrayHasKey('used_pct', $result->details, 'details must include used_pct');
        $this->assertIsInt($result->details['free_mb'],   'free_mb must be an integer');
        $this->assertIsInt($result->details['total_mb'],  'total_mb must be an integer');
        $this->assertIsFloat($result->details['used_pct'], 'used_pct must be a float');
    }
}
