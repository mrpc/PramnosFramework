<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\AccountErasure;
use Pramnos\Auth\Role;
use Pramnos\Database\Database;
use Pramnos\Event\Event;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\AuthServer;
use Pramnos\Framework\Migrations\Notifications;
use Pramnos\Framework\Testing\Schema;

/**
 * Erasing an account deletes everything the framework holds about it, and nobody else's.
 *
 * Against real tables built from their migrations, on MySQL and PostgreSQL, in a throwaway
 * database. The tables here are the ones the erase used to leave behind: none of them has a
 * cascading foreign key to `users`, so a deleted account kept its passkeys, its trusted
 * devices, its roles, its memberships, its push subscriptions and its notifications.
 */
#[CoversClass(AccountErasure::class)]
#[CoversClass(\Pramnos\Email\MailPersonalData::class)]
#[CoversClass(\Pramnos\Llm\Chat\TaskPolicy::class)]
class AccountErasureTest extends TestCase
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    private const PROBE = 'pramnos_erasure_probe';

    private ?Database $admin = null;

    private ?Database $db = null;

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /** A throwaway database with every table the erase touches, built by its migration. */
    private function boot(string $type, string $host, int $port, string $user): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $make = static function (string $name) use ($type, $host, $port, $user): Database {
            $db           = new Database();
            $db->type     = $type;
            $db->server   = $host;
            $db->port     = $port;
            $db->user     = $user;
            $db->password = 'secret';
            $db->database = $name;

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
        // Built once per class and engine, emptied for each test; see ReusesProbeDatabase.
        $this->db = $this->openProbe($this->admin, self::PROBE, $make, [
            \Pramnos\Framework\Migrations\Core\CreateFrameworkPoliciesTable::class,
            CreateUsersTable::class,
            AuthServer\CreateOrganizationsTable::class,
            AuthServer\CreateAuthserverRolesTable::class,
            AuthServer\CreateAuthserverUserRolesTable::class,
            AuthServer\CreateAuthserverUserOrganizationsTable::class,
            AuthServer\CreatePasskeyCredentialsTable::class,
            AuthServer\CreateAuthserverTrustedDevicesTable::class,
            Notifications\CreateNotificationsTable::class,
            Notifications\CreatePushSubscriptionsTable::class,
            Notifications\CreatePushLogTable::class,
            Notifications\CreatePushtestsTable::class,
            \Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            ...self::llmMigrations(),
        ]);

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
    }

    protected function tearDown(): void
    {
        Event::forget(AccountErasure::EVENT);
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->close();
    }

    /**
     * The `llm` migrations, in their order: what the assistant keeps about a person.
     *
     * @return list<class-string>
     */
    private static function llmMigrations(): array
    {
        $files = glob(ROOT . '/database/migrations/framework/llm/*.php') ?: [];
        sort($files);

        return array_map(static function (string $file): string {
            preg_match('/^namespace\s+([^;]+);.*?^class\s+(\w+)/ms', (string) file_get_contents($file), $found);

            return $found[1] . '\\' . $found[2];
        }, $files);
    }

    /**
     * What the assistant keeps about a user: a conversation and its message and artifact, a memory, a
     * question asked, an agreement, a dashboard, a scheduled task with its run and a reader, and a
     * call in the cost log. Answers the task's id.
     */
    private function aiDataOf(int $userId): int
    {
        $stamp = date('Y-m-d H:i:s');
        $qb    = fn (string $table) => $this->db->queryBuilder()->table($table);
        $qb('chat_conversations')->insert(['userid' => $userId, 'title' => 'Mine ' . $userId, 'space' => 'chat', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $conversation = (int) $qb('chat_conversations')->where('userid', $userId)->first()->fields['conversation_id'];
        $qb('chat_messages')->insert(['conversation_id' => $conversation, 'role' => 'user', 'content' => 'My wife is called Eleni', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $qb('chat_artifacts')->insert(['userid' => $userId, 'space' => 'chat', 'conversation_id' => $conversation, 'kind' => 'text', 'title' => 'About Eleni',
            'content' => '{"text":"Eleni"}', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $qb('chat_memory')->insert(['userid' => $userId, 'kind' => 'fact', 'content' => 'Eleni', 'space' => 'chat', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $qb('chat_asks')->insert(['userid' => $userId, 'status' => 'answered', 'space' => 'chat', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $qb('ai_consents')->insert(['userid' => $userId, 'provider' => 'deepseek', 'created_at' => $stamp]);
        $qb('usersettings')->insert(['userid' => $userId, 'setting' => 'dashboard.admin', 'value' => '[]', 'updated_at' => time()]);
        $qb('ai_routines')->insert(['userid' => $userId, 'space' => 'chat', 'title' => 'Task ' . $userId, 'prompt' => 'p', 'cadence' => 'daily',
            'at' => '08:00', 'timezone' => 'UTC', 'is_active' => true, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $task = (int) $qb('ai_routines')->where('userid', $userId)->first()->fields['routine_id'];
        $qb('ai_task_runs')->insert(['routine_id' => $task, 'kind' => 'ask', 'status' => 'ok', 'content' => 'About Eleni']);
        $qb('ai_task_subscriptions')->insert(['routine_id' => $task, 'userid' => $userId, 'email' => false]);
        $qb('llm_calls')->insert(['userid' => $userId, 'provider' => 'openai', 'model' => 'm', 'messages' => 'Eleni', 'reply' => 'Eleni', 'cost' => 0.5, 'created_at' => $stamp]);

        return $task;
    }

    /** A user, and one row of theirs in every table the erase must reach. */
    private function userWithEverything(string $name): int
    {
        $this->db->queryBuilder()->table('users')->insert(['username' => $name, 'email' => $name . '@example.com']);
        $userId = (int) $this->db->queryBuilder()->table('users')->where('username', $name)->first()->fields['userid'];
        $hash   = hash('sha256', $name);

        $this->db->queryBuilder()->table('organizations')->insert(['name' => 'Org of ' . $name]);
        $orgId = (int) $this->db->queryBuilder()->table('organizations')->where('name', 'Org of ' . $name)->first()->fields['organization_id'];
        $this->db->queryBuilder()->table('authserver.roles')->insert(['role_name' => 'Role of ' . $name, 'is_active' => true]);
        $roleId = (int) $this->db->queryBuilder()->table('authserver.roles')->where('role_name', 'Role of ' . $name)->first()->fields['roleid'];

        $rows = [
            Role::assignmentTable()          => ['userid' => $userId, 'roleid' => $roleId, 'is_active' => true],
            Role::membershipTable()          => ['userid' => $userId, 'organization_id' => $orgId, 'is_active' => true],
            'authserver.passkey_credentials' => ['userid' => $userId, 'credential_id' => 'cred-' . $name, 'public_key' => 'key'],
            'authserver.trusted_devices'     => ['userid' => $userId, 'token_lookup' => substr($hash, 0, 64), 'created_at' => time(), 'expires_at' => time() + 3600],
            'pramnos.pushsubscriptions'      => ['userid' => $userId, 'endpoint' => 'https://push.example/' . $name, 'endpoint_hash' => $hash],
            'pramnos.pushlog'                => ['userid' => $userId, 'endpoint_hash' => $hash],
            \Pramnos\Push\TestPush::TABLE    => ['token_hash' => $hash, 'userid' => $userId, 'endpoint_hash' => $hash, 'sent_at' => time(), 'expires_at' => time() + 60],
            'notifications'                  => [
                'id' => substr($hash, 0, 36), 'type' => 'Probe', 'notifiable_type' => \Pramnos\User\User::class,
                'notifiable_id' => $userId, 'data' => '{}', 'created_at' => date('Y-m-d H:i:s'),
            ],
        ];
        foreach ($rows as $table => $row) {
            $this->db->queryBuilder()->table($table)->insert($row);
        }

        return $userId;
    }

    /** How many rows of this user's each table still holds. @return array<string, int> */
    private function leftOf(int $userId): array
    {
        $left = [];
        foreach ([
            'users', Role::assignmentTable(), Role::membershipTable(), 'authserver.passkey_credentials',
            'authserver.trusted_devices', 'pramnos.pushsubscriptions', 'pramnos.pushlog', \Pramnos\Push\TestPush::TABLE,
        ] as $table) {
            $left[$table] = $this->db->queryBuilder()->table($table)->where('userid', $userId)->count();
        }
        $left['notifications'] = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $userId)->count();

        return $left;
    }

    /**
     * Every row of the erased account goes, and every row of the other account stays.
     */
    #[DataProvider('databases')]
    public function testEverythingOfTheAccountGoesAndNothingElse(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        $staying = $this->userWithEverything('staying');
        $this->assertNotContains(0, $this->leftOf($leaving), 'the fixture is missing a row: ' . json_encode($this->leftOf($leaving)));

        // Act
        (new AccountErasure($this->db))->erase($leaving);

        // Assert
        $this->assertSame([], array_filter($this->leftOf($leaving)), 'the erase left these behind');
        $this->assertNotContains(0, $this->leftOf($staying), "another account's rows went too");
    }

    /**
     * The mail sent to the account stays in the log without them: no address, no name, the
     * subject `[erased]`, no body. Another account's mail is as it was.
     */
    #[DataProvider('databases')]
    public function testTheMailSentToThemIsErasedFromTheLog(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        $this->userWithEverything('staying');
        foreach (['Leaving@example.com' => 'Your code is 123', 'staying@example.com' => 'Your code is 456'] as $to => $body) {
            $this->db->queryBuilder()->table('mails')->insert([
                'status' => 1, 'frommail' => 'site@example.com', 'fromname' => 'Site', 'tomail' => $to, 'toname' => 'Name',
                'subject' => 'Your code', 'content' => $body, 'date' => time(), 'module' => 'auth', 'moduleinfo' => '',
                'extrainfo' => '', 'path' => '', 'hash' => md5($body),
            ]);
        }

        // Act
        (new AccountErasure($this->db))->erase($leaving);
        $rows = $this->db->queryBuilder()->table('mails')->orderBy('id')->get()->fetchAll();

        // Assert
        $this->assertCount(2, $rows, 'the rows stay: the log keeps that mail went');
        $this->assertSame(['', '', '[erased]', ''], [$rows[0]['tomail'], $rows[0]['toname'], $rows[0]['subject'], $rows[0]['content']], 'stored with a capital, erased all the same');
        $this->assertSame('auth', $rows[0]['module'], 'what kind of mail it was is not the person');
        $this->assertSame(['staying@example.com', 'Your code is 456'], [$rows[1]['tomail'], $rows[1]['content']]);
    }

    /**
     * What the assistant keeps about the account goes too: its conversations with every message,
     * its memory, questions, agreements and dashboard, its tasks with their runs and readers. The
     * cost log keeps what each call cost, without who or what was said. Another account's stays.
     */
    #[DataProvider('databases')]
    public function testWhatTheAssistantKeptGoesToo(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        $staying = $this->userWithEverything('staying');
        $theirs  = $this->aiDataOf($leaving);
        $kept    = $this->aiDataOf($staying);
        $count   = fn (string $table, string $column, int $value): int => $this->db->queryBuilder()->table($table)->where($column, $value)->count();

        // Act
        (new AccountErasure($this->db))->erase($leaving);

        // Assert
        foreach (['chat_conversations', 'chat_artifacts', 'chat_memory', 'chat_asks', 'ai_consents', 'usersettings', 'ai_routines', 'ai_task_subscriptions', 'llm_calls'] as $table) {
            $this->assertSame(0, $count($table, 'userid', $leaving), $table . ' still holds the account');
            $this->assertSame(1, $count($table, 'userid', $staying), $table . ': another account\'s went too');
        }
        $this->assertSame(0, $count('ai_task_runs', 'routine_id', $theirs), 'their task\'s runs');
        $this->assertSame(1, $count('ai_task_runs', 'routine_id', $kept));
        $this->assertSame(1, $this->db->queryBuilder()->table('chat_messages')->count(), 'only the other account\'s message');
        $call = $this->db->queryBuilder()->table('llm_calls')->whereNull('userid')->first()->fields;
        $this->assertSame([null, null, 0.5], [$call['messages'], $call['reply'], (float) $call['cost']], 'the cost kept, the words gone');
    }

    /**
     * Who may take over a leaving member's scheduled tasks, by default: the organisation's other
     * active members, by name, or by username where they have none — never the leaver.
     */
    #[DataProvider('databases')]
    public function testTheMembersWhoMayTakeOverTasks(string $type, string $host, int $port, string $user): void
    {
        // Arrange — leaving and staying are members of the leaver's organisation
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        $staying = $this->userWithEverything('staying');
        $orgId   = (int) $this->db->queryBuilder()->table('organizations')->where('name', 'Org of leaving')->first()->fields['organization_id'];
        $this->db->queryBuilder()->table(Role::membershipTable())->insert(['userid' => $staying, 'organization_id' => $orgId, 'is_active' => true]);
        $policy = new \Pramnos\Llm\Chat\TaskPolicy(new \Pramnos\Llm\Chat\BaseAssistant());

        // Act
        $who = $policy->successors($orgId, $leaving);

        // Assert
        $this->assertSame([$staying => 'staying'], $who);
    }

    /**
     * A notification addressed to something that is not a user, with the same number, stays.
     *
     * `notifications` is keyed by class and id, and an organisation can be notifiable too.
     */
    #[DataProvider('databases')]
    public function testANotificationToSomethingElseWithTheSameIdStays(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        $this->db->queryBuilder()->table('notifications')->insert([
            'id' => 'org-notification-0000000000000000000', 'type' => 'Probe', 'notifiable_type' => 'App\\Organization',
            'notifiable_id' => $leaving, 'data' => '{}', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Act
        (new AccountErasure($this->db))->erase($leaving);

        // Assert
        $types = [];
        $rows  = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $leaving)->get();
        while ($rows->fetch()) {
            $types[] = $rows->fields['notifiable_type'];
        }
        $this->assertSame(['App\\Organization'], $types);
    }

    /**
     * A listener that refuses stops the erase before anything is deleted.
     */
    #[DataProvider('databases')]
    public function testARefusingListenerStopsEverything(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        Event::listen(AccountErasure::EVENT, static fn (int $userId): bool => false);

        // Act
        $raised = false;
        try {
            (new AccountErasure($this->db))->erase($leaving);
        } catch (\RuntimeException) {
            $raised = true;
        }

        // Assert
        $this->assertTrue($raised, 'the refusal was not reported');
        $this->assertNotContains(0, $this->leftOf($leaving), 'something was deleted after the refusal');
    }
}
