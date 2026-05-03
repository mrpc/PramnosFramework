<?php

namespace Pramnos\Health;

/**
 * Possible outcomes of a health check.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Health
 */
enum HealthStatus: string
{
    /** All systems nominal. */
    case Ok       = 'ok';

    /** Partially degraded — the application is running but at reduced capacity. */
    case Degraded = 'degraded';

    /** Service is down or unavailable. */
    case Down     = 'down';

    /**
     * Returns the worst (most severe) of two statuses.
     *
     * Severity order: Ok < Degraded < Down.
     */
    public function worst(HealthStatus $other): HealthStatus
    {
        $rank = [
            self::Ok->value       => 0,
            self::Degraded->value => 1,
            self::Down->value     => 2,
        ];

        return $rank[$this->value] >= $rank[$other->value] ? $this : $other;
    }
}
