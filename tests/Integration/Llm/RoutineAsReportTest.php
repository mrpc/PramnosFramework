<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\ReportProposal;
use Pramnos\Metrics\Reports;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * A scheduled task of the assistant's sent as a metrics report from now on: the report its words
 * describe, with an analysis card that reads it as those words ask, mailed on its cadence to its
 * owner and its mail readers — and the task paused, so the report is what goes out.
 */
#[CoversClass(Routines::class)]
#[CoversClass(ReportProposal::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class RoutineAsReportTest extends LlmTestCase
{
    use ChatDatabase;

    /** The reports and the metrics forgotten, then the database let go. */
    protected function tearDown(): void
    {
        ReportProposal::useClient(null);
        Metrics::reset();
        if (isset($this->db)) {
            $this->db->queryBuilder()->table(Reports::TABLE)->delete();
            $this->db->queryBuilder()->table('users')->whereIn('email', ['owner@example.test', 'reader@example.test'])->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * A weekly question becomes a weekly report: its name, the cards the words describe and an
     * analysis card with the task's words, mailed to the owner and the reader who asked for it by
     * mail; the task is paused. A reminder, a one-off and somebody else's task are refused.
     */
    #[DataProvider('databases')]
    public function testATaskIsSentAsAReportFromNowOn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        require_once ROOT . '/database/migrations/framework/stats/2026_11_06_000002_create_reports_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Stats\CreateReportsTable::class], $this->db);
        $users = $this->db->queryBuilder()->table('users');
        $users->insert(['username' => 'routine-owner', 'email' => 'owner@example.test', 'usertype' => 98]);
        $owner = (int) $this->db->getInsertId();
        $this->db->queryBuilder()->table('users')->insert(['username' => 'routine-reader', 'email' => 'reader@example.test', 'usertype' => 98]);
        $reader = (int) $this->db->getInsertId();
        Metrics::reset();
        Metrics::register(Metric::flow('test.sales', 'Sales')->source(static fn (): int => 3));
        ReportProposal::useClient(static fn (): \Pramnos\Llm\Client => new class () extends \Pramnos\Llm\Client {
            public function __construct()
            {
            }

            public function askCheaply(string $systemPrompt, array $messages): array
            {
                return ['ok' => true, 'reply' => json_encode(['name' => 'Sales', 'grain' => 'month', 'compare' => 'previous', 'sections' => "# Sales\nSales | test.sales"])];
            }
        });
        $tasks  = new Routines(new Conversations(new BaseAssistant()));
        $scope  = new Scope($owner, null, Scope::ADMIN);
        $weekly = (int) $tasks->create($scope, ['kind' => 'report', 'title' => 'Weekly sales', 'prompt' => 'How did sales go?', 'cadence' => 'weekly', 'day' => 1, 'at' => '08:00'])['routine_id'];
        // A colleague who reads it by mail (as a shared task's reader subscribes).
        $this->db->queryBuilder()->table('pramnos.ai_task_subscriptions')->insert(['routine_id' => $weekly, 'userid' => $reader, 'email' => true, 'created_at' => \Pramnos\Llm\CallLog::instant(time())]);
        $remind = (int) $tasks->create($scope, ['kind' => 'reminder', 'title' => 'Call', 'prompt' => 'Call', 'cadence' => 'daily', 'at' => '08:00'])['routine_id'];

        // Act
        $made     = $tasks->asReport($scope, $weekly);
        $reminder = $tasks->asReport($scope, $remind);
        $theirs   = $tasks->asReport(new Scope($reader, null, Scope::ADMIN), $weekly);
        $row      = $this->db->queryBuilder()->table(Reports::TABLE)->where('reportid', $made)->getAllOrFail()[0];
        $layout   = json_decode((string) $row['definition'], true)['layout'];

        // Assert
        $this->assertIsInt($made);
        $this->assertSame(['Weekly sales', 'week', 'owner@example.test, reader@example.test', 'private'],
            [$row['name'], $row['schedule'], $row['recipients'], $row['visibility']], 'its name, its cadence, its readers by mail');
        $this->assertSame(['report.section~s0', 'report.table~t0', 'report.analysis~a'], array_column($layout, 'id'));
        $this->assertSame('How did sales go?', $layout[2]['settings']['instructions'], 'the analysis reads it as the task asked');
        $this->assertFalse($tasks->find($scope, $weekly)['active'], 'the task paused: the report goes out instead');
        $this->assertSame('Only a daily, weekly or monthly question or report is sent as a report.', $reminder);
        $this->assertSame('There is no such scheduled task.', $theirs);
    }
}
