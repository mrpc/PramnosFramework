<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\WebhookService;
use Pramnos\Console\Commands\AuthWebhookDeliver;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `auth:webhook-deliver` — the consumer the webhook queue never had.
 *
 * WHAT: that the command drains the queue, and what it does with the outcomes.
 * WHY:  `WebhookService::processQueue()` had no caller anywhere in the framework.
 *       Events were queued by GDPR erasure, device deauthorization and permission
 *       changes, and stayed `pending` forever. The relying parties that had
 *       registered an endpoint were never told anything, and neither end could
 *       see it: the server logged a successful queue write, and the client had
 *       nothing to notice the absence of.
 *
 *       The exit code is the other thing these pin. A scheduler treats a non-zero
 *       exit as a broken command, so an unreachable relying party must not produce
 *       one — the event keeps its attempts and its back-off, and the next run tries
 *       again.
 */
class AuthWebhookDeliverTest extends TestCase
{
    /**
     * Builds the command with a stub service, and a tester for it.
     *
     * @param array{sent?: int, failed?: int} $result What processQueue reports
     * @return array{0: SpyingWebhookDeliver, 1: CommandTester}
     */
    private function make(array $result = [], ?\Throwable $throw = null): array
    {
        $command = new SpyingWebhookDeliver();
        $command->result = $result + ['sent' => 0, 'failed' => 0];
        $command->throw  = $throw;

        $app = new Application();
        $app->add($command);

        return [$command, new CommandTester($command)];
    }

    /**
     * The queue is drained, with the default batch size.
     *
     * The assertion that matters is simply that `processQueue` was called: before
     * this command existed, nothing in the framework ever did.
     */
    public function testTheQueueIsDrained(): void
    {
        // Arrange
        [$command, $tester] = $this->make(['sent' => 3, 'failed' => 1]);

        // Act
        $exit = $tester->execute([], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame([50], $command->batches, 'the queue must actually be processed');
        $this->assertStringContainsString('3 delivered', $tester->getDisplay());
        $this->assertStringContainsString('1 failed', $tester->getDisplay());
    }

    /**
     * `--batch` decides how many events one run attempts.
     */
    public function testTheBatchSizeIsHonoured(): void
    {
        // Arrange
        [$command, $tester] = $this->make(['sent' => 1]);

        // Act
        $tester->execute(['--batch' => '200'], ['interactive' => false]);

        // Assert
        $this->assertSame([200], $command->batches);
    }

    /**
     * A batch size below one is raised to one rather than accepted.
     *
     * `--batch=0` would otherwise query for nothing and report a clean run, which
     * is indistinguishable from an empty queue.
     */
    public function testAnUnusableBatchSizeIsRaised(): void
    {
        // Arrange
        [$command, $tester] = $this->make();

        // Act
        $tester->execute(['--batch' => '0'], ['interactive' => false]);

        // Assert
        $this->assertSame([1], $command->batches);
    }

    /**
     * A failed delivery is not a failed run.
     *
     * The event keeps its attempt count and its back-off; the next run tries
     * again. Exiting non-zero would make a scheduler treat an unreachable relying
     * party as a broken command and start alerting about the wrong thing.
     */
    public function testAFailedDeliveryStillExitsCleanly(): void
    {
        // Arrange — everything failed
        [, $tester] = $this->make(['sent' => 0, 'failed' => 7]);

        // Act
        $exit = $tester->execute([], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('7 failed', $tester->getDisplay());
    }

    /**
     * An empty queue says nothing.
     *
     * This runs every five minutes. A line per run would be nearly three hundred
     * lines a day saying nothing happened, which is how the lines that matter get
     * missed.
     */
    public function testAnEmptyQueueIsSilent(): void
    {
        // Arrange
        [, $tester] = $this->make(['sent' => 0, 'failed' => 0]);

        // Act
        $tester->execute([], ['interactive' => false]);

        // Assert
        $this->assertSame('', trim($tester->getDisplay()));
    }

    /** With `-v`, an empty queue reports that it was empty. */
    public function testVerboseModeSpeaksAnyway(): void
    {
        // Arrange
        [, $tester] = $this->make(['sent' => 0, 'failed' => 0]);

        // Act
        $tester->execute([], ['interactive' => false, 'verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // Assert
        $this->assertStringContainsString('0 delivered', $tester->getDisplay());
    }

    /** `--purge` drops settled events older than the given number of days. */
    public function testPurgeRemovesSettledEvents(): void
    {
        // Arrange
        [$command, $tester] = $this->make(['sent' => 1]);

        // Act
        $tester->execute(['--purge' => '30'], ['interactive' => false]);

        // Assert
        $this->assertSame([30], $command->purges);
        $this->assertStringContainsString('purged', $tester->getDisplay());
    }

    /** Without `--purge`, nothing is deleted. */
    public function testNothingIsPurgedUnlessAsked(): void
    {
        // Arrange
        [$command, $tester] = $this->make(['sent' => 1]);

        // Act
        $tester->execute([], ['interactive' => false]);

        // Assert
        $this->assertSame([], $command->purges);
    }

    /**
     * An installation with no webhook tables succeeds quietly.
     *
     * The schedule runs this on every installation, including those without the
     * authserver feature. A missing table there is the expected state, not a
     * failure worth waking somebody for.
     */
    public function testAMissingTableIsNotAFailure(): void
    {
        // Arrange
        [, $tester] = $this->make([], new \RuntimeException(
            'SQLSTATE[42P01]: Undefined table: relation "oauth2_webhook_events" does not exist'
        ));

        // Act
        $exit = $tester->execute([], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('', trim($tester->getDisplay()));
    }

    /**
     * Any other error is a failure, and says so.
     *
     * The missing-table tolerance is narrow on purpose: a connection that is
     * refused, or a permission that is denied, must not be swallowed by the same
     * branch.
     */
    public function testAnyOtherErrorFails(): void
    {
        // Arrange
        [, $tester] = $this->make([], new \RuntimeException('Connection refused'));

        // Act
        $exit = $tester->execute([], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }
}

/** The command with its service replaced by a recorder. */
class SpyingWebhookDeliver extends AuthWebhookDeliver
{
    /** @var array{sent: int, failed: int} What the stub service reports */
    public array $result = ['sent' => 0, 'failed' => 0];

    /** Thrown by the stub service instead of returning, when set. */
    public ?\Throwable $throw = null;

    /** @var list<int> Batch sizes processQueue was called with */
    public array $batches = [];

    /** @var list<int> Day counts purgeOldEvents was called with */
    public array $purges = [];

    protected function service(): WebhookService
    {
        $command = $this;

        return new class ($command) extends WebhookService {
            public function __construct(private SpyingWebhookDeliver $command)
            {
                // Deliberately no parent::__construct(): the double never touches
                // a database, and requiring one here would defeat the point.
            }

            public function processQueue(int $batchSize = 50): array
            {
                if ($this->command->throw !== null) {
                    throw $this->command->throw;
                }
                $this->command->batches[] = $batchSize;

                return $this->command->result;
            }

            public function purgeOldEvents(int $daysOld = 30): int
            {
                $this->command->purges[] = $daysOld;

                return 4;
            }
        };
    }
}
