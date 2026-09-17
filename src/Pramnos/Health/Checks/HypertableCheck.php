<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Database\ContinuousAggregateRegistry;
use Pramnos\Database\Database;
use Pramnos\Database\HypertableRegistry;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Is the time-series storage the shape it was declared to be?
 *
 * Every declaration in {@see HypertableRegistry} is a claim about the database, and until
 * now nothing read it back. `timescale:ensure` printed a tick for each step whether or not
 * the step had worked, so a table could be declared a hypertable, reported as converted,
 * and be an ordinary table — on every run, for ever.
 *
 * **Nothing breaks, which is why it needs a check.** Rows are written and read exactly as
 * before, every test passes and every screen works. What is missing is chunking,
 * compression and a table that stays usable holding years rather than days — discovered
 * when it is far too large to convert quickly. For an application whose proposition is that
 * the numbers outlive the platforms, a metric store that is quietly an ordinary table is
 * the most expensive thing that can possibly be working.
 *
 * It also answers the version question, because the answer is not PostgreSQL's version and
 * is not in any file: `percentile_cont(…) WITHIN GROUP (…)` and `COUNT(DISTINCT …)` cannot
 * be maintained incrementally, so a continuous aggregate containing them is accepted by a
 * newer TimescaleDB and refused by an older one — 2.30.0 and 2.26.4 respectively, and
 * 2.26.4 is the newest package installable on Debian 11 with PostgreSQL 17. The framework
 * falls back to a plain materialised view with the same columns, refreshed by the
 * PolicyEngine daemon rather than a background job. That is a working arrangement and a
 * different one, so it is reported rather than hidden.
 *
 * **Degraded, not down.** The application is up; its storage is not the shape it asked for.
 * Reporting `down` would page somebody for a site that is serving every request, and a
 * check that cries wolf is a check that gets muted.
 *
 * @see \Pramnos\Health\Checks\CacheBackendCheck for the same reasoning about a silent
 *      fallback that nobody was told about.
 */
class HypertableCheck implements HealthCheck
{
    private ?Database $database;

    /**
     * Nothing is resolved here: a health check is registered on every request and run on
     * almost none, and asking the catalogue at registration would make every page pay for
     * a check nobody asked for.
     */
    public function __construct(?Database $database = null)
    {
        $this->database = $database;
    }

    public function getName(): string
    {
        return 'hypertables';
    }

    public function run(): HealthCheckResult
    {
        try {
            $database = $this->database ?? Database::getInstance();
            $schema   = $database->schema();
        } catch (\Throwable $ex) {
            return HealthCheckResult::down($this->getName(), 'Cannot read the schema', [
                'error' => $ex->getMessage(),
            ]);
        }

        $capabilities = $database->capabilities();
        if (!$capabilities->hasTimescaleDB()) {
            // Not a fault. Every declaration has a documented software equivalent on a
            // backend without the extension — that is what the registry is for — and a
            // check that reported it as a problem would be permanently yellow on two of
            // the three databases this framework supports.
            return HealthCheckResult::ok(
                $this->getName(),
                'No TimescaleDB; declarations run through their software equivalents'
            );
        }

        $version = $capabilities->timescaleVersion();
        $details = ['timescaledb' => $version === '' ? 'unknown' : $version];

        $missing  = [];
        $fellBack = [];

        foreach (array_keys(HypertableRegistry::all()) as $table) {
            try {
                if (!$schema->hasTable($table)) {
                    // Declared but not created yet — a migration that has not run is not
                    // this check's business, and reporting it would make a fresh install
                    // yellow before it has finished setting itself up.
                    continue;
                }
                if (!$schema->hasHypertable($table)) {
                    $missing[] = $table;
                }
            } catch (\Throwable) {
                // An unreadable catalogue is the connectivity check's subject, not this
                // one's. Skipping keeps one failure from being reported twice.
                continue;
            }
        }

        foreach (array_keys(ContinuousAggregateRegistry::all()) as $view) {
            try {
                if (!$schema->hasView($view)) {
                    continue;
                }
                if (!$schema->isContinuousAggregate($view)) {
                    $fellBack[] = $view;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($missing !== []) {
            $details['not_hypertables'] = $missing;
            $details['hint'] = 'run `timescale:ensure` — it now reports the driver error '
                . 'instead of a tick, and exits non-zero';

            if ($fellBack !== []) {
                $details['plain_materialised_views'] = $fellBack;
            }

            return HealthCheckResult::degraded(
                $this->getName(),
                count($missing) . ' declared hypertable(s) are ordinary tables: '
                . implode(', ', $missing),
                $details
            );
        }

        if ($fellBack !== []) {
            $details['plain_materialised_views'] = $fellBack;
            $details['hint'] = 'this TimescaleDB cannot maintain those aggregates '
                . 'incrementally; they refresh through the PolicyEngine daemon instead, '
                . 'so make sure it is running';

            return HealthCheckResult::degraded(
                $this->getName(),
                count($fellBack) . ' aggregate(s) are plain materialised views on '
                . 'TimescaleDB ' . ($version === '' ? 'of unknown version' : $version),
                $details
            );
        }

        return HealthCheckResult::ok(
            $this->getName(),
            'TimescaleDB ' . ($version === '' ? '(version unknown)' : $version)
            . ' — every declaration matches the catalogue',
            $details
        );
    }
}
