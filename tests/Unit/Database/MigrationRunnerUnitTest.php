<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * Unit tests for MigrationRunner's topological sort, filtering, and ordering
 * logic. These tests run without a live database — all DB interactions are
 * exercised in the integration test suite.
 *
 * The test stubs follow the YYYY_MM_DD_HHmmss_slug.php filename convention
 * so getSlug() and getTimestamp() can extract metadata from the class name.
 */
#[CoversClass(MigrationRunner::class)]
#[CoversClass(Migration::class)]
class MigrationRunnerUnitTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Migration metadata extraction
    // -----------------------------------------------------------------------

    /**
     * A Migration subclass named after the YYYY_MM_DD_HHmmss_slug convention
     * must expose the slug (part after the timestamp) via getSlug().
     */
    public function testGetSlugExtractsSlugFromTimestampedClassName(): void
    {
        // Arrange – class name: 2024_01_15_120000_create_users_table
        $m = new class extends StubMigration {
            public function getSlug(): string
            {
                // Hardcode so the test doesn't depend on the actual class-name,
                // which varies per PHP version for anonymous classes.
                return parent::slugFromName('2024_01_15_120000_create_users_table');
            }
        };

        // Act
        $slug = $m->getSlug();

        // Assert
        $this->assertSame('create_users_table', $slug);
    }

    /**
     * getSlug() on a plain non-timestamped class name returns the full name
     * lowercased (no timestamp prefix to strip).
     */
    public function testGetSlugReturnsFallbackForNonTimestampedName(): void
    {
        // Arrange
        $m = new class extends StubMigration {
            public function getSlug(): string
            {
                return parent::slugFromName('CreateUsersTable');
            }
        };

        // Act
        $slug = $m->getSlug();

        // Assert – CamelCase is converted to snake_case when there is no timestamp
        $this->assertSame('create_users_table', $slug);
    }

    /**
     * getTimestamp() extracts the YYYY_MM_DD_HHmmss prefix from a timestamped
     * class name and returns it as a string for sorting purposes.
     */
    public function testGetTimestampExtractsDatetimePrefix(): void
    {
        // Arrange
        $m = new class extends StubMigration {
            public function getTimestamp(): ?string
            {
                return parent::timestampFromName('2024_01_15_120000_create_users_table');
            }
        };

        // Act
        $ts = $m->getTimestamp();

        // Assert
        $this->assertSame('2024_01_15_120000', $ts);
    }

    /**
     * getTimestamp() returns null when the class name has no YYYY_MM_DD_HHmmss
     * prefix, so the runner can handle legacy un-timestamped migrations safely.
     */
    public function testGetTimestampReturnsNullForNonTimestampedName(): void
    {
        // Arrange
        $m = new class extends StubMigration {
            public function getTimestamp(): ?string
            {
                return parent::timestampFromName('CreateUsersTable');
            }
        };

        // Act
        $ts = $m->getTimestamp();

        // Assert
        $this->assertNull($ts);
    }

    // -----------------------------------------------------------------------
    // Migration metadata defaults
    // -----------------------------------------------------------------------

    /**
     * New metadata properties must carry safe BC-compatible defaults so that
     * existing migration subclasses (written before Phase 4) continue to work
     * without modification.
     */
    public function testMigrationDefaultsAreBackwardCompatible(): void
    {
        // Arrange
        $m = new StubMigration();

        // Assert
        $this->assertSame('', $m->feature,        'feature defaults to empty string (app migration)');
        $this->assertSame('app', $m->scope,        'scope defaults to "app"');
        $this->assertSame(50, $m->priority,        'priority defaults to 50');
        $this->assertSame([], $m->dependencies,    'dependencies defaults to empty array');
        $this->assertTrue($m->autoExecute,         'autoExecute defaults to true');
    }

    /**
     * $autoExecute can be set to false at runtime; filterAutorun() reads this
     * property directly so it must reflect the assigned value.
     */
    public function testAutoExecuteCanBeSetFalse(): void
    {
        // Arrange
        $m = new StubMigration();

        // Act
        $m->autoExecute = false;

        // Assert
        $this->assertFalse($m->autoExecute, 'autoExecute must reflect the assigned false value');
    }

    // -----------------------------------------------------------------------
    // Topological sort
    // -----------------------------------------------------------------------

    /**
     * When migrations have no dependencies, they are sorted by priority
     * ascending (lower priority number runs first).
     */
    public function testSortByPriorityAscendingWhenNoDependencies(): void
    {
        // Arrange – three migrations with decreasing priority values
        $high   = $this->makeMigration('create_roles_table', priority: 10);
        $medium = $this->makeMigration('create_users_table', priority: 20);
        $low    = $this->makeMigration('create_tokens_table', priority: 30);

        $runner = new MigrationRunner();

        // Act – pass them in reverse order to prove sort is not position-dependent
        $sorted = $runner->sort([$low, $medium, $high]);

        // Assert – highest priority (lowest number) first
        $this->assertSame('create_roles_table',  $sorted[0]->getSlug());
        $this->assertSame('create_users_table',  $sorted[1]->getSlug());
        $this->assertSame('create_tokens_table', $sorted[2]->getSlug());
    }

    /**
     * When priority values are equal, migrations are ordered by their
     * filename timestamp ascending (older datetime = runs first).
     */
    public function testSortByDatetimeWhenPriorityIsTied(): void
    {
        // Arrange – same priority but different timestamps
        $newer = $this->makeMigration('2024_03_01_000000_create_sessions',  priority: 50, timestamp: '2024_03_01_000000');
        $older = $this->makeMigration('2024_01_01_000000_create_settings',  priority: 50, timestamp: '2024_01_01_000000');
        $mid   = $this->makeMigration('2024_02_01_000000_create_logs',      priority: 50, timestamp: '2024_02_01_000000');

        $runner = new MigrationRunner();

        // Act
        $sorted = $runner->sort([$newer, $mid, $older]);

        // Assert – oldest timestamp first
        $this->assertStringContainsString('create_settings',  $sorted[0]->getSlug());
        $this->assertStringContainsString('create_logs',      $sorted[1]->getSlug());
        $this->assertStringContainsString('create_sessions',  $sorted[2]->getSlug());
    }

    /**
     * A migration whose dependencies list contains the slug of another
     * migration must always be placed after that dependency, regardless of
     * priority or timestamp.
     */
    public function testDependencyForcesLaterExecution(): void
    {
        // Arrange – users depends on roles; roles has higher priority number (runs later
        // by default), but the dependency forces roles to precede users anyway
        $users = $this->makeMigration('create_users_table', priority: 10, deps: ['create_roles_table']);
        $roles = $this->makeMigration('create_roles_table', priority: 90);

        $runner = new MigrationRunner();

        // Act
        $sorted = $runner->sort([$users, $roles]);

        // Assert – roles must appear before users
        $this->assertSame('create_roles_table', $sorted[0]->getSlug());
        $this->assertSame('create_users_table', $sorted[1]->getSlug());
    }

    /**
     * A multi-level chain A → B → C must be emitted in C, B, A order
     * (C has no deps, B depends on C, A depends on B).
     */
    public function testTransitiveDependenciesOrderedCorrectly(): void
    {
        // Arrange
        $a = $this->makeMigration('create_user_roles',  deps: ['create_users_table']);
        $b = $this->makeMigration('create_users_table', deps: ['create_roles_table']);
        $c = $this->makeMigration('create_roles_table', deps: []);

        $runner = new MigrationRunner();

        // Act
        $sorted = $runner->sort([$a, $b, $c]);

        // Assert
        $this->assertSame('create_roles_table', $sorted[0]->getSlug());
        $this->assertSame('create_users_table', $sorted[1]->getSlug());
        $this->assertSame('create_user_roles',  $sorted[2]->getSlug());
    }

    /**
     * A cycle in the dependency graph (A depends on B, B depends on A) must
     * throw a clear RuntimeException rather than looping forever.
     */
    public function testCyclicDependencyThrowsException(): void
    {
        // Arrange
        $a = $this->makeMigration('migration_a', deps: ['migration_b']);
        $b = $this->makeMigration('migration_b', deps: ['migration_a']);

        $runner = new MigrationRunner();

        // Act + Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cyclic dependency');
        $runner->sort([$a, $b]);
    }

    /**
     * A dependency that refers to an unknown slug must throw, preventing
     * silent errors where a missing prerequisite migration is never run.
     */
    public function testUnresolvableDependencyThrowsException(): void
    {
        // Arrange
        $a = $this->makeMigration('create_users_table', deps: ['create_roles_table_that_does_not_exist']);

        $runner = new MigrationRunner();

        // Act + Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('create_roles_table_that_does_not_exist');
        $runner->sort([$a]);
    }

    /**
     * Regression: the authserver RBAC functions migration must run AFTER
     * the user_roles table migration, even though user_roles is not in the
     * transitive dependency chain of the RBAC functions migration through
     * the audit_log → permission_templates → … path.
     *
     * The Kahn's algorithm implementation splices newly-ready migrations at
     * queue position 0 (array_splice($queue, 0, 0, $insertable)).  When
     * create_authserver_permissions_table (pri=30) is emitted, it makes
     * create_authserver_audit_log_table (pri=50) newly ready.  That is
     * inserted at position 0 in front of create_authserver_user_roles_table
     * (pri=40) which was already waiting.  The audit_log chain (50 → 55 →
     * 65 → 70 → 75) then runs entirely before user_roles (pri=40), causing
     * "CREATE TRIGGER … ON authserver.user_roles" to fail because the table
     * did not exist yet.
     *
     * The fix: declare create_authserver_user_roles_table as an explicit dep
     * of create_authserver_rbac_functions (000036) so the topological sort
     * guarantees the correct ordering regardless of queue-insertion order.
     *
     * This test uses a minimal graph that reproduces the exact branching
     * pattern from the authserver migration set.
     */
    public function testRbacFunctionsMigrationRunsAfterUserRolesTable(): void
    {
        // Arrange — minimal graph that mirrors the real authserver migration set
        //
        //   schema (10)
        //     └→ roles (20)
        //          ├→ permissions (30)
        //          │    └→ audit_log (50)
        //          │         └→ perm_templates (55)
        //          │              └→ role_templates (60)
        //          │                   └→ perm_inheritance (65)
        //          │                        └→ effective_perms (70)
        //          │                             └→ rbac_functions (75) ← also explicit dep on user_roles
        //          └→ user_roles (40)    ← NOT in the audit_log chain above
        //               └→ user_organizations (45)
        //
        // Without the explicit dep, the queue-splice bug causes rbac_functions
        // to run while user_roles is still in the queue (not yet emitted).
        $schema     = $this->makeMigration('create_authserver_schema',                 priority: 10);
        $roles      = $this->makeMigration('create_authserver_roles_table',            priority: 20, deps: ['create_authserver_schema']);
        $perms      = $this->makeMigration('create_authserver_permissions_table',      priority: 30, deps: ['create_authserver_roles_table']);
        $userRoles  = $this->makeMigration('create_authserver_user_roles_table',       priority: 40, deps: ['create_authserver_roles_table']);
        $auditLog   = $this->makeMigration('create_authserver_audit_log_table',        priority: 50, deps: ['create_authserver_permissions_table']);
        $orgs       = $this->makeMigration('create_organizations_table',               priority: 16);
        $userOrgs   = $this->makeMigration('create_authserver_user_organizations_table', priority: 45, deps: ['create_authserver_user_roles_table', 'create_organizations_table']);
        $permTmpl   = $this->makeMigration('create_authserver_permission_templates_table', priority: 55, deps: ['create_authserver_audit_log_table']);
        $roleTmpl   = $this->makeMigration('create_authserver_role_templates_table',   priority: 60, deps: ['create_authserver_permission_templates_table']);
        $permInh    = $this->makeMigration('create_authserver_permission_inheritance_table', priority: 65, deps: ['create_authserver_role_templates_table']);
        $effPerms   = $this->makeMigration('create_authserver_effective_permissions_view', priority: 70, deps: ['create_authserver_permission_inheritance_table']);
        $rbacFns    = $this->makeMigration('create_authserver_rbac_functions',         priority: 75, deps: [
            'create_authserver_effective_permissions_view',
            'create_authserver_user_roles_table',         // the fix
            'create_authserver_user_organizations_table', // the fix
        ]);

        $runner = new MigrationRunner();

        // Act
        $sorted = $runner->sort([
            $schema, $roles, $perms, $userRoles, $auditLog, $orgs, $userOrgs,
            $permTmpl, $roleTmpl, $permInh, $effPerms, $rbacFns,
        ]);

        // Assert — extract just the slugs for readable failure messages
        $slugs = array_map(fn($m) => $m->getSlug(), $sorted);

        $userRolesPos = array_search('create_authserver_user_roles_table', $slugs, true);
        $rbacFnsPos   = array_search('create_authserver_rbac_functions',   $slugs, true);

        $this->assertNotFalse($userRolesPos, 'create_authserver_user_roles_table must appear in sorted output');
        $this->assertNotFalse($rbacFnsPos,   'create_authserver_rbac_functions must appear in sorted output');
        $this->assertLessThan(
            $rbacFnsPos,
            $userRolesPos,
            'create_authserver_user_roles_table must run before create_authserver_rbac_functions ' .
            '(triggers reference authserver.user_roles which must exist at CREATE TRIGGER time)'
        );

        // Also assert user_organizations runs before rbac_functions
        $userOrgsPos = array_search('create_authserver_user_organizations_table', $slugs, true);
        $this->assertLessThan(
            $rbacFnsPos,
            $userOrgsPos,
            'create_authserver_user_organizations_table must run before create_authserver_rbac_functions'
        );
    }

    /**
     * The Kahn's algorithm splice-at-front bug (array_splice($q, 0, 0, $new))
     * can displace already-queued lower-priority siblings behind newly-unlocked
     * higher-priority ones.  This test documents the behaviour with the MINIMAL
     * reproducer: A→B (pri=30), A→C (pri=50 in dep chain); both B and C unlock
     * after A but C should NOT run before B even if C is inserted at front.
     *
     * With the fix (explicit dep C→B), the correct order is enforced
     * regardless of queue-insertion order.
     */
    public function testSiblingWithLowerPriorityRunsBeforeHigherPrioritySiblingInSameDepTree(): void
    {
        // Arrange — two siblings, both unlocked by A; B has lower priority (runs
        // first by convention) but is displaced by C being spliced at front when
        // A is emitted and C's chain follows immediately.
        //
        //   A (pri=10)
        //     ├→ B (pri=20, no further deps)
        //     └→ C-chain-root (pri=30, leads to D pri=40 which needs B)
        //          └→ D (pri=40, explicit dep on B)
        //
        // Without explicit dep D→B: C is inserted at front ahead of B; D then
        // runs without B having been created — wrong order.
        // With explicit dep D→B: D cannot run until B is emitted — correct.
        $a = $this->makeMigration('migration_a',       priority: 10);
        $b = $this->makeMigration('migration_b',       priority: 20, deps: ['migration_a']);
        $c = $this->makeMigration('migration_c_chain', priority: 30, deps: ['migration_a']);
        $d = $this->makeMigration('migration_d_needs_b', priority: 40, deps: ['migration_c_chain', 'migration_b']);

        $runner = new MigrationRunner();

        // Act
        $sorted = $runner->sort([$a, $b, $c, $d]);
        $slugs  = array_map(fn($m) => $m->getSlug(), $sorted);

        $bPos = array_search('migration_b',         $slugs, true);
        $dPos = array_search('migration_d_needs_b', $slugs, true);

        // Assert — B must always precede D (D explicitly depends on B)
        $this->assertLessThan($dPos, $bPos,
            'migration_b must run before migration_d_needs_b (explicit dep declared)');
    }

    // -----------------------------------------------------------------------
    // Autorun and migration_cutoff filtering
    // -----------------------------------------------------------------------

    /**
     * Migrations with autoExecute=false are excluded from the pending list by
     * default (they require --force to run).
     */
    public function testAutorunFalseMigrationsExcludedByDefault(): void
    {
        // Arrange
        $normal  = $this->makeMigration('create_users_table', autoExecute: true);
        $manual  = $this->makeMigration('seed_demo_data',     autoExecute: false);

        $runner = new MigrationRunner();

        // Act – filter without force flag
        $pending = $runner->filterAutorun([$normal, $manual], force: false);

        // Assert – only autoExecute=true migration included
        $this->assertCount(1, $pending);
        $this->assertSame('create_users_table', $pending[0]->getSlug());
    }

    /**
     * With force=true, autoExecute=false migrations are included in the run.
     */
    public function testAutorunFalseMigrationsIncludedWithForceFlag(): void
    {
        // Arrange
        $normal = $this->makeMigration('create_users_table', autoExecute: true);
        $manual = $this->makeMigration('seed_demo_data',     autoExecute: false);

        $runner = new MigrationRunner();

        // Act – filter with force=true
        $pending = $runner->filterAutorun([$normal, $manual], force: true);

        // Assert – both migrations included
        $this->assertCount(2, $pending);
    }

    /**
     * migration_cutoff is a datetime string (YYYY_MM_DD_HHmmss). Migrations
     * with a filename timestamp <= cutoff are skipped. This prevents framework
     * baseline migrations from re-running on a production install that has a
     * cutoff date set.
     */
    public function testMigrationCutoffSkipsOlderTimestamps(): void
    {
        // Arrange – cutoff: 2022-01-01; migration from 2020 must be skipped
        $old     = $this->makeMigration('2020_01_01_000000_create_settings', timestamp: '2020_01_01_000000');
        $current = $this->makeMigration('2023_06_01_000000_create_users',    timestamp: '2023_06_01_000000');

        $runner = new MigrationRunner();

        // Act
        $pending = $runner->filterCutoff([$old, $current], cutoff: '2022_01_01_000000');

        // Assert – only the 2023 migration passes the cutoff
        $this->assertCount(1, $pending);
        $this->assertStringContainsString('create_users', $pending[0]->getSlug());
    }

    /**
     * A migration whose timestamp equals the cutoff exactly is also skipped
     * (cutoff is inclusive — "run nothing older than or equal to this point").
     */
    public function testMigrationCutoffSkipsExactMatchTimestamp(): void
    {
        // Arrange
        $exact = $this->makeMigration('2022_01_01_000000_create_permissions', timestamp: '2022_01_01_000000');

        $runner = new MigrationRunner();

        // Act
        $pending = $runner->filterCutoff([$exact], cutoff: '2022_01_01_000000');

        // Assert – excluded because it's exactly at the cutoff
        $this->assertCount(0, $pending);
    }

    /**
     * Migrations without a filename timestamp (legacy un-timestamped) are
     * never filtered by cutoff and always pass through.
     */
    public function testUntimestampedMigrationsPassCutoffFilterUnchanged(): void
    {
        // Arrange – no timestamp prefix in slug
        $legacy = $this->makeMigration('legacy_setup_migration', timestamp: null);

        $runner = new MigrationRunner();

        // Act
        $pending = $runner->filterCutoff([$legacy], cutoff: '9999_12_31_235959');

        // Assert – always included regardless of cutoff
        $this->assertCount(1, $pending);
    }

    /**
     * filterAlreadyRan() subtracts migrations whose slugs appear in the
     * provided "already ran" list, returning only genuinely pending ones.
     */
    public function testFilterAlreadyRanExcludesRanSlugs(): void
    {
        // Arrange
        $a = $this->makeMigration('create_roles_table');
        $b = $this->makeMigration('create_users_table');
        $c = $this->makeMigration('create_tokens_table');

        $runner = new MigrationRunner();

        // Act – pretend roles and tokens have already run
        $pending = $runner->filterAlreadyRan([$a, $b, $c], ranSlugs: ['create_roles_table', 'create_tokens_table']);

        // Assert – only users is pending
        $this->assertCount(1, $pending);
        $this->assertSame('create_users_table', $pending[0]->getSlug());
    }

    /**
     * Regression: when a high-priority-number migration becomes newly ready
     * mid-sort (because its lower-priority-number dependency just ran), it must
     * NOT jump ahead of siblings with lower priority numbers that are already in
     * the queue waiting to run.
     *
     * Concrete scenario: auth schema migrations 000020–000025 all depend on
     * create_authserver_schema (priority 10). After that runs, priority 90–125
     * all become ready and get enqueued together — sorted correctly. Then when
     * priority 100 (user_activity_log) runs, priority 125 (daily_activity_summary)
     * becomes newly ready. It must be inserted AFTER priority 110, 115, 120 —
     * not at position 0 of the queue.
     */
    public function testHighPriorityNumberSiblingDoesNotJumpQueueWhenMadeReady(): void
    {
        // Arrange
        // schema (10) → [a(90), b(100), c(110), d(125→depends on b)]
        $schema = $this->makeMigration('schema',  10);
        $a      = $this->makeMigration('a',        90, ['schema']);
        $b      = $this->makeMigration('b',       100, ['schema']);
        $c      = $this->makeMigration('c',       110, ['schema']);
        $d      = $this->makeMigration('d',       125, ['b']);     // only depends on b(100)

        $runner = new MigrationRunner();

        // Act
        $sorted = $runner->sort([$schema, $a, $b, $c, $d]);

        // Assert — d(125) must come after c(110) despite becoming ready when b(100) runs
        $slugs = array_map(fn($m) => $m->getSlug(), $sorted);
        $this->assertSame(['schema', 'a', 'b', 'c', 'd'], $slugs,
            'd(125) must not jump ahead of c(110) when it becomes ready after b(100) runs');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a concrete stub migration with controllable metadata.
     *
     * @param string   $slug        Identifies this migration; becomes the return of getSlug().
     * @param int      $priority    Execution priority (lower = runs first).
     * @param string[] $deps        Slugs of migrations that must run before this one.
     * @param bool     $autoExecute Whether the migration is included in default runs.
     * @param string|null $timestamp YYYY_MM_DD_HHmmss string returned by getTimestamp().
     */
    private function makeMigration(
        string $slug,
        int $priority = 50,
        array $deps = [],
        bool $autoExecute = true,
        ?string $timestamp = null
    ): StubMigration {
        $m = new StubMigration();
        $m->forcedSlug      = $slug;
        $m->priority        = $priority;
        $m->dependencies    = $deps;
        $m->autoExecute     = $autoExecute;
        $m->forcedTimestamp = $timestamp;

        return $m;
    }
}

/**
 * Minimal concrete Migration whose slug and timestamp can be set directly,
 * bypassing the class-name-based extraction. Used only in unit tests.
 */
class StubMigration extends Migration
{
    /** @var string Overrides getSlug() return value when set. */
    public string $forcedSlug = '';

    /** @var string|null Overrides getTimestamp() return value when set. */
    public ?string $forcedTimestamp = null;

    public function __construct()
    {
        // Skip parent constructor — no Application / Database needed for unit tests.
    }

    public function up(): void {}
    public function down(): void {}

    public function getSlug(): string
    {
        return $this->forcedSlug !== '' ? $this->forcedSlug : parent::getSlug();
    }

    public function getTimestamp(): ?string
    {
        return $this->forcedTimestamp !== null ? $this->forcedTimestamp : parent::getTimestamp();
    }

    /**
     * Exposes the protected static helper so anonymous sub-classes in the test
     * can call it without reflection.
     */
    public function slugFromName(string $name): string
    {
        return Migration::extractSlugFromName($name);
    }

    public function timestampFromName(string $name): ?string
    {
        return Migration::extractTimestampFromName($name);
    }
}
