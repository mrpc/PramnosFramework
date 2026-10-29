<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\ParallelTools;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\ToolTask;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The tool calls of a round run at once, against the real `pramnos.llm_tool_runs` on MySQL and
 * PostgreSQL: claimed by the answer or by a worker, whichever is first, read back in the order
 * the model asked for them, and gone once read.
 */
#[CoversClass(ParallelTools::class)]
#[CoversClass(ToolTask::class)]
class ParallelToolsTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        ParallelAssistant::$ran = [];
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The descriptor a worker builds the tools again from: person 7's chat. */
    private static function descriptor(): array
    {
        return ['space' => Scope::CHAT, 'userid' => 7, 'organization_id' => null, 'conversation_id' => null, 'scheduled' => false, 'extra' => []];
    }

    /** The calls of one round, as the client hands them over. */
    private static function batch(): array
    {
        return [['name' => 'slow', 'arguments' => ['n' => 1]], ['name' => 'fast', 'arguments' => ['n' => 2]], ['name' => 'slow', 'arguments' => ['n' => 3]]];
    }

    /**
     * With no worker taking anything, the answer runs every call itself — nothing waits for a
     * worker that is not there — and each call was still queued for one, and the round's rows
     * are gone once read.
     */
    #[DataProvider('databases')]
    public function testWithoutWorkersTheAnswerRunsEveryCallItself(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $queue = new QueuedTools();
        $here  = [];

        // Act
        $results = (new ParallelTools(self::descriptor(), 5, $queue))(self::batch(), static function (string $name, array $args) use (&$here): string {
            $here[] = $name . $args['n'];

            return 'here:' . $name . $args['n'];
        });

        // Assert — in the order asked, all run here, one task per call, no rows left
        $this->assertSame(['here:slow1', 'here:fast2', 'here:slow3'], $results);
        $this->assertSame(['slow1', 'fast2', 'slow3'], $here);
        $this->assertSame([ParallelTools::TASK, ParallelTools::TASK, ParallelTools::TASK], array_column($queue->tasks, 0));
        $this->assertSame(self::descriptor(), $queue->tasks[0][1]['descriptor'], 'what a worker needs to build the tools again');
        $this->assertSame([], $this->rows('pramnos.llm_tool_runs'), 'state only while the round is answered');

        // A worker that takes a task afterwards finds its call taken, and does nothing
        $this->assertFalse(ParallelTools::runQueued($queue->tasks[0][1]));
    }

    /**
     * A worker that takes the calls first runs them where it is — building the tools again from
     * the descriptor, as the answer's author — and the answer reads its results back in order,
     * running none of them twice.
     */
    #[DataProvider('databases')]
    public function testAWorkerRunsTheCallsItTakes(string $type, string $host, int $port): void
    {
        // Arrange — workers that take every call the moment it is queued
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['assistant' => ParallelAssistant::class]]);
        $queue = new QueuedTools(work: true);

        // Act
        $results = (new ParallelTools(self::descriptor(), 5, $queue))(self::batch(), static fn (string $name): string => 'here:' . $name);

        // Assert
        $this->assertSame(['worker:slow1', 'worker:fast2', 'worker:slow3'], $results);
        $this->assertSame(['slow1', 'fast2', 'slow3'], ParallelAssistant::$ran, 'each ran once, in the worker');
        $this->assertSame([], $this->rows('pramnos.llm_tool_runs'));
    }

    /**
     * A call a worker took and never finished does not hold the answer for ever: after the
     * timeout the model is told so for that call, and the others' results stand.
     */
    #[DataProvider('databases')]
    public function testACallNobodyFinishesIsSaidAfterTheTimeout(string $type, string $host, int $port): void
    {
        // Arrange — a worker that takes the second call and dies
        $this->connectDatabase($type, $host, $port);
        $queue = new QueuedTools(stall: 1);

        // Act
        $results = (new ParallelTools(self::descriptor(), 1, $queue))(self::batch(), static fn (string $name, array $args): string => 'here:' . $name . $args['n']);

        // Assert
        $this->assertSame(['here:slow1', ParallelTools::TOO_LONG, 'here:slow3'], $results);
        $this->assertSame([], $this->rows('pramnos.llm_tool_runs'));
    }

    /**
     * A worker runs only what the answer would have sent it: a tool not marked parallel, or one
     * the assistant does not have, is said to be missing rather than run. And the queue's handler
     * is the framework's own.
     */
    #[DataProvider('databases')]
    public function testAWorkerRunsOnlyParallelTools(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['assistant' => ParallelAssistant::class]]);
        $queue = new QueuedTools(work: true);

        // Act
        $results = (new ParallelTools(self::descriptor(), 5, $queue))(
            [['name' => 'private', 'arguments' => []], ['name' => 'nowhere', 'arguments' => []], ['name' => 'broken', 'arguments' => []]],
            static fn (string $name): string => 'here:' . $name
        );

        // Assert — what a tool throws in the worker is told to the model, as it is in the answer
        $this->assertSame(['Error: there is no tool called private.', 'Error: there is no tool called nowhere.', 'Error: no service'], $results);
        $this->assertSame(ToolTask::class, \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS[ParallelTools::TASK]);
        $this->assertFalse(ParallelTools::runQueued(['run_id' => 0]), 'nothing to claim');
    }

    /**
     * The queue's `llm_tool` handler runs the worker's half from the task's payload, and is done
     * whether or not the call was still there to take.
     */
    #[DataProvider('databases')]
    public function testTheQueueTaskRunsTheWorkersHalf(string $type, string $host, int $port): void
    {
        // Arrange — one call left pending, as an answer that has not reached it yet leaves it
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['assistant' => ParallelAssistant::class]]);
        $this->db->queryBuilder()->table(ParallelTools::TABLE)->insert([
            'batch' => 'b1', 'position' => 0, 'tool' => 'fast', 'arguments' => '{"n":9}', 'status' => 'pending',
        ]);
        $runId = (int) $this->db->getInsertId();
        $item  = (new \ReflectionClass(\Pramnos\Queue\QueueItem::class))->newInstanceWithoutConstructor();
        $item->payload = (string) json_encode(['run_id' => $runId, 'descriptor' => self::descriptor()]);

        // Act
        $task   = new ToolTask(null);
        $first  = $task->execute($item);
        $again  = $task->execute($item);

        // Assert
        $this->assertTrue($first);
        $this->assertTrue($again, 'a call already taken is nothing to do, not a failure');
        $this->assertSame(['fast9'], ParallelAssistant::$ran, 'run once');
        $this->assertSame('worker:fast9', $this->rows(ParallelTools::TABLE)[0]['result']);
        $this->assertSame('Run a tool call ' . $runId, $task->getDescription($item));
    }

    /**
     * Turned on by the application and only then; and an answer through the conversations hands
     * a round's parallel calls over, getting their results back in the order asked.
     */
    #[DataProvider('databases')]
    public function testAnAnswerRunsItsReadsTogether(string $type, string $host, int $port): void
    {
        // Arrange — the model asks for two reads at once, then answers
        $this->connectDatabase($type, $host, $port);
        $this->answer([
            [200, self::calls([['slow', ['n' => 1]], ['fast', ['n' => 2]]])],
            [200, self::says('Both read')],
        ]);
        $off = ParallelTools::forAnswer(self::descriptor());
        $this->configure(['parallel_tools' => ['timeout' => 5], 'chat' => ['assistant' => ParallelAssistant::class]]);

        // Act
        $on     = ParallelTools::forAnswer(self::descriptor());
        $answer = (new Conversations(new ParallelAssistant()))->as(new Scope(7, null))->ask(null, 'Read both');

        // Assert
        $this->assertNull($off, 'off unless the application turns it on');
        $this->assertInstanceOf(ParallelTools::class, $on);
        $this->assertNull(ParallelTools::forAnswer(['extra' => [NAN]]), 'a descriptor no worker could read');
        $this->assertTrue($answer['ok']);
        $results = array_values(array_filter($this->sent[1]['body']['messages'], static fn (array $m): bool => $m['role'] === 'tool'));
        $this->assertStringContainsString('slow1', $results[0]['content']);
        $this->assertStringContainsString('fast2', $results[1]['content']);
        $this->assertSame([], $this->rows('pramnos.llm_tool_runs'));
    }
}

