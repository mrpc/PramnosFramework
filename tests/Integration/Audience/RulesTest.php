<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;
use Pramnos\Audience\Rules\Definition;
use Pramnos\Audience\Rules\Engine;
use Pramnos\Audience\Rules\Rules;
use Pramnos\Audience\Segments;
use Pramnos\Event\Event;

/**
 * Rules on MySQL, prefixed MySQL and PostgreSQL: people taken in by a trigger, through actions,
 * waits, branches and splits, out by an exit or a goal — each step a task that moves a run only
 * from where it is, every decision in the run log.
 *
 * anna is a Greek parent, bob an English professional, cara a Greek professional.
 */
#[CoversClass(Engine::class)]
#[CoversClass(Rules::class)]
#[CoversClass(Context::class)]
#[CoversClass(Actions::class)]
#[CoversClass(Definition::class)]
#[CoversClass(\Pramnos\Audience\Rules\RuleTask::class)]
class RulesTest extends TestCase
{
    use AudienceProbe;

    /** @var list<array{person: int, tag: string, origin: string}> What the test action was asked to do */
    private array $done = [];

    /** A test action — on a channel, so consent, caps and hours apply — that records each call. */
    private function noteAction(?string $channel = 'email', string $status = 'sent'): void
    {
        Actions::define('note', 'Note', ['tag' => ['label' => 'Tag', 'type' => 'text']],
            function (int $person, array $params, Context $context) use ($status): array {
                $this->done[] = ['person' => $person, 'tag' => (string) ($params['tag'] ?? ''), 'origin' => $context->origin()];

                return ['status' => $status, 'detail' => ['tag' => $params['tag'] ?? '', 'mail' => $params['mail'] ?? null]];
            }, $channel);
    }

    /** An action step of the test action. */
    private static function note(string $tag, ?string $next = null): array
    {
        return ['type' => 'action', 'action' => 'note', 'params' => ['tag' => $tag], 'next' => $next];
    }

    /** Make a rule and switch it on. */
    private function rule(array $definition, string $status = 'active', string $name = 'Test rule'): int
    {
        $id = Rules::create($name, $definition, owner: 2);
        Rules::setStatus($id, $status, 2);

        return $id;
    }

    /** The outcomes of a person's way through a rule, in order. @return list<string> */
    private function outcomes(int $rule, string $person): array
    {
        return array_map(static fn (array $row): string => $row['outcome'] . ($row['step'] === null ? '' : ':' . $row['step']),
            $this->db->queryBuilder()->table('rule_log')->where('ruleid', $rule)->where('personid', $this->ids[$person])->orderBy('id')->getAll());
    }

    /** A run's row. */
    private function runOf(int $rule, string $person): array
    {
        return $this->db->queryBuilder()->table('rule_runs')->where('ruleid', $rule)->where('personid', $this->ids[$person])->orderBy('runid', 'desc')->getAll()[0] ?? [];
    }

    /**
     * An event fires: the listener writes a task and nothing else; the worker takes in the people
     * the rule's "who" matches, and each goes through the actions and branches with every
     * decision logged — the origin of each action naming the rule, version, step and run.
     */
    #[DataProvider('databases')]
    public function testAnEventTakesMatchingPeopleThroughTheSteps(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $rule  = $this->rule([
            'trigger' => ['type' => 'event', 'event' => 'payment.made'],
            'who'     => ['conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2]]],
            'start'   => 'thanks',
            'steps'   => [
                'thanks' => self::note('thanks', 'greek'),
                'greek'  => ['type' => 'branch', 'filter' => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]], 'yes' => 'offer', 'no' => null],
                'offer'  => self::note('offer'),
            ],
        ]);
        Engine::listen();

        // Act
        foreach (['anna', 'bob', 'cara'] as $name) {
            Event::fire('payment.made', $this->ids[$name]);
        }
        Event::fire('payment.made', 'nobody');
        $listened = count($queue->tasks);
        $said     = $this->work($queue);

