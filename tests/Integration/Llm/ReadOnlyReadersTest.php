<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Application\Controllers\AiController;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Shares;
use Pramnos\Llm\Chat\TaskPolicy;
use Pramnos\Llm\Chat\Web\ReadOnlyView;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/AnalystTest.php';
require_once __DIR__ . '/ReportsTest.php';
require_once __DIR__ . '/AnalystTasksTest.php';

/**
 * Reports and shares for people below the analyst's floor — on MySQL and PostgreSQL.
 *
 * What holds: by default a report never reaches them; where the application lets reports go lower
 * (`analyst.reports_below_floor`) they are readers too, and opening its link — or a share sent to
 * them — shows it read only, in every theme, without copying it into an Ask they cannot open; what
 * is not theirs to read is not there.
 */
#[CoversClass(AiController::class)]
#[CoversClass(Shares::class)]
#[CoversClass(ReadOnlyView::class)]
#[CoversClass(TaskPolicy::class)]
class ReadOnlyReadersTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        // 7 the administrator who makes it, 9 a member below the floor, 10 another below it
        ReportPolicy::$types = [7 => 99, 9 => 10, 10 => 10];
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** An analyst report open to everybody from usertype 1, with one run. Answers [task, run id]. */
    private function report(): array
    {
        $tasks = new Routines(new \Pramnos\Llm\Chat\Conversations(new SchedulingAnalyst()));
        $made  = $tasks->create(new Scope(7, null, Scope::ADMIN), ['kind' => 'report', 'owner' => 'global', 'title' => 'Leaks', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $this->db->queryBuilder()->table('ai_routines')->where('routine_id', $made['routine_id'])->update(['audience' => json_encode(['min_usertype' => 1, 'users' => []])]);
        $this->db->queryBuilder()->table('ai_task_runs')->insert(['routine_id' => $made['routine_id'], 'kind' => 'report', 'status' => 'ok', 'content' => '**3** leaks today',
            'started_at' => \Pramnos\Llm\CallLog::instant(time()), 'finished_at' => \Pramnos\Llm\CallLog::instant(time())]);

        // The run's id from the row itself: an insert id read after the audience update is not always the run's.
        return [$tasks->byId($made['routine_id']), (int) $this->db->queryBuilder()->table('ai_task_runs')->where('routine_id', $made['routine_id'])->max('run_id')];
    }

    /** A screen read by somebody below the floor. */
    private function screenFor(int $userId, string $theme = 'plain-css'): AnalystScreen
    {
        $screen = new AnalystScreen($theme);
        $screen->reading = (object) ['userid' => $userId, 'usertype' => ReportPolicy::$types[$userId] ?? 0, 'active' => 1];

        return $screen;
    }

    /**
     * Off by default: a reader below the floor is refused. On: they read it, read only, in every
     * theme; somebody it was not for gets the 404.
     */
    #[DataProvider('databases')]
    public function testAReportBelowTheFloor(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['task_policy' => ReportPolicy::class]]);
        [$task, $runId] = $this->report();
        $policy = new ReportPolicy(new SchedulingAnalyst());

        // Act / Assert — off by default
        $this->assertFalse($policy->canSee(9, $task), 'below the floor, by default');

        // Arrange — the application lets reports go lower
        $this->configure(['chat' => ['task_policy' => ReportPolicy::class], 'analyst' => ['reports_below_floor' => true]]);

        // Act
        $pages = [];
        foreach (['plain-css', 'tailwind', 'bootstrap'] as $theme) {
            $pages[$theme] = (string) $this->screenFor(9, $theme)->report($runId);
        }
        ReportPolicy::$types[10] = 0;
        $closed = 0;
        try {
            $this->screenFor(10)->report($runId);
        } catch (\Pramnos\Application\ApplicationClosedException) {
            $closed++;
        }

        // Assert
        $this->assertTrue((new ReportPolicy(new SchedulingAnalyst()))->canSee(9, $task));
        $this->assertSame(0, (new ReportPolicy(new SchedulingAnalyst()))->readerFloor($task));
        foreach ($pages as $theme => $html) {
            $this->assertStringContainsString('data-pf-read-only', $html, $theme);
            $this->assertStringContainsString('<strong>3</strong> leaks today', $html, $theme);
            $this->assertStringContainsString('Read only.', $html, $theme);
            $this->assertStringNotContainsString('<textarea', $html, $theme . ': nothing to ask');
        }
        $this->assertSame(1, $closed, 'below its audience: not there');
        $this->assertSame([], $this->rows('chat_conversations'), 'nothing copied into an Ask they cannot open');
    }

    /** A share sent to somebody below the floor reads as it was shared, without a copy. */
    #[DataProvider('databases')]
    public function testAShareBelowTheFloor(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $owner = new Scope(7, null, Scope::ADMIN);
        $chat  = \Pramnos\Llm\Analyst\Analyst::conversations(7);
        $id    = (int) $chat->seed('Leaks', [['role' => 'user', 'content' => 'Where?'], ['role' => 'assistant', 'content' => 'In **Patras**.']]);
        $made  = (new Artifacts($owner))->create(['kind' => 'text', 'title' => 'Memo', 'text' => 'Kept']);
        $this->db->queryBuilder()->table('chat_artifacts')->where('artifact_id', $made['artifact_id'])
            ->update(['conversation_id' => $id, 'message_id' => (int) $chat->find($id)['messages'][1]['message_id']]);
        $this->db->queryBuilder()->table('chat_shares')->insert(['kind' => 'conversation', 'target_id' => $id, 'organization_id' => null, 'userid' => 7,
            'space' => Scope::ADMIN, 'recipient' => 9, 'title' => 'Leaks', 'up_to_message_id' => null, 'created_at' => \Pramnos\Llm\CallLog::instant(time()), 'updated_at' => \Pramnos\Llm\CallLog::instant(time())]);
        $shareId = (int) $this->db->getInsertId();
        $this->db->queryBuilder()->table('chat_shares')->insert(['kind' => 'artifact', 'target_id' => $made['artifact_id'], 'organization_id' => null, 'userid' => 7,
            'space' => Scope::ADMIN, 'recipient' => 9, 'title' => 'Memo', 'created_at' => \Pramnos\Llm\CallLog::instant(time()), 'updated_at' => \Pramnos\Llm\CallLog::instant(time())]);
        $artifactShare = (int) $this->db->getInsertId();
        $before = count($this->rows('chat_conversations'));

        // Act
        $html     = (string) $this->screenFor(9)->shared($shareId);
        $artifact = (string) $this->screenFor(9)->shared($artifactShare);
        $closed   = 0;
        try {
            $this->screenFor(10)->shared($shareId);
        } catch (\Pramnos\Application\ApplicationClosedException) {
            $closed++;
        }

        // Assert
        $this->assertStringContainsString('In <strong>Patras</strong>.', $html);
        $this->assertStringContainsString('Where?', $html);
        $this->assertStringContainsString('data-pf-artifact="' . $made['artifact_id'] . '"', $html, 'with its artifacts');
        $this->assertStringContainsString('Shared with you by', $html);
        $this->assertStringContainsString('shared this with you.', $artifact);
        $this->assertSame(1, $closed, 'not theirs: not there');
        $this->assertCount($before, $this->rows('chat_conversations'), 'no copy taken');
        $this->assertNull((new Shares())->snapshot(999999, 9));
    }
}