/** An assistant with two parallel reads and one that is not parallel; it records what ran. */
final class ParallelAssistant extends BaseAssistant
{
    /** @var list<string> */
    public static array $ran = [];

    /** @return array<string, array<string, mixed>> */
    public function tools(Turn $turn): array
    {
        $read = static function (string $name): \Closure {
            return static function (array $args) use ($name): string {
                ParallelAssistant::$ran[] = $name . ($args['n'] ?? '');

                return 'worker:' . $name . ($args['n'] ?? '');
            };
        };

        return [
            'slow'    => ['description' => 'A slow read', 'parameters' => [], 'parallel' => true, 'run' => $read('slow')],
            'fast'    => ['description' => 'A fast read', 'parameters' => [], 'parallel' => true, 'run' => $read('fast')],
            'private' => ['description' => 'Not parallel', 'parameters' => [], 'run' => $read('private')],
            'broken'  => ['description' => 'Fails', 'parameters' => [], 'parallel' => true, 'run' => static fn (): string => throw new \RuntimeException('no service')],
        ];
    }
}

/**
 * A queue that records each task — and, as a worker would, runs it at once (`work`), or claims
 * one call and never finishes it (`stall`, its position).
 */
final class QueuedTools extends \Pramnos\Queue\QueueManager
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $tasks = [];

    public function __construct(private readonly bool $work = false, private readonly ?int $stall = null)
    {
    }

    public function addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
    {
        $this->tasks[] = [$taskType, $data];
        if ($this->work) {
            ParallelTools::runQueued($data);
        }
        if ($this->stall === count($this->tasks) - 1) {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(ParallelTools::TABLE)
                ->where('run_id', (int) $data['run_id'])->update(['status' => 'running', 'owner' => 'worker-dead']);
        }

        return count($this->tasks);
    }
}
