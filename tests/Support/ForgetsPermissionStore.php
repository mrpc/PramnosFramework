<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

/**
 * Clears the permission store `Pramnos\Auth\Permissions` remembered.
 *
 * That class is reached through a process-wide singleton and caches which store
 * it found — `authserver.permissions`, the legacy table, or neither. Caching a
 * *found* store is deliberate: a table does not disappear underneath a running
 * process, and re-asking the catalogue on every permission question would cost
 * two round trips each time.
 *
 * A test suite breaks that assumption. A test that creates the store and drops
 * it again leaves the singleton pointing at a table that is no longer there, and
 * the next test to ask gets a write against a missing relation — a failure with
 * nothing to do with the code under test.
 *
 * So any test that creates or drops a permission store calls this afterwards.
 * The alternative — never caching — would make the framework pay for the test
 * suite's habits, which is the wrong way round.
 */
trait ForgetsPermissionStore
{
    /**
     * Make the shared Permissions instance re-detect its store.
     */
    protected function forgetPermissionStore(): void
    {
        try {
            $permissions = \Pramnos\Auth\Permissions::getInstance();
            $property    = new \ReflectionProperty(
                \Pramnos\Auth\Permissions::class,
                '_store'
            );
            $property->setValue($permissions, null);
        } catch (\Throwable) {
            // Nothing to forget.
        }
    }
}
