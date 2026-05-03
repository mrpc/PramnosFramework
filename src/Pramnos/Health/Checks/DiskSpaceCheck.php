<?php

namespace Pramnos\Health\Checks;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Checks available disk space on the filesystem containing the application.
 *
 * Reports degraded when free space falls below $degradedThresholdMb and
 * down when it falls below $downThresholdMb.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Health
 */
class DiskSpaceCheck implements HealthCheck
{
    /**
     * @param string $path              Filesystem path to check (default: current working dir).
     * @param int    $degradedThresholdMb Free space in MB below which the check is Degraded.
     * @param int    $downThresholdMb    Free space in MB below which the check is Down.
     */
    public function __construct(
        private readonly string $path               = '.',
        private readonly int    $degradedThresholdMb = 500,
        private readonly int    $downThresholdMb     = 100
    ) {
    }

    public function getName(): string
    {
        return 'disk_space';
    }

    public function run(): HealthCheckResult
    {
        try {
            $free  = disk_free_space($this->path);
            $total = disk_total_space($this->path);

            if ($free === false || $total === false) {
                return HealthCheckResult::down($this->getName(), 'Could not read disk space');
            }

            $freeMb  = (int) round($free / 1_048_576);
            $totalMb = (int) round($total / 1_048_576);
            $usedPct = $total > 0 ? round(($total - $free) / $total * 100, 1) : 0.0;

            $details = [
                'free_mb'  => $freeMb,
                'total_mb' => $totalMb,
                'used_pct' => $usedPct,
            ];

            if ($freeMb < $this->downThresholdMb) {
                return HealthCheckResult::down(
                    $this->getName(),
                    "Only {$freeMb} MB free (threshold: {$this->downThresholdMb} MB)",
                    $details
                );
            }

            if ($freeMb < $this->degradedThresholdMb) {
                return HealthCheckResult::degraded(
                    $this->getName(),
                    "Low disk space: {$freeMb} MB free (threshold: {$this->degradedThresholdMb} MB)",
                    $details
                );
            }

            return HealthCheckResult::ok(
                $this->getName(),
                "{$freeMb} MB free ({$usedPct}% used)",
                $details
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::down(
                $this->getName(),
                'Disk space check failed: ' . $e->getMessage()
            );
        }
    }
}
