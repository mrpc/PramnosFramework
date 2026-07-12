<?php

namespace Pramnos\Health\Checks;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Checks current PHP memory usage against the configured memory_limit.
 *
 * Reports degraded when usage exceeds $degradedPct percent of the limit and
 * down when it exceeds $downPct percent.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class MemoryLimitCheck implements HealthCheck
{
    /**
     * @param float $degradedPct Usage percentage (0–100) above which check is Degraded.
     * @param float $downPct     Usage percentage (0–100) above which check is Down.
     */
    public function __construct(
        private readonly float $degradedPct = 75.0,
        private readonly float $downPct     = 90.0
    ) {
    }

    public function getName(): string
    {
        return 'memory_limit';
    }

    public function run(): HealthCheckResult
    {
        try {
            $limitBytes = $this->parseMemoryLimit(ini_get('memory_limit') ?: '-1');
            $usedBytes  = memory_get_usage(true);

            if ($limitBytes <= 0) {
                // No memory limit configured — report as OK with a note
                return HealthCheckResult::ok(
                    $this->getName(),
                    'No memory limit configured',
                    ['used_mb' => round($usedBytes / 1_048_576, 2)]
                );
            }

            $usedMb  = round($usedBytes / 1_048_576, 2);
            $limitMb = round($limitBytes / 1_048_576, 2);
            $pct     = round($usedBytes / $limitBytes * 100, 1);

            $details = [
                'used_mb'  => $usedMb,
                'limit_mb' => $limitMb,
                'used_pct' => $pct,
            ];

            if ($pct >= $this->downPct) {
                return HealthCheckResult::down(
                    $this->getName(),
                    "{$pct}% of memory limit used ({$usedMb} / {$limitMb} MB)",
                    $details
                );
            }

            if ($pct >= $this->degradedPct) {
                return HealthCheckResult::degraded(
                    $this->getName(),
                    "High memory usage: {$pct}% ({$usedMb} / {$limitMb} MB)",
                    $details
                );
            }

            return HealthCheckResult::ok(
                $this->getName(),
                "{$usedMb} MB / {$limitMb} MB ({$pct}%)",
                $details
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::down(
                $this->getName(),
                'Memory check failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Converts php.ini memory_limit strings like '128M', '1G' to bytes.
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
        }

        $unit  = strtoupper(substr(trim($limit), -1));
        $value = (int) $limit;

        return match ($unit) {
            'G'     => $value * 1_073_741_824,
            'M'     => $value * 1_048_576,
            'K'     => $value * 1_024,
            default => $value,
        };
    }
}
