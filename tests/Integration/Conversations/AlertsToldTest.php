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
}