        // Assert
        $this->assertSame(3, $listened, 'one task per event about a person, nothing more in the request');
        $this->assertStringContainsString('0 entered', $said[0], 'anna is not a professional');
        $this->assertSame([[$this->ids['bob'], 'thanks'], [$this->ids['cara'], 'thanks'], [$this->ids['cara'], 'offer']],
            array_map(static fn (array $d): array => [$d['person'], $d['tag']], $this->done));
        $runId = (int) $this->runOf($rule, 'cara')['runid'];
        $this->assertSame('rule:' . $rule . ':v1:offer:run' . $runId, $this->done[2]['origin']);
        $this->assertSame(['entered', 'done:thanks', 'branch:greek', 'done:offer', 'finished'], $this->outcomes($rule, 'cara'));
        $this->assertSame(['entered', 'done:thanks', 'branch:greek', 'finished'], $this->outcomes($rule, 'bob'));
        $this->assertSame('done', $this->runOf($rule, 'bob')['status']);
        $this->assertSame([], $this->runOf($rule, 'anna'));
        $this->assertSame(['payment.made'], Rules::listenedEvents());
    }

    /**
     * "First time" takes a person in once ever; "every time" again after the last run ended, but
     * never twice at once.
     */
    #[DataProvider('databases')]
    public function testEntryFirstOrEvery(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $first = $this->rule(['trigger' => ['type' => 'event', 'event' => 'login'], 'entry' => 'first', 'steps' => ['a' => self::note('a')]]);
        $every = $this->rule(['trigger' => ['type' => 'event', 'event' => 'login'], 'entry' => 'every',
            'steps' => ['w' => ['type' => 'wait', 'seconds' => 3600, 'next' => 'a'], 'a' => self::note('b')]]);

        // Act
        Engine::onEvent('login', $this->ids['bob']);
        Engine::onEvent('login', $this->ids['bob']);   // the "every" run is still waiting
        $this->work($queue);                            // both finish
        Engine::onEvent('login', $this->ids['bob']);
        $this->work($queue);

        // Assert
        $runs = fn (int $rule): int => count($this->db->queryBuilder()->table('rule_runs')->where('ruleid', $rule)->getAll());
        $this->assertSame(1, $runs($first));
        $this->assertSame(2, $runs($every));
    }

    /**
     * A wait for an event goes on when the event comes, and its timeout — still queued — then does
     * nothing; with no event, the timeout takes the other way.
     */
    #[DataProvider('databases')]
    public function testAWaitForAnEventResumesOrTimesOut(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $rule  = $this->rule(['trigger' => ['type' => 'event', 'event' => 'signed.up'], 'steps' => [
            'w'    => ['type' => 'wait_event', 'event' => 'profile.done', 'seconds' => 86400, 'next' => 'done', 'timeout' => 'nudge'],
            'done' => self::note('thanks'),
            'nudge' => self::note('nudge'),
        ]]);
        $timeout = static fn (array $task): bool => !empty($task['job']['timeout']);

        // Act
        Engine::onEvent('signed.up', $this->ids['bob']);
        Engine::onEvent('signed.up', $this->ids['cara']);
        $this->work($queue, $timeout);
        $waiting = [$this->runOf($rule, 'bob')['status'], $this->runOf($rule, 'bob')['waitingfor']];
        $delays  = array_column($queue->tasks, 'delay');
        Engine::onEvent('profile.done', $this->ids['bob']);
        $this->work($queue);

        // Assert
        $this->assertSame(['waiting', 'profile.done'], $waiting);
        $this->assertSame([86400, 86400], $delays);
        $this->assertSame([[$this->ids['bob'], 'thanks'], [$this->ids['cara'], 'nudge']], array_map(static fn (array $d): array => [$d['person'], $d['tag']], $this->done));
        $this->assertSame(['entered', 'waiting:w', 'resumed:w', 'done:done', 'finished'], $this->outcomes($rule, 'bob'), 'the late timeout did nothing');
        $this->assertSame(['entered', 'waiting:w', 'timeout:w', 'done:nudge', 'finished'], $this->outcomes($rule, 'cara'));
    }

    /**
     * Before a message: in shadow mode nothing is sent and the log says what would have been;
     * a person outside the consent list is skipped; a frequency cap holds the second message.
     */
    #[DataProvider('databases')]
    public function testShadowConsentAndCapsGuardTheMessages(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction('email');
        $queue = $this->fakeQueue();
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert(['email' => 'bob@example.com', 'list' => 'all', 'source' => 'page', 'created_at' => time()]);
        $mail   = ConsentLists::create('Service mail', 'email', 'everyone');
        $steps  = ['one' => self::note('one', 'two'), 'two' => self::note('two')];
        $shadow = $this->rule(['trigger' => ['type' => 'event', 'event' => 'x'], 'steps' => $steps], 'shadow');
        $real   = $this->rule(['trigger' => ['type' => 'event', 'event' => 'y'], 'steps' => $steps, 'consent' => $mail, 'caps' => ['email' => ['max' => 1, 'days' => 1]]]);

        // Act
        Engine::onEvent('x', $this->ids['cara']);
        Engine::onEvent('y', $this->ids['bob']);
        Engine::onEvent('y', $this->ids['cara']);
        $this->work($queue);

        // Assert
        $this->assertSame(['entered', 'shadow:one', 'shadow:two', 'finished'], $this->outcomes($shadow, 'cara'));
        $this->assertSame(['entered', 'skipped:one', 'skipped:two', 'finished'], $this->outcomes($real, 'bob'), 'bob left everything');
        $this->assertSame(['entered', 'sent:one', 'skipped:two', 'finished'], $this->outcomes($real, 'cara'), 'one email a day');
        $this->assertSame([[$this->ids['cara'], 'one']], array_map(static fn (array $d): array => [$d['person'], $d['tag']], $this->done));
        $reasons = array_map(static fn (array $r): string => json_decode($r['detail'], true)['reason'],
            $this->db->queryBuilder()->table('rule_log')->where('outcome', 'skipped')->orderBy('id')->getAll());
        $this->assertSame(['consent', 'consent', 'cap'], $reasons);
        $this->assertSame('1', (string) $this->runOf($shadow, 'cara')['shadow']);
    }

    /**
     * Outside its sending hours a message waits for them to open; the window may cross midnight.
     */
    #[DataProvider('databases')]
    public function testAMessageWaitsForItsHours(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction('email');
        $queue = $this->fakeQueue();
        $now   = (int) date('G') * 60 + (int) date('i');
        $later = sprintf('%02d:%02d', intdiv(($now + 120) % 1440, 60), ($now + 120) % 60);
        $after = sprintf('%02d:%02d', intdiv(($now + 180) % 1440, 60), ($now + 180) % 60);
        $rule  = $this->rule(['trigger' => ['type' => 'event', 'event' => 'x'], 'steps' => ['m' => self::note('m')], 'hours' => ['from' => $later, 'to' => $after]]);
        $day   = mktime(12, 0, 0, 1, 15, 2026);

        // Act
        Engine::onEvent('x', $this->ids['bob']);
        $this->work($queue, static fn (array $t): bool => $t['delay'] > 0);
        $waits = [
            Engine::untilOpen(['from' => '09:00', 'to' => '21:00'], $day),
            Engine::untilOpen(['from' => '13:00', 'to' => '21:00'], $day),
            Engine::untilOpen(['from' => '09:00', 'to' => '11:00'], $day),
            Engine::untilOpen(['from' => '22:00', 'to' => '06:00'], mktime(23, 0, 0, 1, 15, 2026)),
            Engine::untilOpen(['from' => '22:00', 'to' => '06:00'], $day),
        ];

        // Assert
        $this->assertSame([], $this->done, 'not sent outside the hours');
        $this->assertSame(['entered', 'waiting:m'], $this->outcomes($rule, 'bob'));
        $this->assertEqualsWithDelta(7200, $queue->tasks[0]['delay'], 70);
        $this->assertSame([0, 3600, 21 * 3600, 0, 10 * 3600], $waits);
    }

    /**
     * An exit condition takes a person out at the next step; a goal event within its days ends the
     * run as reached.
     */
    #[DataProvider('databases')]
    public function testExitAndGoalEndARun(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $rule  = $this->rule(['trigger' => ['type' => 'event', 'event' => 'x'],
            'steps' => ['w' => ['type' => 'wait', 'seconds' => 3600, 'next' => 'm'], 'm' => self::note('m')],
            'exit'  => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'en']]],
            'goal'  => ['event' => 'payment.made', 'days' => 10]]);

        // Act
        Engine::onEvent('x', $this->ids['cara']);
        Engine::onEvent('x', $this->ids['anna']);
        $this->work($queue, static fn (array $t): bool => $t['delay'] > 0);
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids['anna'])->update(['language' => 'en']);
        Engine::onEvent('payment.made', $this->ids['cara']);
        $this->work($queue);
        Engine::onEvent('x', $this->ids['bob']);   // who exits already is not taken in

        // Assert
        $this->assertSame('goal', $this->runOf($rule, 'cara')['status']);
        $this->assertSame('exited', $this->runOf($rule, 'anna')['status']);
        $this->assertSame([], $this->done);
        $this->assertSame([], $this->runOf($rule, 'bob'));
        $this->assertSame(['entered', 'waiting:w', 'exited'], $this->outcomes($rule, 'anna'));
    }

    /**
     * A split puts each person in a variant by share — the same one every time — and records it.
     */
    #[DataProvider('databases')]
    public function testASplitIsStablePerPerson(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $rule  = $this->rule(['trigger' => ['type' => 'event', 'event' => 'x'], 'entry' => 'every', 'steps' => [
            's' => ['type' => 'split', 'variants' => [['key' => 'A', 'share' => 50, 'next' => 'a'], ['key' => 'B', 'share' => 50, 'next' => 'b']]],
            'a' => self::note('A'), 'b' => self::note('B'),
        ]]);

        // Act
        foreach ([1, 2] as $round) {
            foreach (['anna', 'bob', 'cara'] as $name) {
                Engine::onEvent('x', $this->ids[$name]);
            }
            $this->work($queue);
        }

        // Assert
        $byPerson = [];
        foreach ($this->done as $d) {
            $byPerson[$d['person']][] = $d['tag'];
        }
        foreach ($byPerson as $tags) {
            $this->assertCount(2, $tags);
            $this->assertSame($tags[0], $tags[1], 'the same variant both times');
        }
        $this->assertContains($this->runOf($rule, 'bob')['variant'], ['A', 'B']);
    }

    /**
     * A branch on a message: opened when its tracking says so — the message found through the log
     * of the step that sent it.
     */
    #[DataProvider('databases')]
    public function testABranchOnWhetherTheMessageWasOpened(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Actions::define('mail', 'Mail', [], static fn (int $person, array $params, Context $c): array
            => ['status' => 'sent', 'detail' => ['mail' => $person * 10]], 'email');
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $this->db->queryBuilder()->table('pramnos.emailtracking')->insert(['tracking_id' => 'opened', 'mailid' => $this->ids['bob'] * 10,
            'recipient' => 'bob@example.com', 'list' => 'news', 'subject' => 'Hi', 'sent_at' => time(), 'opens' => 2, 'clicks' => 0]);
        $rule = $this->rule(['trigger' => ['type' => 'event', 'event' => 'x'], 'steps' => [
            'send'  => ['type' => 'action', 'action' => 'mail', 'params' => [], 'next' => 'check'],
            'check' => ['type' => 'branch', 'message' => ['step' => 'send', 'status' => 'opened'], 'yes' => 'yes', 'no' => 'no'],
            'yes'   => self::note('opened'), 'no' => self::note('not opened'),
        ]]);

        // Act
        Engine::onEvent('x', $this->ids['bob']);
        Engine::onEvent('x', $this->ids['cara']);
        $this->work($queue);
        $status = Engine::messageStatus((int) $this->runOf($rule, 'bob')['runid'], 'send');

        // Assert
        $this->assertSame([[$this->ids['bob'], 'opened'], [$this->ids['cara'], 'not opened']], array_map(static fn (array $d): array => [$d['person'], $d['tag']], $this->done));
        $this->assertSame(['mail' => $this->ids['bob'] * 10, 'delivered' => true, 'opened' => true, 'clicked' => false], $status);
    }

    /**
     * A once rule takes everybody who matches when switched on; a schedule takes them when its
     * minute is due; a paused rule's runs wait an hour at a time.
     */
    #[DataProvider('databases')]
    public function testOnceScheduleAndPause(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $once  = $this->rule(['trigger' => ['type' => 'once'], 'who' => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]], 'steps' => ['a' => self::note('once')]]);
        $cron  = $this->rule(['trigger' => ['type' => 'schedule', 'cron' => '30 10 * * *'], 'steps' => ['a' => self::note('cron')]]);
        $paused = $this->rule(['trigger' => ['type' => 'event', 'event' => 'x'], 'steps' => ['a' => self::note('paused')]]);

        // Act
        $this->work($queue);
        $notDue = Engine::due(new \DateTimeImmutable('2026-10-21 10:29'));
        $due    = Engine::due(new \DateTimeImmutable('2026-10-21 10:30'));
        $this->work($queue);
        Engine::onEvent('x', $this->ids['bob']);
        Rules::setStatus($paused, 'paused', 2);
        $this->work($queue, static fn (array $t): bool => $t['delay'] > 0);

        // Assert
        $tags = array_count_values(array_column($this->done, 'tag'));
        $this->assertSame(2, $tags['once'], 'anna and cara');
        $this->assertSame(3, $tags['cron']);
        $this->assertSame([0, 1], [$notDue, $due]);
        $this->assertArrayNotHasKey('paused', $tags);
        $this->assertSame(3600, $queue->tasks[0]['delay'] ?? null, 'the paused run looks again in an hour');
        $this->assertSame("Rule {$paused} is not running.", Engine::everybody($paused));
        $this->assertSame('Nothing to do: {"kind":"what"}', Engine::handle(['kind' => 'what']));
    }

    /**
     * The store: a changed definition is a new version, an unchanged one is not; statuses and
     * changes are audited; a running rule is not deleted; a segment or consent list a rule reads
     * is not either; a definition that does not check is refused.
     */
    #[DataProvider('databases')]
    public function testVersionsAuditAndUse(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction();
        $segment = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        $list    = ConsentLists::create('Mail', 'email', 'everyone');
        $base    = ['trigger' => ['type' => 'segment_entered', 'segment' => $segment], 'consent' => $list, 'steps' => ['a' => self::note('a')]];

        // Act
        $id    = $this->rule($base, 'draft', 'Welcome');
        $same  = Rules::update($id, ['definition' => $base], 2);
        $next  = Rules::update($id, ['name' => 'Welcome back', 'definition' => ['steps' => ['a' => self::note('b')]] + $base], 2);
        Rules::setStatus($id, 'active', 2);
        $refusals = [];
        foreach ([fn () => Rules::delete($id), fn () => Segments::delete($segment), fn () => ConsentLists::delete($list),
            fn () => Rules::setStatus($id, 'on'), fn () => Rules::create(' ', $base), fn () => Rules::update(999, [])] as $call) {
            try {
                $call();
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        Rules::setStatus($id, 'paused', 2);
        Rules::delete($id, 2);

        // Assert
        $this->assertSame([1, 2], [$same, $next]);
        $this->assertSame(['Pause the rule before deleting it.', 'This segment is in use: rule Welcome back',
            'This consent list is in use: rule Welcome back', 'A rule is draft, shadow, active, paused.', 'A rule needs a name.', 'No rule 999.'], $refusals);
        $events = array_column($this->db->queryBuilder()->table('authserver.audit_log')->where('object_type', 'rule')->orderBy('auditid')->getAll(), 'event_type');
        $this->assertSame(['rule.created', 'rule.status', 'rule.updated', 'rule.updated', 'rule.status', 'rule.status', 'rule.deleted'], $events);
        $this->assertNull(Rules::get($id));
        $this->assertSame([], $this->db->queryBuilder()->table('rule_versions')->where('ruleid', $id)->getAll());
    }

    /**
     * A segment trigger takes in whoever enters the segment, through the segment's own event.
     */
    #[DataProvider('databases')]
    public function testEnteringASegmentStartsARule(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue   = $this->fakeQueue();
        $segment = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($segment);
        $rule = $this->rule(['trigger' => ['type' => 'segment_entered', 'segment' => $segment], 'steps' => ['a' => self::note('welcome')]]);
        Engine::listen();
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids['bob'])->update(['language' => 'el']);

        // Act
        Segments::recompute($segment);
        $this->work($queue);
        $versions = Rules::versions($rule);

        // Assert
        $this->assertSame([[$this->ids['bob'], 'welcome']], array_map(static fn (array $d): array => [$d['person'], $d['tag']], $this->done));
        $this->assertSame([['version' => 1, 'by' => 2]], array_map(static fn (array $v): array => ['version' => $v['version'], 'by' => $v['by']], $versions));
        $this->assertSame([], $this->outcomes($rule, 'anna'));
    }

    /**
     * What can go wrong after a rule was saved is logged and moves the run on, never stops the
     * worker: an action no longer declared, an action that throws, a step that is not there, a
     * rule that is gone, a queue that refuses the task, tracking that is not installed.
     */
    #[DataProvider('databases')]
    public function testWhatGoesWrongIsLoggedAndPassedOver(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Actions::define('boom', 'Boom', [], static function (): array {
            throw new \RuntimeException('provider down');
        });
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        $rule  = $this->rule(['trigger' => ['type' => 'event', 'event' => 'x'], 'steps' => [
            'gone' => self::note('gone', 'boom'), 'boom' => ['type' => 'action', 'action' => 'boom', 'params' => [], 'next' => null],
        ]]);
        $orphan = $this->rule(['trigger' => ['type' => 'event', 'event' => 'y'], 'steps' => ['a' => self::note('a')]]);

        // Act
        Actions::reset();
        Actions::define('boom', 'Boom', [], static function (): array {
            throw new \RuntimeException('provider down');
        });
        Engine::onEvent('x', $this->ids['bob']);
        $this->work($queue);
        Engine::onEvent('y', $this->ids['cara']);
        Engine::onEvent('y', $this->ids['anna']);
        $tasks = $queue->tasks;
        $queue->tasks = [];
        $annaRun = (int) $this->runOf($orphan, 'anna')['runid'];
        $this->db->queryBuilder()->table('rule_runs')->where('runid', (int) $this->runOf($orphan, 'cara')['runid'])->update(['step' => 'ghost']);
        $ghost = Engine::handle($tasks[0]['job']);
        $this->db->queryBuilder()->table('rules')->where('ruleid', $orphan)->delete();
        $lost  = Engine::step($annaRun, 0);
        Engine::$queue = new class extends \Pramnos\Queue\QueueManager {
            /** Nothing to connect to. */
            public function __construct()
            {
            }

            /** A queue that is down. */
            public function addKeyed(string $taskType, mixed $data, ?string $key = null, int $delaySeconds = 0, int $ceilingSeconds = 0, int $priority = 10, int $maxAttempts = 3): int
            {
                throw new \RuntimeException('queue down');
            }
        };
        $refused = Engine::schedule(['kind' => 'event']);
        $this->db->schema()->dropTableIfExists('pramnos.emailtracking');
        $this->probeChanged($this->db->type);
        $this->db->queryBuilder()->table('rule_log')->insert(['runid' => 5, 'ruleid' => 1, 'version' => 1, 'personid' => 1, 'step' => 'm', 'outcome' => 'sent', 'detail' => '{"mail":3}', 'at' => time()]);
        $untracked = Engine::messageStatus(5, 'm');

        // Assert
        $outcomes = $this->outcomes($rule, 'bob');
        $this->assertSame(['entered', 'error:gone', 'error:boom', 'finished'], $outcomes);
        $reasons = array_map(static fn (array $r): string => json_decode($r['detail'], true)['reason'],
            $this->db->queryBuilder()->table('rule_log')->where('ruleid', $rule)->where('outcome', 'error')->orderBy('id')->getAll());
        $this->assertSame(['no action note', 'provider down'], $reasons);
        $this->assertStringContainsString('no step ghost', $ghost);
        $this->assertSame('failed', $this->runOf($orphan, 'cara')['status']);
        $this->assertSame("Run {$annaRun}: its rule is gone.", $lost);
        $this->assertNull($refused);
        $this->assertSame(['mail' => 3, 'delivered' => true, 'opened' => false, 'clicked' => false], $untracked);
    }

    /**
     * Taking everybody in goes a page at a time, each page queuing the next; leaving a segment is a
     * trigger too.
     */
    #[DataProvider('databases')]
    public function testEverybodyGoesByPagesAndLeavingIsATrigger(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->noteAction(null, 'done');
        $queue = $this->fakeQueue();
        Engine::$page = 2;
        $segment = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($segment);
        $left = $this->rule(['trigger' => ['type' => 'segment_left', 'segment' => $segment], 'steps' => ['a' => self::note('bye')]]);
        Engine::listen();

        // Act
        try {
            $once = $this->rule(['trigger' => ['type' => 'once'], 'steps' => ['a' => self::note('all')]]);
            $said = $this->work($queue);
        } finally {
            Engine::$page = 500;
        }
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids['anna'])->update(['language' => 'en']);
        Segments::recompute($segment);
        $this->work($queue);

        // Assert
        $this->assertSame(3, count(array_filter($this->done, static fn (array $d): bool => $d['tag'] === 'all')));
        $this->assertStringContainsString('2 of 2 entered', implode(' | ', $said), 'the first page');
        $this->assertSame([[$this->ids['anna'], 'bye']], array_values(array_map(static fn (array $d): array => [$d['person'], $d['tag']],
            array_filter($this->done, static fn (array $d): bool => $d['tag'] === 'bye'))));
        $this->assertSame('done', $this->runOf($left, 'anna')['status']);
        $this->assertNotNull($once);
    }
}
