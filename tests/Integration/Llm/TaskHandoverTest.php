<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\TaskPolicy;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * A person leaving: what they are asked about their scheduled tasks, organisation by
 * organisation, and what is handed to whom — on MySQL and PostgreSQL.
 *
 * What holds: their tasks are grouped by organisation, the shared ones apart from their own, each
 * group with who may take it over; handing over moves the shared ones to the successor chosen, and
 * their own only when they say so; a successor the policy does not offer, or none, moves nothing;
 * and the account page asks the same and hands over the same.
 */
#[CoversClass(Routines::class)]
#[CoversClass(TaskPolicy::class)]
#[CoversClass(\Pramnos\Auth\Controllers\Account::class)]
class TaskHandoverTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['task_policy' => HandoverPolicy::class]]);
        HandoverPolicy::$successors = [3 => [8 => 'Maria', 9 => 'Nikos'], 4 => [8 => 'Maria']];
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * Choices keyed by organisation, `none` for outside any.
     *
     * @param list<array<string, mixed>> $choices
     * @return array<int|string, array<string, mixed>>
     */
    private static function byOrganization(array $choices): array
    {
        $keyed = [];
        foreach ($choices as $one) {
            $keyed[$one['organization_id'] ?? 'none'] = $one;
        }

        return $keyed;
    }

    /** The tasks, through tools that record nothing they send. */
    private function tasks(): RecordingTasks
    {
        return new RecordingTasks(new Conversations(new BaseAssistant()));
    }

    /**
     * 7's tasks in organisation 3 — a shared report and one of their own — and one outside any.
     *
     * @return array{report: int, own: int, outside: int}
     */
    private function sevensTasks(RecordingTasks $tasks): array
    {
        $tasks->policy();
        $report  = $tasks->create(new Scope(7, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'Team week', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $own     = $tasks->create(new Scope(7, 3), ['kind' => 'reminder', 'title' => 'Mine', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '09:00']);
        $outside = $tasks->create(new Scope(7, null), ['title' => 'Elsewhere', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '10:00']);

        return ['report' => $report['routine_id'], 'own' => $own['routine_id'], 'outside' => $outside['routine_id']];
    }

    /** By organisation: the shared apart from their own, with who may take them; nobody outside one. */
    #[DataProvider('databases')]
    public function testWhatALeavingPersonIsAsked(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $ids   = $this->sevensTasks($tasks);

        // Act
        $choices = self::byOrganization($tasks->handoverChoices(7));

        // Assert
        $this->assertSame([$ids['report']], array_column($choices[3]['shared'], 'routine_id'));
        $this->assertSame([$ids['own']], array_column($choices[3]['personal'], 'routine_id'));
        $this->assertSame([8 => 'Maria', 9 => 'Nikos'], $choices[3]['successors']);
        $this->assertSame([$ids['outside']], array_column($choices['none']['personal'], 'routine_id'), 'outside any organisation');
        $this->assertSame([], $choices['none']['successors'], 'nobody to take those');
        $this->assertSame([], $tasks->handoverChoices(404));
    }

    /**
     * The shared ones go to the successor chosen, their own only when ticked; a successor not
     * offered moves nothing, and neither does none.
     */
    #[DataProvider('databases')]
    public function testHandingOver(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $ids   = $this->sevensTasks($tasks);

        // Act
        $stranger = $tasks->handover(7, [3 => ['to' => 42], 'none' => ['to' => 8, 'personal' => '1']]);
        $shared   = $tasks->handover(7, [3 => ['to' => 8]]);
        $own      = $tasks->handover(7, [3 => ['to' => 9, 'personal' => '1']]);
        $nothing  = $tasks->handover(7, [3 => ['to' => '']]);
        $solo     = $tasks->create(new Scope(7, 4), ['title' => 'Solo', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '11:00']);
        $notOwn   = $tasks->handover(7, [4 => ['to' => 8]]);

        // Assert
        $this->assertSame([], $stranger, 'a successor not offered, and nobody outside an organisation');
        $this->assertSame([$ids['report']], $shared);
        $this->assertSame(8, $tasks->byId($ids['report'])['userid']);
        $this->assertSame([$ids['own']], $own, 'their own, when ticked');
        $this->assertSame(9, $tasks->byId($ids['own'])['userid']);
        $this->assertSame(7, $tasks->byId($ids['outside'])['userid'], 'left for the erasure');
        $this->assertSame([], $nothing);
        $this->assertSame([], $notOwn, 'only their own there, and not ticked: nothing to hand over');
        $this->assertSame(7, $tasks->byId($solo['routine_id'])['userid']);
    }

    /**
     * Tasks that cannot be read give a page with nothing to ask; a hand-over that fails is
     * logged, not a refusal to delete the account; and with no database to ask there are no tasks.
     */
    #[DataProvider('databases')]
    public function testWhatFailsDoesNotStopTheDeletion(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $account = (new \ReflectionClass(FailingAccount::class))->newInstanceWithoutConstructor();
        $plain   = (new \ReflectionClass(\Pramnos\Auth\Controllers\Account::class))->newInstanceWithoutConstructor();
        $broken  = $this->createMock(\Pramnos\Database\Database::class);
        $broken->method('schema')->willThrowException(new \RuntimeException('gone'));

        // Act
        $asked = (new \ReflectionMethod($account, 'taskHandover'))->invoke($account, 7);
        (new \ReflectionMethod($account, 'handOverTasks'))->invoke($account, 7, [3 => ['to' => 8]]);
        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $previous  = $singleton;
        $singleton = $broken;
        try {
            $none     = (new \ReflectionMethod($plain, 'taskRoutines'))->invoke($plain);
            $nothing  = (new \ReflectionMethod($plain, 'taskHandover'))->invoke($plain, 7);
            (new \ReflectionMethod($plain, 'handOverTasks'))->invoke($plain, 7, [3 => ['to' => 8]]);
        } finally {
            $singleton = $previous;
        }

        // Assert
        $this->assertSame([], $asked);
        $this->assertNull($none);
        $this->assertSame([], $nothing, 'no tasks to ask about');
    }

    /** The default policy offers nobody outside an organisation, and leaves the leaver out. */
    #[DataProvider('databases')]
    public function testTheDefaultSuccessors(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $policy = new TaskPolicy(new BaseAssistant());

        // Act
        $outside = $policy->successors(null, 7);
        $inside  = $policy->successors(3, 7);

        // Assert
        $this->assertSame([], $outside);
        $this->assertArrayNotHasKey(7, $inside, 'never the leaver');
    }

    /**
     * The account page asks the same, with the organisation's name, and hands over what the
     * form chose; with nothing chosen it moves nothing.
     */
    #[DataProvider('databases')]
    public function testTheAccountPage(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $ids     = $this->sevensTasks($this->tasks());
        $account = (new \ReflectionClass(\Pramnos\Auth\Controllers\Account::class))->newInstanceWithoutConstructor();
        $ask     = new \ReflectionMethod($account, 'taskHandover');
        $hand    = new \ReflectionMethod($account, 'handOverTasks');

        // Act
        $choices = self::byOrganization($ask->invoke($account, 7));
        $hand->invoke($account, 7, []);
        $before = $this->tasks()->byId($ids['report'])['userid'];
        $hand->invoke($account, 7, [3 => ['to' => '8'], 'junk' => 'not a choice']);

        // Assert
        $this->assertSame([8 => 'Maria', 9 => 'Nikos'], $choices[3]['successors']);
        $this->assertSame('', $choices['none']['name'], 'outside any organisation: no name');
        $this->assertSame('#3', $choices[3]['name'], 'no organisations kept here: its number');
        $this->assertSame(7, $before, 'nothing chosen, nothing moved');
        $this->assertSame(8, $this->tasks()->byId($ids['report'])['userid']);
    }
}

/** An account page whose tasks cannot be read or handed over. */
final class FailingAccount extends \Pramnos\Auth\Controllers\Account
{
    /** Tasks that throw. */
    protected function taskRoutines(): ?\Pramnos\Llm\Chat\Routines
    {
        return new class (new Conversations(new BaseAssistant())) extends Routines {
            /** It fails. */
            public function handoverChoices(int $userId): array
            {
                throw new \RuntimeException('no tasks table');
            }

            /** It fails. */
            public function handover(int $userId, array $choices): array
            {
                throw new \RuntimeException('no tasks table');
            }
        };
    }
}

/** Successors as the test names them, by organisation. */
final class HandoverPolicy extends TaskPolicy
{
    /** @var array<int, array<int, string>> */
    public static array $successors = [];

    /** Anybody may share, here. */
    public function canManage(Scope $scope, string $owner): bool
    {
        return true;
    }

    /** Who the test says. */
    public function successors(?int $organizationId, int $leavingUserId): array
    {
        return self::$successors[(int) $organizationId] ?? [];
    }
}
