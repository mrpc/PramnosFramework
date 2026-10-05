<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * The cache that stops every request asking the database whether its migrations are up to date.
 *
 * Twenty-four uncovered statements, and a cache is the one kind of code where being uncovered is
 * genuinely dangerous rather than merely untidy: a broken cache still returns *an* answer, and the
 * wrong answer here leaves the schema behind the code — which is the single failure the
 * auto-migration check exists to prevent.
 *
 * The design turns on one decision, and it is the thing worth asserting. **The key is the
 * fingerprint**, derived from the migration files themselves — their count and the latest
 * timestamp. A plain time-based cache would be wrong: after a deploy that adds a migration, a stale
 * "all applied" leaves the code ahead of the schema for however long the lifetime happens to be. By
 * keying on a description of the files, the cache invalidates *itself* — a deploy that adds a
 * migration changes the key, the next request misses, and no lifetime has to be guessed. So the
 * tests assert both that a remembered fingerprint is found and that a *different* one is not,
 * because only the pair together says the cache cannot outlive what it describes.
 *
 * Two smaller properties, both of which only matter on a real machine. The key is namespaced by the
 * application root, so several applications sharing one PHP-FPM pool do not answer each other's
 * question. And the marker file is written to a temporary name and moved into place, so a
 * concurrent reader never sees a half-written one — a partially written marker is a request that
 * skips the migration check on the strength of a file nobody finished writing.
 *
 * No database and no APCu required: with neither cache available the check answers false and runs as
 * it always did, which is the third path and the one every developer machine takes.
 */
#[CoversClass(Application::class)]
class AutoMigrationFingerprintTest extends TestCase
{
    private object $application;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $this->application = new class extends Application {
            public function __construct()
            {
            }

            public function callAlreadyVerified(string $fingerprint): bool
            {
                return $this->fingerprintAlreadyVerified($fingerprint);
            }

            public function callRemember(string $fingerprint): void
            {
                $this->rememberVerifiedFingerprint($fingerprint);
            }

            public function callCacheKey(string $fingerprint): string
            {
                return $this->fingerprintCacheKey($fingerprint);
            }

            public function callCacheFile(string $fingerprint): ?string
            {
                return $this->fingerprintCacheFile($fingerprint);
            }
        };

