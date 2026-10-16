<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\AuditLog;
use Pramnos\Console\Commands\AuditPrune;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The audit trail on both backends: events and changes written with their secrets redacted, read
 * back newest first through the filters an admin screen needs, and pruned only when a retention
 * is asked for.
 */
#[CoversClass(AuditLog::class)]
#[CoversClass(AuditPrune::class)]
#[CoversClass(\Pramnos\Database\Instant::class)]
final class AuditLogTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    /** What marks this test's rows, so it reads and removes only its own. */
    private string $mark = '';

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->queryBuilder()->table(AuditLog::TABLE)->where('event_type', 'like', $this->mark . '%')->delete();
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        AuditLog::resetTableCache();
    }

    /** A connection made the framework's, with the audit table. */
    private function connect(string $type, string $host, int $port): void
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class], $db);
        AuditLog::resetTableCache();
        $this->mark = 'au' . bin2hex(random_bytes(3)) . '.';
    }

    /**
     * An event and a change land with their actor, target or object, and their context, old and
     * new values — the secrets in them replaced, the rest as given.
     */
    #[DataProvider('databases')]
    public function testEventsAndChangesAreWrittenWithTheirSecretsRedacted(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        AuditLog::record($this->mark . 'role.granted', 3, 42, ['role' => 'editor', 'password' => 'hunter2', 'tokens_issued' => 2]);
        AuditLog::change($this->mark . 'client.updated', null, 'application', 7, ['clientSecret' => 'old'], ['clientSecret' => 'new', 'name' => 'Ψ'], ['via' => ['api_key' => 'k', 'ip' => '10.0.0.1']]);
        $rows = AuditLog::query(['event' => $this->mark . '*']);

        // Assert
        $this->assertCount(2, $rows);
        [$change, $event] = $rows;
        $this->assertSame([$this->mark . 'role.granted', 3, 'user', 'user', '42'], [$event['event_type'], (int) $event['actor_userid'], $event['actor_type'], $event['target_type'], (string) $event['target_id']]);
        $this->assertEquals(['role' => 'editor', 'password' => AuditLog::REDACTED, 'tokens_issued' => 2], $event['metadata'], 'a count of tokens is not a token');
        $this->assertSame([null, 'system', 'application', '7'], [$change['actor_userid'], $change['actor_type'], $change['object_type'], (string) $change['object_id']]);
        $this->assertSame(['clientSecret' => AuditLog::REDACTED], $change['old_values']);
        $this->assertEquals(['clientSecret' => AuditLog::REDACTED, 'name' => 'Ψ'], $change['new_values'], 'JSON columns keep keys, not their order');
        $this->assertEquals(['via' => ['api_key' => AuditLog::REDACTED, 'ip' => '10.0.0.1']], $change['metadata'], 'at any depth');
    }

    /**
     * The reader's filters: an event or a prefix, the actor, the target, the object, a time window
     * and the page before an id — newest first, at most the limit.
     */
    #[DataProvider('databases')]
    public function testTheReaderFiltersAndPages(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        foreach ([[1, 10], [2, 10], [1, 11], [2, 12]] as $i => [$actor, $target]) {
            AuditLog::record($this->mark . ($i < 2 ? 'role.' : 'user.') . $i, $actor, $target);
        }
        AuditLog::change($this->mark . 'setting', 1, 'setting', 'smtp_host', [], ['value' => 'mail']);
        $all = AuditLog::query(['event' => $this->mark . '*'], 10);

        // Act
        $prefix  = AuditLog::query(['event' => $this->mark . 'role.*']);
        $exact   = AuditLog::query(['event' => $this->mark . 'user.3']);
        $byActor = AuditLog::query(['event' => $this->mark . '*', 'actor' => 2]);
        $target  = AuditLog::query(['event' => $this->mark . '*', 'target' => 10]);
        $object  = AuditLog::query(['event' => $this->mark . '*', 'object_type' => 'setting', 'object_id' => 'smtp_host']);
        $window  = AuditLog::query(['event' => $this->mark . '*', 'since' => time() - 60, 'until' => time() + 60]);
        $future  = AuditLog::query(['event' => $this->mark . '*', 'since' => time() + 3600]);
        $page    = AuditLog::query(['event' => $this->mark . '*', 'before' => (int) $all[1]['auditid']], 2);

        // Assert
        $this->assertCount(5, $all);
        $this->assertGreaterThan((int) $all[1]['auditid'], (int) $all[0]['auditid'], 'newest first');
        $this->assertSame([$this->mark . 'role.1', $this->mark . 'role.0'], array_column($prefix, 'event_type'));
        $this->assertSame([$this->mark . 'user.3'], array_column($exact, 'event_type'));
        $this->assertCount(2, $byActor);
        $this->assertCount(2, $target);
        $this->assertSame(['value' => 'mail'], $object[0]['new_values']);
        $this->assertNull($object[0]['old_values'], 'nothing before: no value stored');
        $this->assertCount(5, $window);
        $this->assertSame([], $future);
        $this->assertSame(array_column(array_slice($all, 2, 2), 'auditid'), array_column($page, 'auditid'), 'the next page');
    }

    /**
     * Without a retention nothing is pruned; with one, only what is older goes, and the command
     * says how many.
     */
    #[DataProvider('databases')]
    public function testPruningOnlyWhenARetentionIsAsked(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        AuditLog::record($this->mark . 'new', 1);
        $this->db->queryBuilder()->table(AuditLog::TABLE)->insert(['event_type' => $this->mark . 'old', 'actor_type' => 'system', 'event_timestamp' => \Pramnos\Database\Instant::at($this->db, time() - 40 * 86400)]);
        $console = new Application();
        $console->add(new AuditPrune());
        $run = static function (array $options) use ($console): string {
            $tester = new CommandTester($console->find('audit:prune'));
            $tester->execute($options);

            return $tester->getDisplay();
        };

        // Act
        $none   = $run([]);
        $before = array_column(AuditLog::query(['event' => $this->mark . '*']), 'event_type');
        $pruned = $run(['--days' => '30']);
        $after  = array_column(AuditLog::query(['event' => $this->mark . '*']), 'event_type');

        // Assert
        $this->assertStringContainsString('kept whole', $none);
        $this->assertEqualsCanonicalizing([$this->mark . 'old', $this->mark . 'new'], $before);
        $this->assertMatchesRegularExpression('/Kept the last 30 days of the audit trail; [1-9]\d* older rows deleted\./', $pruned);
        $this->assertSame([$this->mark . 'new'], $after);
    }

    /**
     * Without the table every call is a quiet no-op — no row, no exception, an empty read, a
     * prune that says so; and a secret is a key's word, never a part of one.
     */
    #[DataProvider('databases')]
    public function testWithoutTheTableNothingBreaksAndSecretsAreWholeWords(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $probe = new \ReflectionProperty(AuditLog::class, 'tableAvailable');
        $probe->setValue(null, false);
        $console = new Application();
        $console->add(new AuditPrune());
        $tester = new CommandTester($console->find('audit:prune'));

        // Act
        AuditLog::record($this->mark . 'nowhere', 1, 2, ['token' => 'x']);
        $read = AuditLog::query();
        $tester->execute(['--days' => '5']);
        $redacted = AuditLog::redact(['otp' => '123456', 'spin' => 'x', 'keyword' => 'y', 'privateKey' => 'z', 'pin' => 1234, 'cookie' => null]);
        AuditLog::record('', 1);

        // Assert
        $this->assertSame([], $read);
        $this->assertStringContainsString('No audit log here', $tester->getDisplay());
        $this->assertSame(['otp' => AuditLog::REDACTED, 'spin' => 'x', 'keyword' => 'y', 'privateKey' => AuditLog::REDACTED, 'pin' => 1234, 'cookie' => null], $redacted, 'a word, never part of one; numbers and null kept');
    }

    /**
     * The moment is right whatever zone the database session is in: written and compared as a
     * Unix time the database converts, and read back as `at`. With a session five hours off
     * PHP's, a `date('c')` string was the session's local time on MariaDB (the offset dropped)
     * and a window of the last minute found nothing.
     */
    #[DataProvider('databases')]
    public function testTheMomentIsRightInAnySessionZone(string $type, string $host, int $port): void
    {
        // Arrange — a session zone that is nobody's here
        $this->connect($type, $host, $port);
        $this->db->query($type === 'postgresql' ? "SET TIME ZONE 'Asia/Karachi'" : "SET time_zone = '+05:00'");

        try {
            // Act
            AuditLog::record($this->mark . 'now', 1);
            $rows   = AuditLog::query(['event' => $this->mark . '*', 'since' => time() - 60]);
            $before = AuditLog::query(['event' => $this->mark . '*', 'until' => time() - 60]);
        } finally {
            $this->db->query($type === 'postgresql' ? 'SET TIME ZONE DEFAULT' : "SET time_zone = '+00:00'");
        }

        // Assert
        $this->assertCount(1, $rows, 'inside the last minute');
        $this->assertEqualsWithDelta(time(), $rows[0]['at'], 5, 'the moment, as a Unix time');
        $this->assertSame([], $before);
    }

    /**
     * An event can name an account and a screen or record at once, a change can name the
     * account its record belongs to, and a list of events — exact or by prefix — can be left out.
     */
    #[DataProvider('databases')]
    public function testObjectsBesideTargetsAndEventsLeftOut(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        AuditLog::record($this->mark . 'admin.page', 1, 42, ['why' => 'look'], 'page', 'users/edit');
        AuditLog::record($this->mark . 'admin.page.view', 1);
        AuditLog::change($this->mark . 'user.updated', 1, 'user', 42, ['email' => 'a@x'], ['email' => 'b@x'], [], 42);
        AuditLog::record($this->mark . 'role.granted', 1, 42);

        // Act
        $page   = AuditLog::query(['event' => $this->mark . 'admin.page'])[0];
        $change = AuditLog::query(['event' => $this->mark . 'user.updated'])[0];
        $rest   = array_column(AuditLog::query(['event' => $this->mark . '*', 'not_event' => [$this->mark . 'admin.page*', '', 5]]), 'event_type');
        $exact  = array_column(AuditLog::query(['event' => $this->mark . '*', 'not_event' => [$this->mark . 'admin.page']]), 'event_type');

        // Assert
        $this->assertSame(['user', '42', 'page', 'users/edit'], [$page['target_type'], (string) $page['target_id'], $page['object_type'], (string) $page['object_id']]);
        $this->assertSame(['user', '42', 'user', '42'], [$change['target_type'], (string) $change['target_id'], $change['object_type'], (string) $change['object_id']]);
        $this->assertSame([$this->mark . 'role.granted', $this->mark . 'user.updated'], $rest, 'page views by prefix left out');
        $this->assertNotContains($this->mark . 'admin.page', $exact);
        $this->assertContains($this->mark . 'admin.page.view', $exact, 'an exact name leaves out only itself');
    }

    /**
     * A retention per event: page views go after their own days, everything else after the
     * default, and an event no entry names is kept when there is no default.
     */
    #[DataProvider('databases')]
    public function testRetentionPerEvent(string $type, string $host, int $port): void
    {
        // Arrange — each event once forty days old and once new
        $this->connect($type, $host, $port);
        foreach (['admin.page', 'role.granted', 'user.deleted'] as $event) {
            $this->db->queryBuilder()->table(AuditLog::TABLE)->insert(['event_type' => $this->mark . $event, 'actor_type' => 'system', 'event_timestamp' => \Pramnos\Database\Instant::at($this->db, time() - 40 * 86400)]);
            AuditLog::record($this->mark . $event, 1);
        }
        $app   = \Pramnos\Application\Application::currentInstance() ?? \Pramnos\Application\Application::getInstance();
        $saved = $app->applicationInfo['audit'] ?? null;
        $old   = fn (): array => array_count_values(array_column(array_filter(AuditLog::query(['event' => $this->mark . '*']), fn (array $r): bool => $r['at'] < time() - 86400), 'event_type'));
        $console = new Application();
        $console->add(new AuditPrune());

        try {
            // Act
            AuditLog::prune([$this->mark . 'admin.*' => 30, $this->mark . 'role.granted' => 365, '' => 1, $this->mark . 'user.deleted' => 0]);
            $afterNamed = $old();
            $app->applicationInfo['audit'] = ['retention_days' => [$this->mark . 'role.granted' => 365, '*' => 30]];
            $tester = new CommandTester($console->find('audit:prune'));
            $tester->execute([]);
            $afterDefault = $old();
        } finally {
            $app->applicationInfo['audit'] = $saved;
        }

        // Assert
        $this->assertEquals([$this->mark . 'role.granted' => 1, $this->mark . 'user.deleted' => 1], $afterNamed, 'page views past 30 days gone; the rest kept: no default');
        $this->assertSame([$this->mark . 'role.granted' => 1], $afterDefault, 'the default takes what no entry names');
        $this->assertStringContainsString($this->mark . 'role.granted 365 days, * 30 days', $tester->getDisplay());
        $this->assertCount(3, AuditLog::query(['event' => $this->mark . '*', 'since' => time() - 60]), 'nothing new was touched');
    }
}
