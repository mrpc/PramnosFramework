<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;
use Pramnos\Audience\Rules\Engine;
use Pramnos\Audience\Rules\Rules;
use Pramnos\Audience\Rules\Tester;
use Pramnos\Audience\Segments;

/**
 * Trying a rule before it runs, on MySQL, prefixed MySQL and PostgreSQL: why one person would or
 * would not come in, how many the last days would have brought, a test send to oneself filled with
 * a real person's details, and what a new version changes about who comes in.
 */
#[CoversClass(Tester::class)]
#[CoversClass(Context::class)]
#[CoversClass(Actions::class)]
class RuleTesterTest extends TestCase
{
    use AudienceProbe;

    /** A rule over professionals, with an email step and a branch on Greek. */
    private function rule(array $override = []): int
    {
        $list = ConsentLists::create('Mail', 'email', 'everyone');

        return Rules::create('Welcome', $override + [
            'trigger' => ['type' => 'event', 'event' => 'payment.made'],
            'who'     => ['conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2]]],
            'steps'   => [
                'mail'  => ['type' => 'action', 'action' => 'email', 'params' => ['template' => 'rule.welcome'], 'next' => 'greek'],
                'greek' => ['type' => 'branch', 'filter' => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]], 'yes' => 'wait', 'no' => null],
                'wait'  => ['type' => 'wait', 'seconds' => 3600, 'next' => null],
            ],
            'exit'    => ['conditions' => [['attribute' => 'active', 'op' => 'is', 'value' => 0]]],
            'consent' => $list,
        ], 2);
    }

    /**
     * Why: a parent is not who the rule is for; a professional comes in, and each step says what it
     * would meet; once in, "first time" says entered before; an exit that matches says so.
     */
    #[DataProvider('databases')]
    public function testWhyAPersonWouldOrWouldNotComeIn(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $rule = $this->rule();
        $this->fakeQueue();

        // Act
        $anna = Tester::why($rule, $this->ids['anna']);
        $bob  = Tester::why($rule, $this->ids['bob']);
        $cara = Tester::why($rule, $this->ids['cara']);
        Rules::setStatus($rule, 'active', 2);
        Engine::onEvent('payment.made', $this->ids['bob']);
        $again = Tester::why($rule, $this->ids['bob']);

        // Assert
        $this->assertSame([false, ['who does not match']], [$anna['enters'], $anna['reasons']]);
        $this->assertSame([true, []], [$bob['enters'], $bob['reasons']]);
        $this->assertSame(['action' => 'email', 'channel' => 'email', 'consent' => true], $bob['steps']['mail']);
        $this->assertFalse($bob['steps']['greek']['branch']['ok'], 'bob writes English');
        $this->assertSame(['type' => 'wait'], $bob['steps']['wait']);
        $this->assertSame(['the exit matches'], $cara['reasons'], 'cara is inactive');
        $this->assertSame(['entered before'], $again['reasons']);
    }

    /**
     * A simulation counts the people the last days' events would have brought in who match now,
     * and the messages each channel would have sent at most; a once rule counts who matches.
     */
    #[DataProvider('databases')]
    public function testASimulationCountsTheLastDays(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $rule = $this->rule();
        foreach (['anna' => 1, 'bob' => 3, 'cara' => 20] as $name => $daysAgo) {
            PersonEvents::record($this->ids[$name], 'payment.made', [], time() - $daysAgo * 86400);
        }
        $segment = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($segment);

        // Act
        $week    = Tester::simulate($rule, 7);
        $month   = Tester::simulate($rule, 30);
        $entered = Tester::simulate(['trigger' => ['type' => 'segment_entered', 'segment' => $segment], 'steps' => []], 7);
        $left    = Tester::simulate(['trigger' => ['type' => 'segment_left', 'segment' => $segment], 'steps' => []], 7);
        $once    = Tester::simulate(['trigger' => ['type' => 'once'], 'steps' => ['b' => ['type' => 'action', 'action' => 'bell', 'params' => []]]], 7);

        // Assert
        $this->assertSame(['entered' => 1, 'messages' => ['email' => 1], 'basis' => 'the event log'], $week, 'bob; anna is a parent');
        $this->assertSame(2, $month['entered'], 'and cara, 20 days ago');
        $this->assertSame(['entered' => 0, 'messages' => [], 'basis' => 'entries into the segment'], $entered, 'its first working out is its start, not entries');
        $this->assertSame(0, $left['entered']);
        $this->assertSame(['entered' => 3, 'messages' => ['site' => 3], 'basis' => 'who matches now'], $once);
    }

    /**
     * A test send goes to the account that asked, filled with the person's details and in the
     * person's language, marked as a test; a step that is not an action is refused.
     */
    #[DataProvider('databases')]
    public function testATestSendGoesToMeWithTheirDetails(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->db->queryBuilder()->table('mailtemplates')->insert(['title' => 'W', 'defaulttext' => '<p>Hi</p>', 'defaultsubject' => 'Γεια {firstname}',
            'category' => 'rule.welcome', 'language' => 'el', 'type' => 0, 'sound' => '', 'sendmethod' => 0, 'emailtemplate' => '']);
        $rule = $this->rule();

        // Act
        $sent = Tester::testSend($rule, 'mail', $this->ids['cara'], $this->ids['bob']);
        $refusals = [];
        foreach ([fn () => Tester::testSend($rule, 'greek', 1, 1), fn () => Tester::testSend(999, 'mail', 1, 1), fn () => Tester::why(999, 1)] as $call) {
            try {
                $call();
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }

        // Assert
        $this->assertSame('sent', $sent['status']);
        $row = $this->db->queryBuilder()->table('mails')->where('id', $sent['detail']['mail'])->getAll()[0];
        $this->assertSame(['bob@example.com', '[Test] Γεια Cara', $this->ids['bob']], [$row['tomail'], $row['subject'], (int) $row['userid']]);
        $this->assertSame(["Rule {$rule} has no action step greek.", 'No rule 999.', 'No rule 999.'], $refusals);
        $this->assertSame([], $this->db->queryBuilder()->table('rule_log')->getAll(), 'not part of any run');
    }

    /**
     * A new version's who, against the old: how many it takes in that the old did not, and leaves
     * out.
     */
    #[DataProvider('databases')]
    public function testAVersionDiffSaysWhoComesInAndWhoGoes(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $rule = $this->rule(['who' => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]]);
        $definition = Rules::get($rule)['definition'];
        Rules::update($rule, ['definition' => ['who' => ['conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2]]]] + $definition], 2);

        // Act
        $diff = Tester::diff($rule, 1, 2);

        // Assert
        $this->assertSame(['before' => 2, 'after' => 2, 'in' => 1, 'out' => 1], $diff, 'bob in, anna out');
        $this->expectExceptionMessage("Rule {$rule} has no version 9.");
        Tester::diff($rule, 1, 9);
    }
}
