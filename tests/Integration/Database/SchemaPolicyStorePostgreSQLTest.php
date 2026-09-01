<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

/**
 * The same policy calls against the container that has TimescaleDB, so they take the native path.
 *
 * Not a formality. `addRetentionPolicy()`, `policyInterval()` and `removeRetentionPolicy()` each have
 * two entirely separate implementations behind one signature — `add_retention_policy()` and a row in
 * `timescaledb_information.jobs` on one side, a row in `pramnos.framework_policies` on the other —
 * and the contract is that a caller cannot tell which it got. Running the same assertions down both
 * is the only thing that establishes that.
 *
 * `policyInterval()`'s Timescale branch also has a detail no software store has: the interval lives in
 * a JSON `config` under a key TimescaleDB has renamed across versions, so it tries `drop_after`,
 * `compress_after` and `older_than` in turn. This lane is what runs that.
 *
 * The duplicate-registration test skips itself here, and says why in its own message: idempotence is
 * the extension's problem on this path, and the duplicate-row defect it guards was in the software
 * store.
 */
class SchemaPolicyStorePostgreSQLTest extends SchemaPolicyStoreTest
{
    /**
     * PostgreSQL with TimescaleDB.
     *
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }
}
