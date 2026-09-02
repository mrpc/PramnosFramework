<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

/**
 * The same tracking against real PostgreSQL, which is the lane this class was written for.
 *
 * The upsert is hand-written twice, and this dialect's half — `ON CONFLICT (visitorid) DO UPDATE …
 * RETURNING logout` — had never been issued against a server. A mistake in it is a silent nothing:
 * tracking fails, the active-visitor list is empty, and nothing raises.
 *
 * `RETURNING` is also the mechanism this dialect uses to read the `logout` flag the upsert deliberately
 * does not touch, so the «an administrator ended this session» path is genuinely different code here
 * rather than the same code with a different quote character.
 */
class SessionTrackingUpsertPostgreSQLTest extends SessionTrackingUpsertTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
