<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\FeatureRegistry;

/**
 * Unit tests for Application::filterMigrationDirsByEnabledFeatures().
 *
 * WHAT: the pure directory-level feature-gating used by auto-migrations.
 * WHY: framework migrations live in per-feature sub-directories
 *      (`database/migrations/framework/{feature}/`).  Auto-run must skip a
 *      feature's migrations when the application has not enabled that feature —
 *      but must NEVER skip a directory that is not a registered framework
 *      feature (fail-open), so test fixtures and ad-hoc directories keep
 *      running exactly as before.  This is the core invariant that lets new
 *      authserver/auth/queue migrations run only where the feature is on,
 *      without breaking any existing installation or test.
 *
 * The gating rule under test:
 *   - directory name is NOT a known feature      → kept (fail-open)
 *   - directory name IS a known, ENABLED feature → kept
 *   - directory name IS a known, DISABLED feature→ skipped
 *   - 'core' is always enabled                    → kept
 *
 * FeatureRegistry is static global state, so reset() runs in setUp/tearDown to
 * guarantee isolation between test methods.
 */
#[CoversClass(Application::class)]
class MigrationFeatureGatingTest extends TestCase
{
    /**
     * Anonymous subclass exposing the protected filter method under test.
     * new Application('test_app') avoids the heavy getInstance() bootstrap.
     *
     * @param string[] $dirs
     * @return string[]
     */
    private function makeApp(): Application
    {
        // A minimal Application instance exposing the two protected methods
        // under test. new Application('test_app') avoids the heavy
        // getInstance() bootstrap.
        return new class ('test_app') extends Application {
            /** @param string[] $dirs @return string[] */
            public function exposeFilter(array $dirs): array
            {
                return $this->filterMigrationDirsByEnabledFeatures($dirs);
            }

            /** @return string[] */
            public function exposeFrameworkDirs(): array
            {
                return $this->getFrameworkMigrationDirs();
            }
        };
    }

    /**
     * @param string[] $dirs
     * @return string[]
     */
    private function filter(array $dirs): array
    {
        return $this->makeApp()->exposeFilter($dirs);
    }

    protected function setUp(): void
    {
        // Guarantee a clean-but-populated registry (built-ins registered, none enabled).
        FeatureRegistry::reset();
        FeatureRegistry::initDefaults();
    }

    protected function tearDown(): void
    {
        // Do not leak enabled-state into other suites.
        FeatureRegistry::reset();
    }

    /**
     * A directory whose basename is not a registered feature must always be
     * kept — fail-open guarantees test fixtures / ad-hoc dirs are never gated.
     */
    public function testUnknownFeatureDirectoryIsAlwaysKept(): void
    {
        // Act
        $result = $this->filter(['/app/database/migrations/framework/notafeature']);

        // Assert — unknown name is never a gate, so it survives.
        $this->assertSame(['/app/database/migrations/framework/notafeature'], $result);
    }

    /**
     * A known feature that has been enabled via loadFromConfig() must be kept.
     */
    public function testKnownEnabledFeatureDirectoryIsKept(): void
    {
        // Arrange — enable the built-in 'authserver' feature.
        FeatureRegistry::loadFromConfig(['authserver']);

        // Act
        $result = $this->filter(['/x/framework/authserver']);

        // Assert
        $this->assertSame(['/x/framework/authserver'], $result);
    }

    /**
     * A known feature that is NOT enabled must be skipped — this is the whole
     * point of the gating: authserver migrations do not run where authserver
     * is off.
     */
    public function testKnownDisabledFeatureDirectoryIsSkipped(): void
    {
        // Arrange — 'authserver' is known (built-in) but left disabled.

        // Act
        $result = $this->filter(['/x/framework/authserver']);

        // Assert — the only candidate is gated out, leaving an empty list.
        $this->assertSame([], $result);
    }

    /**
     * The 'core' feature is always enabled regardless of config, so its
     * directory must always be kept.
     */
    public function testCoreFeatureDirectoryIsAlwaysKept(): void
    {
        // Act — no feature enabled explicitly, yet core is implicitly on.
        $result = $this->filter(['/x/framework/core']);

        // Assert
        $this->assertSame(['/x/framework/core'], $result);
    }

    /**
     * A mixed list must return exactly the active subset, re-indexed from 0
     * (array_values), so downstream numeric-index consumers are unaffected.
     */
    public function testMixedListReturnsReindexedActiveSubset(): void
    {
        // Arrange — enable only 'auth' among the known features.
        FeatureRegistry::loadFromConfig(['auth']);

        $dirs = [
            '/x/framework/auth',        // known + enabled  → keep
            '/x/framework/authserver',  // known + disabled → drop
            '/x/framework/queue',       // known + disabled → drop
            '/x/framework/customthing', // unknown          → keep (fail-open)
            '/x/framework/core',        // always enabled   → keep
        ];

        // Act
        $result = $this->filter($dirs);

        // Assert — order preserved for survivors, keys reindexed to 0..n.
        $this->assertSame(
            [
                '/x/framework/auth',
                '/x/framework/customthing',
                '/x/framework/core',
            ],
            $result,
            'Only enabled-known + unknown + core directories survive, reindexed'
        );
    }

    /**
     * An empty input yields an empty output (no crash, no defaults injected).
     */
    public function testEmptyInputYieldsEmptyOutput(): void
    {
        // Act & Assert
        $this->assertSame([], $this->filter([]));
    }

    // -----------------------------------------------------------------------
    // End-to-end against the REAL framework migration directories
    // (filesystem only — no DB, no migration execution)
    // -----------------------------------------------------------------------

    /**
     * With 'authserver' disabled, getFrameworkMigrationDirs() must NOT include
     * the real database/migrations/framework/authserver directory — proving the
     * gate is wired into the actual auto-run directory resolution, not just the
     * pure helper.
     */
    public function testRealFrameworkDirsExcludeDisabledAuthserver(): void
    {
        // Arrange — built-ins registered, authserver left disabled.

        // Act
        $dirs      = $this->makeApp()->exposeFrameworkDirs();
        $basenames = array_map('basename', $dirs);

        // Assert — authserver present on disk but gated out.
        $this->assertNotContains(
            'authserver',
            $basenames,
            'Disabled authserver directory must be excluded from auto-run dirs'
        );
    }

    /**
     * With 'authserver' enabled, the real authserver directory must appear in
     * getFrameworkMigrationDirs() — confirming the gate opens when the feature
     * is turned on in app.php.
     */
    public function testRealFrameworkDirsIncludeEnabledAuthserver(): void
    {
        // Arrange — enable authserver as an app would via app.php features.
        FeatureRegistry::loadFromConfig(['authserver']);

        // Act
        $dirs      = $this->makeApp()->exposeFrameworkDirs();
        $basenames = array_map('basename', $dirs);

        // Assert — the on-disk authserver directory is now included.
        $this->assertContains(
            'authserver',
            $basenames,
            'Enabled authserver directory must be included in auto-run dirs'
        );
    }
}
