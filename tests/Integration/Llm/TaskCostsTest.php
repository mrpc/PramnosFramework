<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\TaskCosts;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

// SchedulingAnalyst, an assistant that may schedule, is declared there under a name the
// autoloader cannot map to a file.
require_once __DIR__ . '/AnalystTasksTest.php';

/**
 * What the scheduled tasks cost, read from `ai_task_runs.cost` — on MySQL and PostgreSQL.
 *
 * What holds: a task's runs are counted and summed; a successful run without a price is counted
 * apart rather than as zero; a window leaves older runs out; a deleted task is still listed, by
 * its id; and the admin card states the total against everything the call log priced. Read from
 * what was recorded, so nothing is priced twice.
 */
#[CoversClass(TaskCosts::class)]
class TaskCostsTest extends LlmTestCase
{
    use ChatDatabase;

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Record one run of a task, as Routines::record() writes it. */
    private function recordRun(int $routineId, string $status, ?float $cost, int $startedAt): void
    {
        $this->db->queryBuilder()->table('pramnos.ai_task_runs')->insert([
            'routine_id' => $routineId, 'kind' => 'report', 'status' => $status, 'cost' => $cost,
            'started_at' => CallLog::instant($startedAt), 'finished_at' => CallLog::instant($startedAt + 5),
        ]);
    }

    /**
     * byTask() counts, sums and sets apart the unpriced; a window leaves older runs out; titles()
     * names what still exists.
     */
    #[DataProvider('databases')]
    public function testCostsPerTaskAndWindow(string $type, string $host, int $port): void
    {
        // Arrange — one task with two priced runs, an unpriced one and an old one
        $this->connectDatabase($type, $host, $port);
        $tasks = new Routines(new \Pramnos\Llm\Chat\Conversations(new SchedulingAnalyst()));
        $made  = $tasks->create(new Scope(7, 3), ['kind' => 'report', 'title' => 'Week <b>', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $id    = (int) $made['routine_id'];
        $this->recordRun($id, 'ok', 0.25, time() - 3600);
        $this->recordRun($id, 'ok', 0.5, time() - 7200);
        $this->recordRun($id, 'ok', null, time() - 7200);
        $this->recordRun($id, 'failed', null, time() - 40 * 86400);

        // Act
        $ever   = TaskCosts::byTask();
        $recent = TaskCosts::byTask(time() - TaskCosts::DAYS * 86400);

        // Assert
        $this->assertSame(['runs' => 4, 'cost' => 0.75, 'unpriced' => 1], $ever[$id], 'a failed run without a price is not "unpriced"');
        $this->assertSame(3, $recent[$id]['runs'], 'the 40-day-old run is outside the window');
        $this->assertSame([$id => 'Week <b>'], TaskCosts::titles([$id]));
        $this->assertSame([], TaskCosts::titles([]));
    }

    /**
     * The open task's card: its runs and total, the unpriced said, each run listed, its title
     * escaped where it appears.
     */
    #[DataProvider('databases')]
    public function testTheOpenTasksCard(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = new Routines(new \Pramnos\Llm\Chat\Conversations(new SchedulingAnalyst()));
        $made  = $tasks->create(new Scope(7, 3), ['kind' => 'report', 'title' => 'Week', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $id    = (int) $made['routine_id'];
        $this->recordRun($id, 'ok', 1.5, time() - 60);
        $this->recordRun($id, 'failed', null, time() - 30);

        // Act
        $html  = TaskCosts::forTask($made, $tasks->runs($id));
        $empty = TaskCosts::forTask(['routine_id' => 999999], []);

        // Assert
        $this->assertStringContainsString('data-pf-task-cost="' . $id . '">Times run: 2 · Total: $1.50', $html);
        $this->assertSame(2, substr_count($html, 'data-pf-run-cost='));
        $this->assertStringContainsString('Failed', $html);
        $this->assertStringContainsString('—', $html, 'a run without a price shows a dash, not $0');
        $this->assertStringContainsString('Times run: 0', $empty);
        $this->assertStringNotContainsString('<table', $empty, 'no runs, no table');
    }

    /**
     * The admin card: one row per task in the window, a deleted task by its id, the total, and
     * its share of everything the call log priced.
     */
    #[DataProvider('databases')]
    public function testTheAdminCard(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $made = (new Routines(new \Pramnos\Llm\Chat\Conversations(new SchedulingAnalyst())))->create(new Scope(7, 3), ['kind' => 'report', 'title' => 'Week <b>', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $this->recordRun((int) $made['routine_id'], 'ok', 0.002, time() - 60);
        $this->recordRun(424242, 'ok', 2.0, time() - 60);
        $card = new TaskCosts();

        // Act
        $html = $card->render();

        // Assert
        $this->assertSame('pf-task-costs', $card->key());
        $this->assertSame('Scheduled tasks: what they cost', $card->title());
        $this->assertStringContainsString('Week &lt;b&gt;', $html, 'the title is escaped');
        $this->assertStringContainsString('#424242', $html, 'a deleted task is still counted, by its id');
        $this->assertStringContainsString('$0.0020', $html, 'below a dollar, four places');
        $this->assertStringContainsString('<th>$2.00</th>', $html, 'the total');
        $this->assertStringContainsString('Last 30 days: $2.00 of the', $html);
    }

    /** Without the runs table, nothing ran: no costs, and no error. */
    public function testWithoutTheTableNothingRan(): void
    {
        // Arrange — the default connection has no LLM tables
        // Act
        $costs = TaskCosts::byTask();

        // Assert
        $this->assertSame([], $costs);
    }
}
