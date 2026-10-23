<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use Pramnos\Audience\Attribute;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\Segments;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\Core\CreateSegmentsTables;
use Pramnos\Framework\Migrations\UserGroups;

/**
 * A throwaway database of four accounts for the audience tests, on MySQL or PostgreSQL: anna (a
 * parent, no profile), bob and cara (professionals with a profile row and interests), and dan,
 * staff, whom the scope leaves out of every answer.
 */
trait AudienceProbe
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    /** The throwaway database's name. */
    private static string $probe = 'pramnos_audience_probe';

    private ?Database $admin = null;

    private ?Database $db = null;

    private ?Database $previous = null;

    /** @var array<string, int> Ids by username */
    private array $ids = [];

    /** @var array<string, int> Group ids by name */
    private array $groups = [];

    /** Whether this test built a prefixed database, which the next test must not reuse. */
    private bool $prefixed = false;

    /**
     * The backends, and MySQL with a table prefix — where `users` is `pf_users` and every
     * qualified column has to name the alias, not the table.
     *
     * @return array<string, array{string, string, int, string}> The type carries the prefix after a `+`
     */
    public static function databases(): array
    {
        return [
            'mysql'           => ['mysql', 'db', 3306, 'root'],
            'mysql, prefixed' => ['mysql+pf_', 'db', 3306, 'root'],
            'postgresql'      => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /** Open the throwaway database on this backend, make it the application's, and seed it. */
    private function boot(string $type, string $host, int $port, string $user): void
    {
        require_once ROOT . '/database/migrations/framework/core/2026_10_21_000009_create_segments_tables.php';
        require_once ROOT . '/database/migrations/framework/core/2026_10_23_000001_add_first_computed_at_to_segments.php';
        require_once ROOT . '/database/migrations/framework/usergroups/2026_10_23_000001_add_membership_history_to_usergroups.php';
        require_once ROOT . '/database/migrations/framework/core/2026_10_21_000010_create_consent_lists_table.php';
        require_once ROOT . '/database/migrations/framework/core/2026_10_21_000011_create_person_events_tables.php';
        require_once ROOT . '/database/migrations/framework/core/2026_10_21_000012_create_rules_tables.php';
        require_once ROOT . '/database/migrations/framework/core/2026_10_21_000013_create_site_messages_table.php';
        require_once ROOT . '/database/migrations/framework/core/2026_10_21_000014_create_experiments_tables.php';
        require_once ROOT . '/database/migrations/framework/messaging/2026_10_21_000002_add_userid_to_mails.php';
        require_once ROOT . '/database/migrations/framework/changelog/2026_08_25_000001_create_changelog_tables.php';
        require_once ROOT . '/database/migrations/framework/changelog/2026_10_04_000001_add_scope_to_changelog_events.php';
        require_once ROOT . '/database/migrations/framework/changelog/2026_10_22_000001_add_event_index_to_changelog_events.php';
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        [$type, $prefix] = array_pad(explode('+', $type, 2), 2, '');
        $make = static function (string $name) use ($type, $host, $port, $user, $prefix): Database {
            $db           = new Database();
            $db->type     = $type;
            $db->server   = $host;
            $db->port     = $port;
            $db->user     = $user;
            $db->password = 'secret';
            $db->database = $name;
            $db->prefix   = $prefix;

            return $db;
        };
        $this->admin = $make(TEST_DATABASE);
        try {
            if (!$this->admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        // The probe is kept per backend, so a prefixed one is built for its test and dropped after.
        if ($prefix !== '') {
            $this->probeChanged($type);
        }
        $this->prefixed = $prefix !== '';
        $this->db = $this->openProbe($this->admin, self::$probe, $make, [
            CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class,
            UserGroups\CreateUsergroupsTables::class,
            UserGroups\CreateAuthserverGroupRolesTable::class,
            UserGroups\AddMembershipHistoryToUsergroups::class,
            CreateSegmentsTables::class,
            \Pramnos\Framework\Migrations\Core\AddFirstComputedAtToSegments::class,
            \Pramnos\Framework\Migrations\Core\CreateConsentListsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class,
            \Pramnos\Framework\Migrations\Core\CreatePersonEventsTables::class,
            \Pramnos\Framework\Migrations\Core\CreateRulesTables::class,
            \Pramnos\Framework\Migrations\Core\CreateSiteMessagesTable::class,
            \Pramnos\Framework\Migrations\Core\CreateExperimentsTables::class,
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
            \Pramnos\Framework\Migrations\Changelog\AddScopeToChangelogEvents::class,
            \Pramnos\Framework\Migrations\Changelog\AddEventIndexToChangelogEvents::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailtemplatesTable::class,
            \Pramnos\Framework\Migrations\Notifications\CreateNotificationsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Messaging\AddUseridToMails::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
        ]);
        $schema = $this->db->schema();
        if (!$schema->hasColumn('users', 'smsoffers')) {
            // An application's own consent column, as a profile setting keeps it.
            $schema->alterTable('users', fn ($table) => $table->tinyInteger('smsoffers')->default(0));
        }
        if (!$schema->hasTable('audtest_profiles')) {
            $schema->createTable('audtest_profiles', function ($table): void {
                $table->bigInteger('userid')->primary();
                $table->integer('pillar');
                $table->string('city', 50)->default('');
                $table->dateTime('joined')->nullable();
            });
            $schema->createTable('audtest_interests', function ($table): void {
                $table->bigInteger('userid');
                $table->integer('interestid');
                $table->primary(['userid', 'interestid']);
            });
        }
        // Whether the audit table exists is remembered per process; this database has one.
        \Pramnos\Auth\AuditLog::resetTableCache();
        // Each person event goes to the change log through the spool: written at once here.
        \Pramnos\Database\WriteSpool::setDriver(\Pramnos\Database\WriteSpool::DRIVER_SYNC);
        \Pramnos\Audience\Installed::reset();
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        // Accounts are cached — by id for the process, and their rows for ten seconds in the SQL
        // cache, namespaced by this probe's database — so the last test's account with the same
        // id would answer for this one's: its address, its name.
        \Pramnos\User\User::clearUserCache();
        $this->db->cacheflush('userlist');
        $this->seed();
    }

    /** Forget the attributes, segment usage and listeners, and give the application its database back. */
    protected function tearDown(): void
    {
        if ($this->prefixed && $this->db !== null) {
            $this->probeChanged($this->db->type);
        }
        Attributes::reset();
        Segments::reset();
        \Pramnos\Audience\Groups::reset();
        \Pramnos\Audience\ConsentLists::reset();
        \Pramnos\Audience\PersonEvents::reset();
        \Pramnos\Audience\Rules\Actions::reset();
        \Pramnos\Audience\Rules\Engine::$queue = null;
        \Pramnos\Audience\Experiments::reset();
        unset($_COOKIE[\Pramnos\Audience\Experiments::COOKIE], $_SESSION['pf_visitor_adopted']);
        \Pramnos\Email\MailLog::reset();
        \Pramnos\Database\WriteSpool::setDriver(null);
        \Pramnos\Event\Event::forget();
        \Pramnos\Auth\AuditLog::resetTableCache();
        \Pramnos\Audience\Installed::reset();
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->close();
    }

    /** The four accounts, their side rows, and the application's attributes over them. */
    private function seed(): void
    {
        $day = static fn (string $d): int => (new \DateTimeImmutable($d . ' 12:00:00'))->getTimestamp();
        foreach ([
            'anna' => ['usertype' => 1, 'language' => 'el', 'lastlogin' => time() - 40 * 86400, 'regdate' => $day('2026-01-15'), 'validated' => 1, 'active' => 1],
            'bob'  => ['usertype' => 2, 'language' => 'en', 'lastlogin' => time() - 5 * 86400, 'regdate' => $day('2026-02-01'), 'validated' => 3, 'active' => 1],
            'cara' => ['usertype' => 2, 'language' => 'el', 'lastlogin' => 0, 'regdate' => $day('2026-03-01'), 'validated' => 0, 'active' => 0],
            'dan'  => ['usertype' => 97, 'language' => 'el', 'lastlogin' => time(), 'regdate' => $day('2026-01-01'), 'validated' => 1, 'active' => 1],
        ] as $name => $row) {
            $this->db->queryBuilder()->table('users')->insert($row + ['username' => $name, 'email' => $name . '@example.com', 'firstname' => ucfirst($name)]);
            $this->ids[$name] = (int) $this->db->queryBuilder()->table('users')->where('username', $name)->first()->fields['userid'];
        }
        $profiles = $this->db->queryBuilder()->table('audtest_profiles');
        $profiles->insert(['userid' => $this->ids['bob'], 'pillar' => 3, 'city' => 'Athens', 'joined' => '2026-02-01 10:00:00']);
        $this->db->queryBuilder()->table('audtest_profiles')
            ->insert(['userid' => $this->ids['cara'], 'pillar' => 1, 'city' => 'Thessaloniki', 'joined' => '2026-03-02 10:00:00']);
        foreach ([['bob', 1], ['bob', 2], ['cara', 2]] as [$name, $interest]) {
            $this->db->queryBuilder()->table('audtest_interests')->insert(['userid' => $this->ids[$name], 'interestid' => $interest]);
        }
        foreach (['Volunteers', 'Press'] as $group) {
            $this->db->queryBuilder()->table('usergroups')->insert(['name' => $group]);
            $this->groups[$group] = (int) $this->db->queryBuilder()->table('usergroups')->where('name', $group)->first()->fields['groupid'];
        }
        foreach ([['anna', 'Volunteers'], ['bob', 'Volunteers'], ['bob', 'Press']] as [$name, $group]) {
            $this->db->queryBuilder()->table('userstogroups')->insert(['userid' => $this->ids[$name], 'groupid' => $this->groups[$group]]);
        }

        Attributes::scope(static fn (QueryBuilder $q) => $q->where('users.usertype', '<', 97));
        Attributes::define(Attribute::column('pillar', 'pillar', Attribute::NUMBER)->from('audtest_profiles'));
        Attributes::define(Attribute::column('city', 'city', Attribute::TEXT)->from('audtest_profiles'));
        Attributes::define(Attribute::column('joined', 'joined', Attribute::DATE)->from('audtest_profiles')->storedAs('datetime'));
        Attributes::define(Attribute::column('joinedday', 'joined', Attribute::DATE)->from('audtest_profiles')->storedAs('date'));
        Attributes::define(Attribute::many('interests', 'audtest_interests', 'interestid')->values([1 => 'Music', 2 => 'Sport']));
        Attributes::define(Attribute::expression('level', 'CASE WHEN users.usertype > 1 THEN 2 ELSE 1 END', Attribute::NUMBER));
        Attributes::define(Attribute::expression('initial', ['mysql' => 'LOWER(LEFT(users.username, 1))', 'postgresql' => 'LOWER(SUBSTR(users.username, 1, 1))'], Attribute::TEXT));
    }

    /**
     * The usernames of these ids, sorted.
     *
     * @param  list<int> $ids
     * @return list<string>
     */
    private function names(array $ids): array
    {
        $names = array_keys(array_intersect($this->ids, $ids));
        sort($names);

        return $names;
    }


    /**
     * A queue that keeps the tasks a test writes instead of storing them: each with its job, its
     * delay and its key.
     *
     * @return \Pramnos\Queue\QueueManager&object{tasks: list<array{job: array<string, mixed>, delay: int, key: ?string}>}
     */
    private function fakeQueue(): \Pramnos\Queue\QueueManager
    {
        $queue = new class extends \Pramnos\Queue\QueueManager {
            /** @var list<array{job: array<string, mixed>, delay: int, key: ?string}> */
            public array $tasks = [];

            /** Nothing to connect to. */
            public function __construct()
            {
            }

            /** Keep the task. */
            public function addKeyed(string $taskType, mixed $data, ?string $key = null, int $delaySeconds = 0, int $ceilingSeconds = 0, int $priority = 10, int $maxAttempts = 3): int
            {
                $this->tasks[] = ['job' => (array) $data, 'delay' => $delaySeconds, 'key' => $key];

                return count($this->tasks);
            }
        };
        \Pramnos\Audience\Rules\Engine::$queue = $queue;

        return $queue;
    }

    /**
     * Run the tasks waiting in the fake queue, as a worker would, until none is left — skipping
     * those this says to leave (a delayed timeout the test wants to come later).
     *
     * @param  callable(array{job: array<string, mixed>, delay: int, key: ?string}): bool|null $leave
     * @return list<string> What each task said
     */
    private function work(\Pramnos\Queue\QueueManager $queue, ?callable $leave = null): array
    {
        $said = [];
        $kept = [];
        while ($queue->tasks !== []) {
            $task = array_shift($queue->tasks);
            if ($leave !== null && $leave($task)) {
                $kept[] = $task;
                continue;
            }
            $said[] = \Pramnos\Audience\Rules\Engine::handle($task['job']);
        }
        $queue->tasks = $kept;

        return $said;
    }

    /**
     * Move a segment's start, and everything recorded of it, `$seconds` into the past — so a test
     * can work it out again and have what changes then fall after its first working out, as it
     * would a minute or a day later.
     */
    private function backdate(int $segment, int $seconds = 60): void
    {
        foreach (['firstcomputedat', 'recomputedat'] as $column) {
            $this->db->queryBuilder()->table('segments')->where('segmentid', $segment)->whereNotNull($column)
                ->update([$column => new \Pramnos\Database\Expression($column . ' - ' . (int) $seconds)]);
        }
        foreach (['entered_at', 'left_at'] as $column) {
            $this->db->queryBuilder()->table('segment_members')->where('segmentid', $segment)->whereNotNull($column)
                ->update([$column => new \Pramnos\Database\Expression($column . ' - ' . (int) $seconds)]);
        }
    }
}
