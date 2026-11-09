<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Metrics\AlertNotification;
use Pramnos\Metrics\Alerts;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;

/**
 * A metric's alert that fires is told: in the bell of each person it names, and as a line in
 * its conversation — the team's channel, not a mail nobody reads.
 */
#[CoversClass(Alerts::class)]
#[CoversClass(AlertNotification::class)]
class AlertsToldTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The metrics forgotten, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        Metrics::reset();
        $this->probeTearDown();
    }

    /** A threshold crossed: maria's bell, and the ops channel, each told once. */
    #[DataProvider('databases')]
    public function testAnAlertIsToldInTheBellAndTheChannel(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/stats/2026_11_06_000001_create_metric_values_table.php';
        require_once ROOT . '/database/migrations/framework/stats/2026_11_09_000005_create_alert_rules_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Stats\CreateMetricValuesTable::class,
            \Pramnos\Framework\Migrations\Stats\CreateAlertRulesTable::class], $this->db);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'Europe/Athens'];
        Metrics::reset();
        Metrics::register(Metric::flow('test.payments', 'Payments')->source(static fn (): int => 0));
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $ops = Conversations::create('channel', 'ops', [$maria], $eleni);
        Metrics::compute(['test.payments'], ['day'], '2026-11-05', '2026-11-05', false, (int) strtotime('2026-11-06 10:00 UTC'));
        $rule = Alerts::create(['metric' => 'test.payments', 'kind' => 'threshold', 'op' => 'below', 'value' => 1, 'name' => 'Payments',
            'notify' => ['users' => [$maria, 999999], 'conversation' => $ops]]);

        // Act
        $fired = Alerts::evaluate((int) strtotime('2026-11-06 10:00 UTC'));
        $bell  = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $maria)->getAllOrFail();
        $lines = array_column(Messages::list($ops), 'body');

        // Assert
        $this->assertArrayHasKey($rule, $fired);
        $this->assertCount(1, $bell, 'maria\'s bell; somebody with no account, nobody');
        $this->assertStringContainsString('Payments was 0', (string) json_encode($bell[0], JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Payments was 0 in 2026-11-05', $lines[0]);
    }

    /**
     * A goal that turns from on track to behind is told once (FW-360, 16b): its owner in the bell,
     * a goal whose notice goes to a conversation as a line there; the turn is kept in the
     * changelog and dispatched as `goal.turned`. Its first state tells nobody, and a state that
     * stays tells nobody again.
     */
    #[DataProvider('databases')]
    public function testAGoalThatTurnsIsToldOnce(string $type, string $host, int $port, string $user): void
    {
        // Arrange — one payment a day until the 10th, none after
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/stats/2026_11_06_000001_create_metric_values_table.php';
        require_once ROOT . '/database/migrations/framework/stats/2026_11_09_000004_create_goals_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Stats\CreateMetricValuesTable::class,
            \Pramnos\Framework\Migrations\Stats\CreateGoalsTable::class], $this->db);
        $this->db->queryBuilder()->table(\Pramnos\Metrics\Goals::TABLE)->delete();
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC', 'audience' => false];
        Metrics::reset();
        $tenth = (int) strtotime('2026-11-11 00:00 UTC');
        Metrics::register(Metric::flow('test.paid', 'Payments')->source(static fn (\Pramnos\Metrics\Window $w): int => $w->startsAt() < $tenth ? 1 : 0));
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $ops = Conversations::create('channel', 'ops', [$maria], $eleni);
        $owned   = \Pramnos\Metrics\Goals::create(['metric' => 'test.paid', 'target' => 30, 'starts' => '2026-11-01', 'due' => '2026-11-30', 'owner' => $maria]);
        $channel = \Pramnos\Metrics\Goals::create(['metric' => 'test.paid', 'target' => 30, 'starts' => '2026-11-01', 'due' => '2026-11-30', 'notify' => 'conversation:' . $ops]);
        $heard = [];
        \Pramnos\Event\Event::listen(\Pramnos\Metrics\GoalTurned::EVENT, static function (array $goal, string $was, string $state) use (&$heard): void {
            $heard[] = $goal['id'] . ':' . $was . '>' . $state;
        });
        $check = function (string $day): array {
            Metrics::compute(['test.paid'], ['day'], '2026-11-01', $day, false, (int) strtotime($day . ' 23:00 UTC'));

            return \Pramnos\Metrics\Goals::check((int) strtotime($day . ' 23:00 UTC'));
        };

        // Act
        $first  = $check('2026-11-05');
        $turned = $check('2026-11-20');
        $again  = $check('2026-11-21');
        $bell   = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $maria)->getAllOrFail();
        $lines  = array_column(Messages::list($ops), 'body');

        // Assert
        $this->assertSame([], $first, 'on track at first: its first state tells nobody');
        $this->assertSame([$owned => 'Payments is behind', $channel => 'Payments is behind'], $turned);
        $this->assertSame([], $again, 'still behind: told once');
        $this->assertCount(1, $bell, 'the owner, once');
        $this->assertSame(['Payments is behind'], $lines, 'a line in the conversation');
        $this->assertSame([$owned . ':on_track>behind', $channel . ':on_track>behind'], $heard);
        $this->assertSame('behind', \Pramnos\Metrics\Goals::get($owned)['last_state']);
    }
}