        Application::forgetVerifiedMigrations();
    }

    protected function tearDown(): void
    {
        Application::forgetVerifiedMigrations();
    }

    /** A fingerprint of the shape `runAutoMigrations()` builds. */
    private function fingerprint(int $count, string $latest = '2026_09_01_000001'): string
    {
        return '__fw_auto_' . $count . '_' . $latest;
    }

    // ── Remembering and finding ───────────────────────────────────────────────

    /**
     * An unverified fingerprint is not found, and a remembered one is.
     *
     * The plain round trip, and the reason it is one test rather than two: "not found" alone is
     * satisfied by a cache that never stores anything — which is a working system that simply never
     * gets faster — and "found" alone is satisfied by one that answers true for everything, which
     * is a system that stops checking its migrations entirely.
     */
    public function testARememberedFingerprintIsFoundAndAnUnknownOneIsNot(): void
    {
        // Arrange
        $fingerprint = $this->fingerprint(41);

        // Act & Assert
        $this->assertFalse(
            $this->application->callAlreadyVerified($fingerprint),
            'a fingerprint nobody stored was reported as verified'
        );

        $this->application->callRemember($fingerprint);

        $this->assertTrue(
            $this->application->callAlreadyVerified($fingerprint),
            'the cache stored nothing, so every request still asks the database'
        );
    }

    /**
     * A different fingerprint is a miss — which is the whole safety argument.
     *
     * Adding a migration changes the count and the latest timestamp, so it changes the key. This is
     * what makes the cache safe where a time-based one would not be: there is no window in which a
     * deploy has added a migration and a cached "all applied" is still being served.
     */
    public function testAddingAMigrationChangesTheKeyAndMisses(): void
    {
        // Arrange — 41 migrations verified
        $this->application->callRemember($this->fingerprint(41));

        // Act & Assert — the 42nd arrives
        $this->assertFalse(
            $this->application->callAlreadyVerified($this->fingerprint(42)),
            'a new migration was served a cached "already verified", leaving the schema behind'
        );

        // A newer timestamp with the same count misses too: a migration can be replaced.
        $this->assertFalse(
            $this->application->callAlreadyVerified($this->fingerprint(41, '2026_09_02_000001')),
            'a replaced migration was served a stale answer'
        );
    }

    /**
     * Forgetting clears what was remembered.
     *
     * The key invalidates itself when the files change, so this exists for the two cases where it
     * cannot: a test that rewrites migration files inside one process, and a deploy that swaps the
     * directory under a long-running worker. Both want the answer gone now rather than at the next
     * key change.
     */
    public function testForgettingClearsWhatWasRemembered(): void
    {
        // Arrange
        $fingerprint = $this->fingerprint(41);
        $this->application->callRemember($fingerprint);
        $this->assertTrue($this->application->callAlreadyVerified($fingerprint));

        // Act
        Application::forgetVerifiedMigrations();

        // Assert
        $this->assertFalse(
            $this->application->callAlreadyVerified($fingerprint),
            'a swapped migration directory would go unnoticed by a long-running worker'
        );
    }

    /** Forgetting when nothing was remembered is not an error. */
    public function testForgettingNothingIsHarmless(): void
    {
        // Act & Assert — every boot calls this path when the cache is empty
        Application::forgetVerifiedMigrations();
        $this->assertFalse($this->application->callAlreadyVerified($this->fingerprint(1)));
    }

    // ── Where it is kept ──────────────────────────────────────────────────────

    /**
     * The key is namespaced by the application root.
     *
     * Several applications can share one PHP-FPM pool, and APCu is per pool rather than per
     * application. Without the namespace, application A's "41 migrations verified" answers
     * application B's question — and B's schema is then behind its code, with the check reporting
     * that everything is fine.
     */
    public function testTheKeyIsNamespacedByTheApplicationRoot(): void
    {
        // Act
        $key = $this->application->callCacheKey($this->fingerprint(41));

        // Assert
        $this->assertStringStartsWith('pramnos:migrations:', $key);
        $this->assertStringContainsString(md5(defined('ROOT') ? ROOT : ''), $key);
        $this->assertStringEndsWith($this->fingerprint(41), $key);

        // Two fingerprints under one root differ, so the namespace has not swallowed the key.
        $this->assertNotSame($key, $this->application->callCacheKey($this->fingerprint(42)));
    }

    /**
     * The marker file lives under the application's own writable directory, named by fingerprint.
     *
     * The name carries the fingerprint, so the content is irrelevant and the file is a marker —
     * which is why two different fingerprints must not resolve to one path.
     */
    public function testTheMarkerFileIsNamedByFingerprint(): void
    {
        // Act
        $file = $this->application->callCacheFile($this->fingerprint(41));

        // Assert
        $this->assertNotNull($file);
        $this->assertStringContainsString('migrations', $file);
        $this->assertNotSame(
            $file,
            $this->application->callCacheFile($this->fingerprint(42)),
            'two fingerprints share one marker, so the cache cannot tell them apart'
        );
    }

    /**
     * The marker is moved into place, leaving no half-written file behind.
     *
     * Written to a temporary name carrying the process id and renamed, because `rename()` within a
     * filesystem is atomic and `file_put_contents()` is not. A concurrent reader that found a
     * partially written marker would skip the migration check on the strength of a file nobody had
     * finished writing.
     */
    public function testTheMarkerIsMovedIntoPlaceWithNoTemporaryLeftBehind(): void
    {
        // Arrange
        $fingerprint = $this->fingerprint(41);
        $file = (string) $this->application->callCacheFile($fingerprint);

        // Act
        $this->application->callRemember($fingerprint);

        // Assert — the APCu path writes no file at all, so this only asserts when a file is used
        if (!is_file($file)) {
            $this->assertTrue(
                function_exists('apcu_enabled') && apcu_enabled(),
                'no marker file was written and no APCu is available to hold one'
            );

            return;
        }

        $leftovers = glob($file . '.*') ?: [];
        $this->assertSame([], $leftovers, 'a temporary marker was left behind: ' . implode(', ', $leftovers));
    }

    // ── Never taking a request down ───────────────────────────────────────────

    /**
     * `migrate()` swallows everything, because it is best-effort infrastructure.
     *
     * It runs on the way into a request. An exception escaping it would turn a migration problem —
     * or a database that is briefly unreachable — into a blank page on every route at once, which
     * is a far worse outage than a schema that is one migration behind. The failure is logged
     * instead, where a deploy can find it.
     */
    public function testMigrateNeverThrows(): void
    {
        // Arrange — an application with no usable settings, so the work inside cannot succeed
        $broken = new class extends Application {
            public function __construct()
            {
                $this->applicationInfo = ['features' => ['auth']];
            }

            protected function runAutoMigrations(): void
            {
                throw new \RuntimeException('the migration table is unreachable');
            }
        };

        // Act & Assert — the assertion is that nothing escapes
        $broken->migrate();
        $this->assertTrue(true, 'migrate() let an exception reach the request');
    }
}
