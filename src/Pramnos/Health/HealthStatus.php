<?php

namespace Pramnos\Health;

/**
 * Possible outcomes of a health check.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
enum HealthStatus: string
{
    /** All systems nominal. */
    case Ok       = 'ok';

    /**
     * Correct here, and would not be everywhere — worth saying, not worth waking anybody.
     *
     * The category `degraded` was being borrowed for, and the borrowing had a cost. A
     * single-server installation keeps its sessions in local files, which is right, and
     * the check that reports it says so in its own doc-block: *"a single-server
     * installation is not broken and must not be paged for"*. Then `degraded` answered
     * **503**, which is a page — so the check did the one thing it documented itself as
     * avoiding, to the majority case it named, and an uptime monitor pointed at
     * `/health/check` alerted permanently on a correct installation.
     *
     * `notice` appears in the JSON, in the table and on the badge, and **does not change
     * the HTTP code**. Use it where the answer is "this is fine, and here is what you
     * would have to change before a second server": the detail is the value, not the
     * severity.
     *
     * Not for something that is wrong and tolerable — that is still `degraded`. The test
     * is whether there is an installation where this exact answer is the correct one.
     */
    case Notice   = 'notice';

    /** Partially degraded — the application is running but at reduced capacity. */
    case Degraded = 'degraded';

    /** Service is down or unavailable. */
    case Down     = 'down';

    /**
     * Returns the worst (most severe) of two statuses.
     *
     * Severity order: Ok < Notice < Degraded < Down.
     */
    public function worst(HealthStatus $other): HealthStatus
    {
        $rank = [
            self::Ok->value       => 0,
            self::Notice->value   => 1,
            self::Degraded->value => 2,
            self::Down->value     => 3,
        ];

        return $rank[$this->value] >= $rank[$other->value] ? $this : $other;
    }

    /**
     * Should a monitor be left alone?
     *
     * The single place that decides, because there were three — the JSON endpoint, the
     * flattened endpoint and the console's exit code — and a sentence in a doc-block
     * saying a check "must not be paged for" enforced none of them.
     *
     * `ok` and `notice` are healthy. Everything else is not.
     */
    public function isHealthy(): bool
    {
        return $this === self::Ok || $this === self::Notice;
    }
}
