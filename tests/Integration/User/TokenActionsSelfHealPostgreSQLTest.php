<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

/**
 * The same repair against real PostgreSQL, where it is a much larger piece of work.
 *
 * MySQL adds four columns and two indexes. This branch does that and then turns `action_time` into a
 * real time dimension: back-fills it from the legacy `servertime` integer, installs a `plpgsql`
 * function and a `BEFORE INSERT OR UPDATE` trigger that keep the two in step in **both** directions,
 * and only then makes the column `NOT NULL`.
 *
 * Which is why this lane exists and is not symmetry for its own sake: two of the four tests below are
 * about this branch alone, and the ordering risk inside it — `SET NOT NULL` before the back-fill fails
 * on every existing row, after three `ALTER`s have already gone through — has no MySQL equivalent.
 */
class TokenActionsSelfHealPostgreSQLTest extends TokenActionsSelfHealTest
{
    /**
     * PostgreSQL, in the timescaledb container.
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
            // The parent's prefix, repeated because this method replaces that array rather than
            // adding to it — leaving it out pointed this lane at the real `tokenactions`, where the
            // columns already exist and the repair under test never runs. The tests then passed for
            // the wrong reason, which is the failure mode a second lane is supposed to remove.
            'prefix'   => 'heal_',
        ];
    }
}
