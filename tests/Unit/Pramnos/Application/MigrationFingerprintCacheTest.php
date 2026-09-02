<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * The cache that stops every request re-checking the migrations.
 *
 * Keyed on a fingerprint derived from the migration files themselves — their count, the latest
 * timestamp, the cutoff — which is what makes a cache safe here where a time-based one would not
 * be: adding a migration changes the key, so the answer cannot outlive the thing it describes.
 * No lifetime has to be guessed, and there is no window in which the code is ahead of the schema.
 *
 * **APCu is the path that runs in production**, and it had no covered line — because APCu was not
 * installed in the test image, and `apc.enable_cli` defaults to `0` even when it is. The code was
 * fine; the environment could not reach it. Both are fixed in the `Dockerfile`, so these are the
 * first assertions about the branch that actually executes on a live installation.
 *
 * Each CLI process gets its own empty APCu cache, so nothing here carries into another test.
 */
#[CoversClass(Application::class)]
class MigrationFingerprintCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped(
                'APCu is not enabled for the CLI SAPI, so the branch under test is unreachable.'
            );
        }

        Application::forgetVerifiedMigrations();
    }

    protected function tearDown(): void
    {
        Application::forgetVerifiedMigrations();
        parent::tearDown();
    }

    /** An application with the two cache methods reachable. */
    private function application(): object
    {
        return new class extends Application {
            public function __construct() {}

            public function exposeRemember(string $fingerprint): void
            {
                $this->rememberVerifiedFingerprint($fingerprint);
            }

            public function exposeAlreadyVerified(string $fingerprint): bool
            {
                return $this->fingerprintAlreadyVerified($fingerprint);
            }

            public function exposeCacheKey(string $fingerprint): string
            {
                return $this->fingerprintCacheKey($fingerprint);
            }
        };
    }

    /**
     * A fingerprint nobody has verified is not verified.
     *
     * The state of a fresh process, and the answer that makes the check run rather than be
     * skipped. A cache that answered `true` here would let a request proceed against a schema
     * nothing had looked at.
     */
    public function testAnUnknownFingerprintIsNotVerified(): void
    {
        // Act + Assert
        $this->assertFalse($this->application()->exposeAlreadyVerified('never-seen-before'));
    }

    /**
     * Remembering one makes it verified.
     *
     * The round trip, through APCu rather than through the file fallback — which is the whole
     * point of this file existing.
     */
    public function testRememberingAFingerprintMakesItVerified(): void
    {
        // Arrange
        $application = $this->application();

        // Act
        $application->exposeRemember('abc123');

        // Assert
        $this->assertTrue($application->exposeAlreadyVerified('abc123'));
    }

    /**
     * A different fingerprint is a different answer.
     *
     * The property that makes the cache safe: adding a migration changes the fingerprint, so the
     * next request misses and does the real work. A cache that answered for any key once one was
     * stored would be a time-based cache with extra steps — and would report a schema as checked
     * while a new migration sat unapplied.
     */
    public function testADifferentFingerprintIsADifferentAnswer(): void
    {
        // Arrange
        $application = $this->application();
        $application->exposeRemember('before-the-new-migration');

        // Act + Assert
        $this->assertTrue($application->exposeAlreadyVerified('before-the-new-migration'));
        $this->assertFalse(
            $application->exposeAlreadyVerified('after-the-new-migration'),
            'a changed fingerprint read as already verified, so a new migration would be skipped'
        );
    }

    /**
     * `forgetVerifiedMigrations()` clears what was remembered.
     *
     * For a test that rewrites migration files inside one process, and for a deploy that swaps the
     * directory under a long-running worker — the key invalidates itself when the files change,
     * but both of those want it gone now rather than at the next key change.
     */
    public function testForgettingClearsWhatWasRemembered(): void
    {
        // Arrange
        $application = $this->application();
        $application->exposeRemember('abc123');
        $this->assertTrue($application->exposeAlreadyVerified('abc123'));

        // Act
        Application::forgetVerifiedMigrations();

        // Assert
        $this->assertFalse($application->exposeAlreadyVerified('abc123'));
    }

    /**
     * The key is namespaced by the application root.
     *
     * Several applications share one PHP-FPM pool, and APCu is per pool rather than per
     * application. Without the namespace they would answer each other's question — and the answer
     * "your migrations are verified" from a different application's schema is the worst possible
     * one to get right by accident.
     */
    public function testTheKeyIsNamespacedByTheApplicationRoot(): void
    {
        // Act
        $key = $this->application()->exposeCacheKey('abc123');

        // Assert
        $this->assertStringStartsWith('pramnos:migrations:', $key);
        $this->assertStringContainsString(md5(defined('ROOT') ? (string) ROOT : ''), $key);
        $this->assertStringEndsWith(':abc123', $key);
    }

    /**
     * Forgetting removes only this application's entries.
     *
     * `APCUIterator` over the namespace prefix rather than `apcu_clear_cache()`, for the same
     * shared-pool reason: clearing the whole cache would drop every other application's entries —
     * sessions, page caches, rate-limit counters — as a side effect of one migration check.
     */
    public function testForgettingLeavesAnotherApplicationsEntriesAlone(): void
    {
        // Arrange — something belonging to somebody else
        apcu_store('another-application:something', 'keep me', 60);
        $this->application()->exposeRemember('abc123');

        // Act
        Application::forgetVerifiedMigrations();

        // Assert
        $this->assertSame(
            'keep me',
            apcu_fetch('another-application:something'),
            'clearing the migration cache took an unrelated entry with it'
        );

        apcu_delete('another-application:something');
    }
}
