<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\OrganizationsController;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The organisation summary screen — 39 uncovered statements in one action.
 *
 * The existing tests of this controller run against a fake view whose layouts cover `list`, `edit`
 * and `members` but not `view`, which is a neat illustration of how a screen goes uncovered: not
 * because anybody decided to skip it, but because the harness grew around the screens that came
 * first.
 *
 * What the action does is read one record and summarise its membership, and the summary is where the
 * decisions are:
 *
 * - **active members only, and only a few.** This is a summary with the full list one click away, so
 *   a screen that loaded every member would be slowest exactly on the organisations that matter most.
 * - **the count is a count, not the size of the sample.** Ten members shown and «10 members» beside
 *   them on an organisation with four hundred is the kind of wrong number somebody plans against.
 * - **both the table and the column are configurable.** An application can point the membership
 *   table elsewhere, and the screen has to follow it — reading the default while the rest of the
 *   system reads the override would show an empty organisation that is not empty.
 *
 * And two refusals, both of which end in a redirect rather than an error page: an id that is not a
 * number, and a record that has been deleted since the link was made. A link from a bookmark or a
 * stale listing is the ordinary case, and the useful next step is the list.
 *
 * Both backends: {@see OrganizationSummaryScreenPostgreSQLTest}. The membership query is a join
 * across a schema boundary — `authserver.user_organizations` to `users` — which the two backends
 * qualify differently, and the count is a second query that has to agree with it.
 */
#[CoversClass(OrganizationsController::class)]
class OrganizationSummaryScreenTest extends BaseTestCase
{
    private $db;

    private int $orgId = 0;

    private array $userIds = [];

    /** @var array<string, bool> Which lanes have rebuilt their tables this run. */
    private static array $migrated = [];

    /** The membership table and column, from the one reader the migration also uses. */
    private string $memberTable = '';

    private string $orgColumn = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        User::setupDb();

        // Once per class: two drops and two migrations per test is a cost measured in
        // seconds across two lanes, and the shape cannot change between tests of one class.
        if (!isset(self::$migrated[static::class])) {
            /*
             * Dropped before it is migrated, and this one earned the rule.
             *
             * The test database held an `authserver_user_organizations` with columns
             * `user_id, organization_id, is_active` — three columns, and not the ones the shipped
             * migration declares (`userid`, `granted_by`, `granted_at`, `expires_at`, `is_active`). It is
             * a shape from an older schema that survived because this database persists between runs,
             * and `runMigrations()` is a no-op for a table that already exists. So every insert here
             * failed on `userid`, and the screen under test — which joins on `uo.userid` — could never
             * have been exercised against it. That is the likeliest reason this action was never covered.
             */
            foreach (
                [\Pramnos\Auth\Role::membershipTable(), 'organizations'] as $table
            ) {
                $this->db->query(
                    'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable($table)
                );
            }

            $this->runMigrations([
                \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
                \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
            ], $this->db);
            self::$migrated[static::class] = true;
        }

        /*
         * Asked, not assumed.
         *
         * Both the migration and `Role` read the membership table's name from one place, precisely
         * so the two cannot drift — and a fixture that spells it out by hand is a third reader with
         * its own opinion. Hard-coding `authserver.user_organizations` here addressed a different
         * table on MySQL, where the qualifier is a database name rather than a schema, and the
         * insert failed on a column that table does not have.
         */
        $this->memberTable = \Pramnos\Auth\Role::membershipTable();
        $this->orgColumn   = \Pramnos\Auth\Role::organizationColumn();

