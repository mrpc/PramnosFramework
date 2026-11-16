<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Metrics\Metrics;

/**
 * Whether the application's own declared metrics are computing on time: a metric late against
 * its own `expect()`, a run that failed, or a recent period with no figure. **Degraded**, naming
 * which — a source that breaks today is otherwise found only when a report's number looks wrong.
 * Nothing to say with no metric declared.
 */
class MetricsHealthCheck implements HealthCheck
{
    public function getName(): string
    {
        return 'metrics_health';
    }

    public function run(): HealthCheckResult
    {
        try {
            if (Metrics::all() === []) {
                return HealthCheckResult::ok($this->getName(), 'No metric is declared');
            }
            $problems = [];
            foreach (Metrics::health() as $metric => $grains) {
                foreach ($grains as $grain => $health) {
                    if ($health['error'] !== null) {
                        $problems[] = $metric . '@' . $grain . ': ' . $health['error'];
                    } elseif ($health['late']) {
                        $problems[] = $metric . '@' . $grain . ': late';
                    } elseif ($health['missing'] !== []) {
                        $problems[] = $metric . '@' . $grain . ': missing ' . implode(', ', $health['missing']);
                    }
                }
            }
        } catch (\Throwable $e) {
            return HealthCheckResult::down($this->getName(), 'Cannot read the metrics\' health', ['error' => $e->getMessage()]);
        }

        return $problems === []
            ? HealthCheckResult::ok($this->getName(), 'Every declared metric is computing on time')
            : HealthCheckResult::degraded($this->getName(), (string) count($problems) . ' metric(s) late, failed, or missing a recent period', ['problems' => $problems]);
    }
}
