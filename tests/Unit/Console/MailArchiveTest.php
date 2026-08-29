<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\MailArchive;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command that moves mail bodies onto disk.
 *
 * It deletes nothing, so the guards here are not the ones `mail:prune` needs. What it has to get
 * right is the **reporting**: the numbers are what somebody reads before deciding whether the
 * archive is worth having, and one of them was wrong by four times on the first run.
 */
#[CoversClass(MailArchive::class)]
class MailArchiveTest extends TestCase
{
    private mixed $previous = null;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->previous = $app->applicationInfo['mail'] ?? null;
        $app->applicationInfo['mail'] = ['body_store' => ['enabled' => true]];
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        if ($this->previous === null) {
            unset($app->applicationInfo['mail']);
        } else {
            $app->applicationInfo['mail'] = $this->previous;
        }
    }

    /**
     * With the store off it explains how to switch it on, and moves nothing.
     *
     * The state every installation is in until somebody decides otherwise, so it is the message
     * most people will ever see from this command.
     */
    public function testWithTheStoreOffItExplainsHowToTurnItOn(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['mail'] = ['body_store' => ['enabled' => false]];
        $command = $this->command();

        // Act
        $tester = $this->execute($command, []);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('body_store', $tester->getDisplay());
        $this->assertStringContainsString('Nothing is lost', $tester->getDisplay());
        $this->assertSame(0, $command->archiveCalls);
    }

    /**
     * A dry run says what it would move and moves nothing.
     */
    public function testItIsADryRunUnlessTold(): void
    {
        // Arrange
        $command = $this->command();

        // Act
        $display = $this->execute($command, [])->getDisplay();

        // Assert
        $this->assertStringContainsString('Dry run', $display);
        $this->assertStringContainsString('1,360', $display);
        $this->assertSame(0, $command->archiveCalls);
    }

    /**
     * The two sizes are reported apart, because they are two different facts.
     *
     * What the archive occupies, and what it would have occupied per row. The first report
     * printed only the second — a per-row sum, which counts one file once for every row that
     * points at it — and said 3.1 MB for 212 KB of disk: four times over, while hiding the
     * entire reason to store bodies this way.
     */
    public function testTheDedupIsReportedRatherThanHidden(): void
    {
        // Act
        $display = $this->execute($this->command(), [])->getDisplay();

        // Assert
        $this->assertStringContainsString('2,916 bodies in 198 file(s)', $display);
        $this->assertStringContainsString('212 KB on disk', $display);
        $this->assertStringContainsString('if each had been stored separately', $display);
    }

    /**
     * With no dedup to report, the second line is not printed.
     *
     * One body per file is the ordinary case for a transactional installation, and a line
     * saying "3 bodies, and 3 if stored separately" is noise.
     */
    public function testWithNoDedupThereIsNoSecondLine(): void
    {
        // Arrange
        $command = $this->command(['archived' => 3, 'archive_files' => 3]);

        // Act
        $display = $this->execute($command, [])->getDisplay();

        // Assert
        $this->assertStringNotContainsString('if each had been stored separately', $display);
    }

    /**
     * `--apply` moves in batches until nothing is left.
     *
     * A table nobody has archived before is every message ever sent, and reading all of their
     * bodies into one result set is how a maintenance command becomes the incident.
     */
    public function testApplyKeepsGoingUntilNothingIsLeft(): void
    {
        // Arrange — three passes' worth
        $command = $this->command();
        $command->passes = [
            ['moved' => 5000, 'freed' => 5_000_000, 'failed' => 0],
            ['moved' => 5000, 'freed' => 5_000_000, 'failed' => 0],
            ['moved' => 120,  'freed' => 120_000,   'failed' => 0],
            ['moved' => 0,    'freed' => 0,         'failed' => 0],
        ];

        // Act
        $display = $this->execute($command, ['--apply' => true])->getDisplay();

        // Assert
        $this->assertSame(4, $command->archiveCalls);
        $this->assertStringContainsString('moved 10,120 bodies', $display);
    }

    /**
     * A limit is one pass, not a smaller loop.
     *
     * Somebody who asked for a thousand rows wants a thousand rows, not a thousand at a time
     * until the table is done.
     */
    public function testALimitIsOnePass(): void
    {
        // Arrange
        $command = $this->command();

        // Act
        $this->execute($command, ['--apply' => true, '--limit' => '1000']);

        // Assert
        $this->assertSame(1, $command->archiveCalls);
        $this->assertSame(1000, $command->lastLimit);
    }

    /**
     * A body that could not be stored is reported, and its row kept it.
     *
     * A disk problem, and the message is still in the table where it was. Silence here would be
     * the one report this command must never give.
     */
    public function testAFailureIsReportedRatherThanCountedAsMoved(): void
    {
        // Arrange
        $command = $this->command();
        $command->passes = [
            ['moved' => 2, 'freed' => 2000, 'failed' => 3],
            ['moved' => 0, 'freed' => 0,    'failed' => 0],
        ];

        // Act
        $display = $this->execute($command, ['--apply' => true])->getDisplay();

        // Assert
        $this->assertStringContainsString('3 could not be stored', $display);
        $this->assertStringContainsString('left in the row', $display);
    }

    /**
     * `--gc` removes only what the store says is unreferenced.
     */
    public function testGcRemovesOnlyWhatTheStoreOffers(): void
    {
        // Arrange
        $command = $this->command();
        $command->orphanList = ['2026/08/aa/' . str_repeat('a', 64) . '.html.gz'];

        // Act
        $display = $this->execute($command, ['--apply' => true, '--gc' => true])->getDisplay();

        // Assert
        $this->assertStringContainsString('unreferenced file(s)', $display);
        $this->assertSame(1, $command->orphanCalls);
    }

    /**
     * A dry run with `--gc` counts them and removes nothing.
     */
    public function testADryRunWithGcOnlyCounts(): void
    {
        // Arrange
        $command = $this->command();
        $command->orphanList = ['2026/08/aa/' . str_repeat('a', 64) . '.html.gz'];

        // Act
        $display = $this->execute($command, ['--gc' => true])->getDisplay();

        // Assert
        $this->assertStringContainsString('no row names any more', $display);
        $this->assertSame(0, $command->archiveCalls);
    }

    /**
     * A cutoff is passed through, and named in the report.
     */
    public function testACutoffIsPassedThroughAndNamed(): void
    {
        // Arrange
        $command = $this->command();

        // Act
        $display = $this->execute($command, ['--older-than' => '14d'])->getDisplay();

        // Assert
        $this->assertStringContainsString('older than 14 days', $display);
        $this->assertSame(14 * 86400, $command->lastOlderThan);
    }

    /**
     * A duration that does not parse means "everything", which is safe here.
     *
     * The opposite of `mail:prune`, where the same typo would delete an audit trail. This
     * command moves bodies and loses none of them, so the cost of a bad value is a longer run.
     */
    public function testAnUnparseableDurationMeansEverything(): void
    {
        // Arrange
        $command = $this->command();

        // Act
        $this->execute($command, ['--older-than' => 'last tuesday']);

        // Assert
        $this->assertSame(0, $command->lastOlderThan);
    }

    /**
     * A database that will not answer is reported, not thrown.
     */
    public function testADatabaseFailureIsReported(): void
    {
        // Arrange
        $command = $this->command();
        $command->statsOverride = ['error' => 'Database is not connected'];

        // Act
        $tester = $this->execute($command, []);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Database is not connected', $tester->getDisplay());
    }

    /**
     * The seams reach the real layer when nothing overrides them.
     *
     * Every test here substitutes a double, so without this the command could be wired to
     * nothing at all and the suite would stay green.
     */
    public function testTheSeamsReachTheRealLayer(): void
    {
        // Arrange
        $command = new class extends MailArchive {
            /** @return array<string, mixed> */
            public function probeStats(): array { return $this->stats(); }

            public function probeArchivable(): int { return $this->archivable(0); }

            /** @return list<string> */
            public function probeOrphans(): array { return $this->orphans(); }
        };

        // Assert — the real readers, against whatever this checkout has
        $this->assertIsArray($command->probeStats());
        $this->assertIsInt($command->probeArchivable());
        $this->assertIsArray($command->probeOrphans());
    }

    /**
     * Durations and sizes read in the units people think in.
     *
     * The report is what somebody checks before deciding the archive is worth having, and
     * "7776000 seconds" and "3250585" are not numbers anybody verifies.
     */
    public function testDurationsAndSizesAreLegible(): void
    {
        // Arrange
        $command = new class extends MailArchive {
            public function probeSeconds(string $v): int { return $this->seconds($v); }
            public function probeHuman(int $s): string   { return $this->human($s); }
            public function probeSize(int $b): string    { return $this->size($b); }
        };

        // Assert — every unit the option accepts
        $this->assertSame(30, $command->probeSeconds('30s'));
        $this->assertSame(12 * 3600, $command->probeSeconds('12h'));
        $this->assertSame(14 * 86400, $command->probeSeconds('14d'));
        $this->assertSame(3 * 604800, $command->probeSeconds('3w'));
        $this->assertSame(6 * 2592000, $command->probeSeconds('6mo'));
        $this->assertSame(2 * 31536000, $command->probeSeconds('2y'));
        $this->assertSame(90 * 60, $command->probeSeconds('90'), 'a bare number is minutes');

        // …and every shape the report prints
        $this->assertSame('45 seconds', $command->probeHuman(45));
        $this->assertSame('1 day', $command->probeHuman(86400));
        $this->assertSame('14 days', $command->probeHuman(14 * 86400));
        $this->assertSame('1 month', $command->probeHuman(2592000));
        $this->assertSame('2 years', $command->probeHuman(2 * 31536000));

        $this->assertSame('512 B', $command->probeSize(512));
        $this->assertSame('2 KB', $command->probeSize(2048));
        $this->assertSame('1.5 MB', $command->probeSize(1_572_864));
        $this->assertSame('2.5 GB', $command->probeSize(2_684_354_560));
    }

    /** @param array<string, mixed> $input */
    private function execute(object $command, array $input): CommandTester
    {
        $application = new \Symfony\Component\Console\Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /** @param array<string, mixed> $stats */
    private function command(array $stats = []): object
    {
        return new class ($stats) extends MailArchive {
            public int $archiveCalls = 0;
            public int $orphanCalls  = 0;
            public int $lastLimit    = 0;
            public int $lastOlderThan = -1;

            /** @var list<array{moved:int,freed:int,failed:int}> */
            public array $passes = [
                ['moved' => 1360, 'freed' => 3_100_000, 'failed' => 0],
                ['moved' => 0,    'freed' => 0,         'failed' => 0],
            ];

            /** @var list<string> */
            public array $orphanList = [];

            public ?array $statsOverride = null;

            public function __construct(private array $extra)
            {
                parent::__construct();
            }

            protected function stats(): array
            {
                if ($this->statsOverride !== null) {
                    return $this->statsOverride;
                }

                return $this->extra + [
                    'rows'           => 3730,
                    'with_body'      => 814,
                    'body_bytes'     => 91_136,
                    'archived'       => 2916,
                    'archived_bytes' => 3_250_585,
                    'archive_files'  => 198,
                    'archive_bytes'  => 217_088,
                    'oldest'         => 1_756_000_000,
                    'newest'         => 1_756_400_000,
                ];
            }

            protected function archivable(int $olderThan): int
            {
                $this->lastOlderThan = $olderThan;

                return 1360;
            }

            protected function archive(int $olderThan, int $limit): array
            {
                $this->lastOlderThan = $olderThan;
                $this->lastLimit     = $limit;

                return $this->passes[$this->archiveCalls++]
                    ?? ['moved' => 0, 'freed' => 0, 'failed' => 0];
            }

            protected function orphans(): array
            {
                $this->orphanCalls++;

                return $this->orphanList;
            }
        };
    }
}
