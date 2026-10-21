<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Audience\Installed;
use Pramnos\Audience\PersonEvent;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\RecordPersonEvent;
use Pramnos\Audience\RefreshPersonSegments;
use Pramnos\Audience\Segments;
use Pramnos\Database\Database;
use Pramnos\Event\Event;
use Pramnos\Event\QueuedEvents;
use Pramnos\Framework\Factory;
use Pramnos\Queue\QueueManager;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An application that has not run the audience migrations — segments, consent lists, person
 * events — on MySQL and PostgreSQL: none of it does anything there.
 *
 * An application may hold those migrations back while the feature is tried on a branch, and take
 * framework updates meanwhile for other reasons. So with the queue installed and every audience
 * table missing: dispatching a PersonEvent, a refreshOn() event, a named page, an email open or
 * click queue nothing and raise nothing; the scheduled commands succeed with one line; the
 * screens leave the menu and say they are not installed; the person card still shows groups.
 */
#[CoversClass(Installed::class)]
#[CoversClass(\Pramnos\Audience\SiteMessages::class)]
#[CoversClass(\Pramnos\Audience\Rules\Engine::class)]
#[CoversClass(\Pramnos\Audience\Rules\Rules::class)]
#[CoversClass(\Pramnos\Console\Commands\RulesRun::class)]
class AbsentTablesTest extends TestCase
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;
    use AdminScreen;

    private const PROBE = 'pramnos_audience_absent_probe';

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

    /** A database with users, groups, mail, tracking and the queue — and no audience table. */
    private function boot(string $type, string $host, int $port, string $user): void
    {
        require_once ROOT . '/database/migrations/framework/messaging/2026_10_21_000002_add_userid_to_mails.php';
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
        $this->db = $this->openProbe($this->admin, self::PROBE, $make, [
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\UserGroups\CreateUsergroupsTables::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Messaging\AddUseridToMails::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
        ]);
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory(ROOT . '/database/migrations/framework/queue', $app) as $migration) {
            $migration->up();
        }
        QueueManager::forgetSchemaCache();
        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)->disableOriginalConstructor()->getMock();
        $controller->application = $app;
        QueuedEvents::$queue = new QueueManager($controller);
        Installed::reset();
        \Pramnos\Email\MailLog::reset();

        $this->db->queryBuilder()->table('users')->insert(['username' => 'anna', 'email' => 'anna@example.com', 'language' => 'el']);
        $this->db->queryBuilder()->table('usergroups')->insert(['name' => 'Press']);
    }

    /** Give the application its database and its queue back. */
    protected function tearDown(): void
    {
        Event::forget();
        QueuedEvents::reset();
        PersonEvents::reset();
        Installed::reset();
        \Pramnos\Email\MailLog::reset();
        unset(Application::getInstance()->applicationInfo['email']);
        $this->clearRequest();
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->close();
    }

    /** The account's id. */
    private function anna(): int
    {
        return (int) $this->db->queryBuilder()->table('users')->where('username', 'anna')->first()->fields['userid'];
    }

    /** How many tasks the queue holds. */
    private function queued(): int
    {
        return count($this->db->queryBuilder()->table('queueitems')->getAll());
    }

    /**
     * Everything that would record or queue is a no-op: a dispatched PersonEvent (its listeners
     * still run), an event with refreshOn() on it, a named page, an email open and click, the
     * queued listeners run by a worker — no task, no row, no exception.
     */
    #[DataProvider('databases')]
    public function testNothingIsRecordedOrQueued(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $anna = $this->anna();
        $paid = new class ($anna) implements PersonEvent {
            public const EVENT = 'payment.made';

            /** @param int $who The person */
            public function __construct(private readonly int $who)
            {
            }

            /** The person. */
            public function personId(): ?int
            {
                return $this->who;
            }

            /** Nothing worth keeping. */
            public function properties(): array
            {
                return [];
            }
        };
        $heard = 0;
        Event::listen('payment.made', function () use (&$heard): void {
            $heard++;
        });
        Segments::refreshOn('profile.saved');
        PersonEvents::page('premium.viewed', 'Premium', '/el/premium*');
        Application::getInstance()->applicationInfo['email'] = ['tracking' => true];
        $this->db->queryBuilder()->table('mails')->insert(['status' => 1, 'frommail' => 'a@b.c', 'fromname' => 'A', 'tomail' => 'anna@example.com',
            'toname' => 'Anna', 'subject' => 'Hi', 'content' => '', 'date' => time(), 'module' => 'test', 'moduleinfo' => '',
            'extrainfo' => '', 'path' => '', 'hash' => md5('absent'), 'userid' => $anna]);
        $mail = (int) $this->db->queryBuilder()->table('mails')->orderBy('id', 'desc')->first()->fields['id'];
        \Pramnos\Email\Tracking::begin('anna@example.com', 'newsletter', 'Hi', $mail, 'absent-track');
        $this->signIn(1, $anna);
        $_SERVER['REQUEST_URI'] = '/el/premium';

        // Act
        Event::dispatch($paid);
        Event::fire('profile.saved', $anna);
        $page    = PersonEvents::visitedNow();
        $opened  = \Pramnos\Email\Tracking::recordOpen('absent-track', 'Mozilla/5.0', '10.0.0.1');
        $link    = \Pramnos\Email\Tracking::link('absent-track', 'https://example.com/');
        $clicked = \Pramnos\Email\Tracking::recordClick(urldecode(explode('c=', $link)[1]));
        PersonEvents::recordLater($anna, 'login');
        PersonEvents::record($anna, 'login');
        $worker  = [(new RecordPersonEvent())->handle($anna, 'login'), (new RefreshPersonSegments())->handle($anna)];
        $changes = Segments::recomputePerson($anna);

        // Assert
        $this->assertSame(1, $heard, 'the event\'s own listeners still run');
        $this->assertSame(0, $this->queued(), 'nothing was queued');
        $this->assertNull($page);
        $this->assertTrue($opened, 'the open is still counted by tracking');
        $this->assertSame('https://example.com/', $clicked);
        $this->assertSame([true, null], $worker, 'a task queued before the tables went away does nothing');
        $this->assertSame(['entered' => [], 'left' => []], $changes);
        $this->assertFalse($this->db->schema()->hasTable('person_events'), 'and nothing made a table');
    }

    /**
     * The scheduled commands succeed with one line each, every hour and day, rather than failing
     * and raising the scheduler's alerts.
     */
    #[DataProvider('databases')]
    public function testTheScheduledCommandsSkipQuietly(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $recompute = new CommandTester(new \Pramnos\Console\Commands\SegmentsRecompute());
        $prune     = new CommandTester(new \Pramnos\Console\Commands\AudiencePruneEvents());

        // Act
        $rules     = new CommandTester(new \Pramnos\Console\Commands\RulesRun());
        $codes = [$recompute->execute([]), $prune->execute([]), $rules->execute([])];
        \Pramnos\Audience\Rules\Engine::listen();

        // Assert
        $this->assertSame([0, 0, 0], $codes);
        $this->assertStringContainsString('No rules here', $rules->getDisplay());
        $this->assertSame([], \Pramnos\Audience\Rules\Rules::listenedEvents(), 'listen() listens to nothing');
        $this->assertSame([], \Pramnos\Audience\Rules\Rules::usingSegment(1));
        $this->assertSame(0, \Pramnos\Audience\Rules\Engine::due());
        $this->assertNull(\Pramnos\Audience\Rules\Engine::schedule(['kind' => 'event']));
        $this->assertNull(\Pramnos\Audience\Rules\Engine::prune());
        $this->assertCount(1, array_filter(explode("\n", trim($recompute->getDisplay()))));
        $this->assertCount(1, array_filter(explode("\n", trim($prune->getDisplay()))));
    }

    /**
     * The Segments and Consent lists screens leave the menu and, typed in, say they are not
     * installed; the person card still lists the person's groups; the audience catalogue answers
     * with no segments, lists or failure.
     */
    #[DataProvider('databases')]
    public function testTheScreensStayOutOfTheWay(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $anna  = $this->anna();
        $group = (int) $this->db->queryBuilder()->table('usergroups')->first()->fields['groupid'];
        $this->db->queryBuilder()->table('userstogroups')->insert(['userid' => $anna, 'groupid' => $group]);
        $this->signIn();
        $segments = $this->screen(\Pramnos\Application\Controllers\Segments::class);
        $lists    = $this->screen(\Pramnos\Application\Controllers\ConsentLists::class);
        $this->request('GET');

        // Act
        $rules    = $this->screen(\Pramnos\Application\Controllers\Rules::class);
        $answers = [$segments->display(), $lists->display(), $rules->display()];
        $panel   = \Pramnos\Html\AudiencePanel::person($anna);
        $catalog = \Pramnos\Application\Controllers\Audience::describe();
        $messages = [\Pramnos\Audience\SiteMessages::post($anna, 'banner', 'x'), \Pramnos\Audience\SiteMessages::pending($anna),
            \Pramnos\Html\SiteMessages::render(null, $anna), \Pramnos\Audience\SiteMessages::dismiss(1, $anna)];

        // Assert
        $this->assertFalse(Installed::segments());
        $this->assertFalse(Installed::consentLists());
        $this->assertSame([null, null, null], $answers);
        $this->assertSame([['error', 'Rules are not installed here yet: run migrate.']], $rules->flash);
        $this->assertSame([['error', 'Segments are not installed here yet: run migrate.']], $segments->flash);
        $this->assertSame([['error', 'Consent lists are not installed here yet: run migrate.']], $lists->flash);
        $this->assertStringContainsString('Press', $panel);
        $this->assertSame([], $catalog['segments']);
        $this->assertSame([], $catalog['consent']);
        $this->assertSame(['Press'], array_column($catalog['groups'], 'name'));
        $this->assertSame([null, [], '', false], $messages, 'no site messages, and no error');
    }
}