        $this->seed();
        $_GET = [];
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** One organisation, two active members and one inactive. */
    private function seed(): void
    {
        $this->db->queryBuilder()->table('organizations')->insert([
            'name'        => 'Probe Organisation',
            'description' => 'For the summary screen',
            'org_type'    => 'test',
            'is_active'   => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        /*
         * Read back rather than taken from `getInsertId()`.
         *
         * The two backends do not agree on what a last-insert id is for a `SERIAL` column, so the
         * only portable answer is to ask for the row.
         */
        $created = $this->db->queryBuilder()->table('organizations')
            ->where('name', 'Probe Organisation')
            ->orderBy('organization_id', 'desc')
            ->first();
        $this->orgId = (int) ($created->fields['organization_id'] ?? 0);
        $this->assertGreaterThan(0, $this->orgId, 'the fixture organisation was not created');

        foreach (['orgprobe_a', 'orgprobe_b', 'orgprobe_gone'] as $index => $name) {
            $user = new User();
            $user->username = $name . '_' . bin2hex(random_bytes(3));
            $user->email    = $user->username . '@example.test';
            $user->save();
            $uid = (int) $user->userid;
            $this->userIds[] = $uid;

            $this->db->queryBuilder()->table($this->memberTable)->insert([
                'userid'            => $uid,
                $this->orgColumn    => $this->orgId,
                'granted_by'      => null,
                'granted_at'      => date('Y-m-d H:i:s', time() - (10 - $index)),
                'expires_at'      => null,
                // The third is inactive: a former member, who must not be counted or listed.
                'is_active'       => $index === 2 ? 0 : 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->userIds as $uid) {
            foreach ([$this->memberTable, '#PREFIX#userdetails', '#PREFIX#users'] as $t) {
                try {
                    $this->db->queryBuilder()->table($t)->where('userid', $uid)->delete();
                } catch (\Throwable) {
                    // Nothing to undo.
                }
            }
        }
        $this->userIds = [];

        if ($this->orgId > 0) {
            try {
                $this->db->queryBuilder()->table('organizations')
                    ->where('organization_id', $this->orgId)->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }

        $_GET = [];
        User::clearUserCache();

        parent::tearDown();
    }

    /**
     * A controller whose gate, redirect and view are recorders.
     *
     * The view is the only one that is a substitute rather than a probe: what a theme does with a
     * context is its own business, and a real render would need a view stack to assert one string.
     * What put the values *in* that context is the subject here.
     */
    private function probe(?int $option): object
    {
        // `_option`, in `$_GET` — that is the only thing `Request::staticGetOption()` reads. My
        // first version set `option`, and every test then took the "id is not valid" branch.
        $_GET['_option'] = $option === null ? 'not-a-number' : (string) $option;

        return new class extends OrganizationsController {
            public array $errors = [];

            public array $redirects = [];

            public array $context = [];

            public bool $rendered = false;

            public function __construct()
            {
            }

            protected function requireMinUserType(int $minType): bool
            {
                return false;
            }

            public function addError($message)
            {
                $this->errors[] = (string) $message;

                return $this;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $owner = $this;
                $view = new class ($owner) {
                    public function __construct(private object $owner)
                    {
                    }

                    public function __set($key, $value)
                    {
                        $this->owner->context[$key] = $value;
                    }

                    public function display(
                        string $layout = 'default',
                        bool $return = false,
                        bool $outputBuffer = true
                    ): mixed {
                        $this->owner->rendered = true;
                        $this->owner->context['__layout'] = $layout;

                        return '';
                    }

                    public function assign(string $key, mixed $value): void
                    {
                        $this->owner->context[$key] = $value;
                    }
                };

                return $view;
            }
        };
    }

    // ── The summary ───────────────────────────────────────────────────────────

    /**
     * The record reaches the view, and the page is titled with its name.
     *
     * The title is what a browser tab and a bookmark carry, so «Organization» on every one of them
     * makes a set of open tabs unusable — and it is the one string that says which record is on
     * screen when the rest of the page is a list of people.
     */
    public function testTheRecordReachesTheViewAndTitlesThePage(): void
    {
        // Arrange
        $probe = $this->probe($this->orgId);

        // Act
        $probe->view();

        // Assert
        $this->assertTrue($probe->rendered, 'the screen did not render');
        $this->assertSame('view', $probe->context['__layout'] ?? null);
        $this->assertSame('Probe Organisation', $probe->context['org']['name'] ?? null);
        $this->assertSame([], $probe->errors);
        $this->assertSame([], $probe->redirects);

        $this->assertSame(
            'Probe Organisation',
            (string) Factory::getDocument()->title,
            'the page is titled generically, so every open tab looks the same'
        );
    }

    /**
     * Only active members are listed, and the count agrees with the list.
     *
     * Two queries that have to say the same thing about membership. The inactive row is a former
     * member — soft-deleted rather than removed, because the membership is the record that a role
     * was once granted — and counting it would report an organisation as larger than it is, which is
     * a number somebody plans against.
     */
    public function testOnlyActiveMembersAreListedAndCounted(): void
    {
        // Arrange
        $probe = $this->probe($this->orgId);

        // Act
        $probe->view();

        // Assert
        $members = (array) ($probe->context['members'] ?? []);
        $this->assertCount(2, $members, 'a former member is listed as current');
        $this->assertSame(2, $probe->context['memberCount'] ?? null, 'the count includes former members');

        $names = array_map(static fn ($row) => (string) ($row['username'] ?? ''), $members);
        foreach ($names as $name) {
            $this->assertStringNotContainsString('orgprobe_gone', $name);
        }
    }

    /**
     * The listed members carry what the screen shows about a person.
     *
     * The join reaches into `users`, so a missing column here is an empty cell on the screen rather
     * than an error — the quiet failure that gets reported as "the members list is broken" long
     * after the join changed.
     */
    public function testTheListedMembersCarryTheirDetails(): void
    {
        // Arrange
        $probe = $this->probe($this->orgId);

        // Act
        $probe->view();

        // Assert
        $members = (array) ($probe->context['members'] ?? []);
        $this->assertNotSame([], $members);

        foreach ($members as $row) {
            foreach (['userid', 'username', 'email', 'granted_at'] as $column) {
                $this->assertArrayHasKey($column, (array) $row, $column . ' is not in the row');
            }
        }
    }

    /**
     * The count is the whole membership, not the size of the sample.
     *
     * The list is capped at ten because this is a summary. So on an organisation with more than ten
     * active members the two numbers diverge, and that is exactly when reporting the sample size as
     * the total is both wrong and plausible.
     */
    public function testTheCountIsTheWholeMembershipNotTheSample(): void
    {
        // Arrange — twelve more active members, taking it past the display cap
        for ($i = 0; $i < 12; $i++) {
            $user = new User();
            $user->username = 'orgprobe_bulk' . $i . '_' . bin2hex(random_bytes(3));
            $user->email    = $user->username . '@example.test';
            $user->save();
            $uid = (int) $user->userid;
            $this->userIds[] = $uid;

            $this->db->queryBuilder()->table($this->memberTable)->insert([
                'userid'            => $uid,
                $this->orgColumn    => $this->orgId,
                'granted_by'      => null,
                'granted_at'      => date('Y-m-d H:i:s'),
                'expires_at'      => null,
                'is_active'       => 1,
            ]);
        }

        $probe = $this->probe($this->orgId);

        // Act
        $probe->view();

        // Assert
        $this->assertCount(
            10,
            (array) ($probe->context['members'] ?? []),
            'the summary loaded the whole membership'
        );
        $this->assertSame(
            14,
            $probe->context['memberCount'] ?? null,
            'the count reported the sample size, which reads as a smaller organisation'
        );
    }

    /**
     * An organisation with no members renders, with nothing rather than an error.
     *
     * A newly created organisation is this, and it is the first thing somebody sees after creating
     * one. A screen that failed here would make the create flow look broken at its last step.
     */
    public function testAnEmptyOrganisationStillRenders(): void
    {
        // Arrange
        $this->db->queryBuilder()->table($this->memberTable)
            ->where($this->orgColumn, $this->orgId)->delete();
        $probe = $this->probe($this->orgId);

        // Act
        $probe->view();

        // Assert
        $this->assertTrue($probe->rendered);
        $this->assertSame([], (array) ($probe->context['members'] ?? []));
        $this->assertSame(0, $probe->context['memberCount'] ?? null);
        $this->assertSame([], $probe->errors);
    }

    // ── The two refusals ──────────────────────────────────────────────────────

    /**
     * An id that is not a number sends the visitor to the list, with a message.
     *
     * A redirect rather than an error page, because the cause is almost always a truncated or
     * hand-edited link and the useful next step is the list. An error page makes the visitor go
     * looking for the link again.
     */
    public function testAnInvalidIdRedirectsToTheList(): void
    {
        // Arrange
        $probe = $this->probe(null);

        // Act
        $probe->view();

        // Assert
        $this->assertFalse($probe->rendered, 'a screen was rendered for an unusable id');
        $this->assertNotSame([], $probe->errors, 'the visitor is redirected with no explanation');
        $this->assertStringContainsString('not valid', $probe->errors[0]);
        $this->assertNotSame([], $probe->redirects);
    }

    /**
     * A record that no longer exists says so, rather than rendering an empty page.
     *
     * The bookmark case, and a stale listing is the same thing. An empty summary screen would read
     * as an organisation with no members rather than as one that is gone — and somebody would create
     * a second one.
     */
    public function testADeletedRecordSaysSoRatherThanRenderingEmpty(): void
    {
        // Arrange
        $probe = $this->probe($this->orgId + 100000);

        // Act
        $probe->view();

        // Assert
        $this->assertFalse($probe->rendered, 'an empty screen was rendered for a missing record');
        $this->assertStringContainsString('no longer exists', $probe->errors[0] ?? '');
        $this->assertNotSame([], $probe->redirects);
    }

    /**
     * Zero is not a valid id either.
     *
     * `(int)` on a missing or non-numeric option gives 0, so the guard has to reject it — and 0 is
     * also what an `AUTO_INCREMENT` column never is, so there is no record it could mean.
     */
    public function testZeroIsRefused(): void
    {
        // Arrange
        $probe = $this->probe(0);

        // Act
        $probe->view();

        // Assert
        $this->assertFalse($probe->rendered);
        $this->assertStringContainsString('not valid', $probe->errors[0] ?? '');
    }
}
