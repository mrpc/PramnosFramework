<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Console\CommandBase;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\Worker;

/**
 * Daemon command that continuously processes background tasks from the queue.
 *
 * In one-shot mode (no --daemon flag) it drains the current batch and exits.
 * In daemon mode it loops indefinitely, sleeping between batches, and writes
 * a heartbeat to the lock file so the DaemonOrchestrator can detect stalls.
 *
 * Override the hook methods to integrate with an application-specific setup:
 *
 *   class MyProcessQueue extends ProcessQueue
 *   {
 *       protected function getDashboardTitle(): string  { return ' MY APP QUEUE '; }
 *       protected function getControllerName(): string  { return 'Queueitems'; }
 *       protected function createWorker($controller, ?string $workerId): Worker
 *       {
 *           $w = parent::createWorker($controller, $workerId);
 *           $w->registerTaskHandler('send_email', SendEmailTask::class);
 *           return $w;
 *       }
 *   }
 *
 * @package     PramnosFramework
 * @subpackage  Console\Commands
 */
class ProcessQueue extends CommandBase
{
    /** @var string  Job/lock file base name (prefixed with QUEUE_PROCESSOR_<workerId> when --worker-id is set) */
    private string $jobname = 'QUEUE_PROCESSOR';

    /** @var OutputInterface|null  Captured for signal handler access */
    protected ?OutputInterface $signalOutput = null;

    /** @var bool  Main loop sentinel — set to false by signal handler */
    private bool $shouldContinue = true;

    // ── Dashboard state ───────────────────────────────────────────────────────

    private int   $refreshInterval          = 1;
    private int   $databaseRetryDelay       = 5;
    private int   $lastRefresh              = 0;
    private int   $startTime               = 0;
    private int   $processedTotal          = 0;
    private int   $processedSinceLastRefresh = 0;
    private float $taskPerSecond           = 0.0;
    private float $cpuUsage               = 0.0;
    private int   $memoryUsage            = 0;
    private int   $terminalWidth          = 80;
    private int   $terminalHeight         = 24;

    /** @var array<int, array<string,mixed>> */
    private array $statusMessages = [];

    /** @var array<int, array<string,mixed>> */
    private array $recentTasks    = [];

    private int $maxRecentTasks = 5;

    // ── CommandBase contract ──────────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    protected function getJobName(): string
    {
        return $this->jobname;
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    /**
     * Title shown in the interactive dashboard header.
     *
     * @return string
     */
    protected function getDashboardTitle(): string
    {
        return ' QUEUE PROCESSOR ';
    }

    /**
     * Controller name passed to getApplication()->internalApplication->getController().
     *
     * Override when the application uses a different controller name for its
     * queue item management UI.
     *
     * @return string
     */
    protected function getControllerName(): string
    {
        return 'Queueitems';
    }

    /**
     * Create the Worker instance used for processing.
     *
     * Override to use an application-specific Worker subclass or to pre-register
     * task handlers:
     *
     *   protected function createWorker($controller, ?string $workerId): Worker
     *   {
     *       $w = new MyWorker($controller, $workerId);
     *       $w->registerTaskHandler('send_email', SendEmailTask::class);
     *       return $w;
     *   }
     *
     * @param  \Pramnos\Application\Controller $controller
     * @param  string|null                     $workerId
     * @return Worker
     */
    protected function createWorker($controller, ?string $workerId): Worker
    {
        return new Worker($controller, $workerId);
    }

    /**
     * Create the QueueManager instance used for statistics.
     *
     * Override to use an application-specific QueueManager subclass.
     *
     * @param  \Pramnos\Application\Controller $controller
     * @param  string|null                     $workerId
     * @return QueueManager
     */
    protected function createQueueManager($controller, ?string $workerId): QueueManager
    {
        return new QueueManager($controller, $workerId);
    }

    // ── Symfony Command ───────────────────────────────────────────────────────

    protected function configure(): void
    {
        $this->setName('queue:process')
            ->setDescription('Process tasks from the background queue')
            ->setHelp('Processes pending tasks from the queue system')
            ->addOption('daemon',       'd', InputOption::VALUE_NONE,     'Run continuously as a daemon')
            ->addOption('runtime',      'r', InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds (daemon mode only)', 0)
            ->addOption('sleep',        's', InputOption::VALUE_REQUIRED, 'Seconds to sleep when the queue is empty', 5)
            ->addOption('limit',        'l', InputOption::VALUE_REQUIRED, 'Maximum tasks to process per run (0 = unlimited)', 0)
            ->addOption('batch',        'b', InputOption::VALUE_REQUIRED, 'Tasks to process per batch', 20)
            ->addOption('type',         't', InputOption::VALUE_REQUIRED, 'Process only this task type (comma-separated list)')
            ->addOption('force',        'f', InputOption::VALUE_NONE,     'Start even if another instance appears to be running')
            ->addOption('worker-id',    'w', InputOption::VALUE_REQUIRED, 'Unique identifier for this worker (used in lock file and lockedby column)')
            ->addOption('start-from',   null, InputOption::VALUE_REQUIRED, 'Only process tasks created at or after this datetime (YYYY-MM-DD HH:MM:SS)')
            ->addOption('reverse-order', null, InputOption::VALUE_NONE,   'Process newest tasks first instead of oldest');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = $input->getOption('worker-id') ? (string)$input->getOption('worker-id') : null;

        if ($workerId !== null) {
            $this->jobname = 'QUEUE_PROCESSOR_' . $workerId;
        }

        if ($input->getOption('force')) {
            $this->endJob();
        }

        if ($this->checkIfRunning()) {
            $file = (defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var/' . $this->jobname;
            $output->writeln('<error>Command is already running. Started at: ' . date('d/m/Y H:i:s', filemtime($file)) . '</error>');
            return 1;
        }

        register_shutdown_function([$this, 'endJob']);

        /** @var \Pramnos\Console\Application $consoleApp */
        $consoleApp  = $this->getApplication();
        $application = $consoleApp->internalApplication;
        $application->init();

        $appLabel = 'QueueManager';
        if ($input->getOption('type')) {
            $appLabel .= '-' . $input->getOption('type');
        }
        $application->database->setTrackingInfo(null, $appLabel, []);

        $output->writeln('<info>Starting queue processor</info>');
        $this->startJob();

        $controller   = $application->getController($this->getControllerName());
        $worker       = $this->createWorker($controller, $workerId);
        $queueManager = $this->createQueueManager($controller, $workerId);

        $daemonMode   = (bool)$input->getOption('daemon');
        $maxRuntime   = (int)$input->getOption('runtime');
        $sleepTime    = (int)$input->getOption('sleep');
        $taskLimit    = (int)$input->getOption('limit');
        $batchSize    = (int)$input->getOption('batch');
        $taskTypes    = $input->getOption('type')
            ? array_map('trim', explode(',', (string)$input->getOption('type')))
            : null;
        $startFrom    = $input->getOption('start-from') ? (string)$input->getOption('start-from') : null;
        $reverseOrder = (bool)$input->getOption('reverse-order');

        $startFromTimestamp = null;
        if ($startFrom !== null) {
            $startFromTimestamp = strtotime($startFrom);
            if ($startFromTimestamp === false) {
                $output->writeln('<error>Invalid date format for --start-from. Use YYYY-MM-DD HH:MM:SS</error>');
                return 1;
            }
        }

        $output->writeln([
            'Configuration:',
            '- Mode: '             . ($daemonMode ? 'Daemon' : 'One-time run'),
            '- Task limit: '       . ($taskLimit   ? (string)$taskLimit : 'None'),
            '- Batch size: '       . $batchSize,
            '- Task types: '       . ($taskTypes   ? implode(', ', $taskTypes) : 'All'),
            '- Start from: '       . ($startFrom   ? $startFrom : 'Beginning'),
            '- Processing order: ' . ($reverseOrder ? 'Reverse (newest first)' : 'Normal (oldest first)'),
        ]);

        if ($daemonMode) {
            $output->writeln([
                '- Max runtime: ' . $maxRuntime . ' seconds',
                '- Sleep time: '  . $sleepTime  . ' seconds',
            ]);
        }

        $output->writeln('');
        $this->configureInterruptHandling($output, 'handleSignal');

        $taskCount = 0;
        $startTime = $this->now();

        $this->initializeInteractiveTerminal($output);

        $this->startTime  = $this->now();
        $this->lastRefresh = $this->now();
        $statsUpdateTime  = $this->now();
        $stats            = null;

        try {
            if ($daemonMode) {
                $lastBatchTime  = $this->now();
                $lastHeartbeat  = $this->now();
                $lastReconnect  = $this->now();
                $hasTasks       = true;

                while ($this->shouldContinue) {
                    if (!file_exists((defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var/' . $this->jobname)
                        || file_exists((defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var/' . $this->jobname . '.stop')) {
                        $output->writeln('<comment>Stop signal detected, exiting.</comment>');
                        break;
                    }

                    if ($maxRuntime > 0 && ($this->now() - $startTime) >= $maxRuntime) {
                        $output->writeln('<comment>Maximum runtime reached, exiting.</comment>');
                        break;
                    }

                    if ($this->now() - $lastHeartbeat >= 30) {
                        $this->heartbeat();
                        $lastHeartbeat = $this->now();
                    }

                    try {
                        if ($this->now() - $lastReconnect > 300) {
                            $this->addStatusMessage('info', 'Refreshing database connection');
                            if (!$this->attemptDatabaseReconnect($application->database)) {
                                throw new \RuntimeException('Database connection unavailable');
                            }
                            $this->applyDatabaseTrackingInfo($application, $appLabel);
                            $lastReconnect = $this->now();
                            $worker        = $this->createWorker($controller, $workerId);
                            $queueManager  = $this->createQueueManager($controller, $workerId);
                        }

                        $this->updateSystemMetrics();

                        if ($this->now() - $statsUpdateTime >= 10) {
                            $stats           = $queueManager->getStats();
                            $statsUpdateTime = $this->now();
                        }

                        if (!$hasTasks) {
                            if ($this->now() - $lastBatchTime < $sleepTime) {
                                $this->renderDashboard($output, [
                                    'mode'          => 'daemon',
                                    'state'         => 'sleeping',
                                    'sleepRemaining' => max(0, $sleepTime - ($this->now() - $lastBatchTime)),
                                    'batchCount'    => 0,
                                    'stats'         => $stats,
                                    'taskTypes'     => $taskTypes,
                                    'maxRuntime'    => $maxRuntime,
                                    'taskLimit'     => $taskLimit,
                                    'batchLimit'    => $batchSize,
                                    'startFrom'     => $startFrom,
                                    'reverseOrder'  => $reverseOrder,
                                ]);
                                usleep(200000);
                                continue;
                            }
                        }

                        $batchMax    = $taskLimit > 0 ? min($batchSize, $taskLimit - $taskCount) : $batchSize;
                        $batchCount  = $this->processBatch($worker, $output, $batchMax, $taskTypes, $startFromTimestamp, $reverseOrder);
                        $hasTasks    = ($batchCount > 0);
                        $taskCount  += $batchCount;
                        $this->processedTotal              += $batchCount;
                        $this->processedSinceLastRefresh   += $batchCount;
                        $lastBatchTime = $this->now();

                        if ($taskLimit > 0 && $taskCount >= $taskLimit) {
                            $this->addStatusMessage('info', 'Task limit reached');
                            break;
                        }

                        if ($this->now() - $this->lastRefresh >= $this->refreshInterval) {
                            $elapsed                           = $this->now() - $this->lastRefresh;
                            $this->taskPerSecond               = $elapsed > 0
                                ? $this->processedSinceLastRefresh / $elapsed
                                : 0.0;
                            $this->processedSinceLastRefresh   = 0;
                            $this->lastRefresh                 = $this->now();
                        }

                        $this->renderDashboard($output, [
                            'mode'          => 'daemon',
                            'state'         => $hasTasks ? 'processing' : 'sleeping',
                            'sleepRemaining' => $hasTasks ? 0 : max(0, $sleepTime - ($this->now() - $lastBatchTime)),
                            'batchCount'    => $batchCount,
                            'stats'         => $stats,
                            'taskTypes'     => $taskTypes,
                            'maxRuntime'    => $maxRuntime,
                            'taskLimit'     => $taskLimit,
                            'batchLimit'    => $batchSize,
                            'startFrom'     => $startFrom,
                            'reverseOrder'  => $reverseOrder,
                        ]);

                        usleep(100000);
                    } catch (\Throwable $e) {
                        if (!$this->isDatabaseFailure($e)) {
                            throw $e;
                        }
                        if (!$this->recoverDatabaseConnection($application, $output, $appLabel, [
                            'stats'        => $stats,
                            'taskTypes'    => $taskTypes,
                            'maxRuntime'   => $maxRuntime,
                            'taskLimit'    => $taskLimit,
                            'batchLimit'   => $batchSize,
                            'startFrom'    => $startFrom,
                            'reverseOrder' => $reverseOrder,
                            'startedAt'    => $startTime,
                        ])) {
                            break;
                        }
                        $worker        = $this->createWorker($controller, $workerId);
                        $queueManager  = $this->createQueueManager($controller, $workerId);
                        $lastReconnect = $this->now();
                        $statsUpdateTime = $this->now();
                        $lastBatchTime = $this->now();
                        $stats         = null;
                        $hasTasks      = false;
                    }
                }
            } else {
                $taskCount = $this->processBatch($worker, $output, $taskLimit, $taskTypes, $startFromTimestamp, $reverseOrder);
                $this->processedTotal = $taskCount;
                $this->renderDashboard($output, [
                    'mode'         => 'oneshot',
                    'state'        => 'completed',
                    'taskCount'    => $taskCount,
                    'startFrom'    => $startFrom,
                    'reverseOrder' => $reverseOrder,
                ]);
                sleep(2);
            }

            $output->writeln("<info>Queue processing completed. Processed {$taskCount} tasks.</info>");
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->showCursor($output);
            $this->endJob();
            return 1;
        }

        $this->showCursor($output);
        $this->endJob();
        return 0;
    }

    // ── Batch processing ──────────────────────────────────────────────────────

    /**
     * Process up to $limit tasks in a tight loop.
     *
     * @param  Worker              $worker
     * @param  OutputInterface     $output
     * @param  int                 $limit
     * @param  string[]|null       $taskTypes
     * @param  int|null            $startFromTimestamp
     * @param  bool                $reverseOrder
     * @return int  Number of tasks processed
     */
    protected function processBatch(
        Worker $worker,
        OutputInterface $output,
        int $limit,
        ?array $taskTypes,
        ?int $startFromTimestamp,
        bool $reverseOrder
    ): int {
        $processed = 0;
        $max       = $limit > 0 ? $limit : 1;

        for ($i = 0; $i < $max; $i++) {
            $output->write("Processing task... \r");
            $taskInfo = $worker->processNextTask($taskTypes, $startFromTimestamp, $reverseOrder);
            if (!$taskInfo) {
                break;
            }
            $processed++;
            $output->write("Processed {$processed} tasks\r");
            $this->addRecentTask($taskInfo);
        }

        if ($processed > 0) {
            $output->writeln("Processed {$processed} tasks");
        }

        return $processed;
    }

    // ── Database resilience ───────────────────────────────────────────────────

    /**
     * Re-apply query tracking metadata after a reconnect.
     *
     * @param  object $application
     * @param  string $appName
     * @return void
     */
    protected function applyDatabaseTrackingInfo(object $application, string $appName): void
    {
        $application->database->setTrackingInfo(null, $appName, []);
    }

    /**
     * Attempt a non-fatal database reconnect.
     *
     * Supports both tryReconnect() and refresh() database methods for
     * backwards compatibility with older driver implementations.
     *
     * @param  object $database
     * @return bool
     */
    protected function attemptDatabaseReconnect(object $database): bool
    {
        try {
            if (method_exists($database, 'tryReconnect')) {
                return (bool)$database->tryReconnect();
            }
            if (method_exists($database, 'refresh')) {
                $result = $database->refresh();
                return $result === null ? true : (bool)$result;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    /**
     * Classify an exception as a database connectivity failure.
     *
     * Used to decide whether to enter the reconnection loop or re-throw.
     *
     * @param  \Throwable $exception
     * @return bool
     */
    protected function isDatabaseFailure(\Throwable $exception): bool
    {
        static $needles = [
            'database connection unavailable',
            'database is not connected',
            'could not connect',
            'connection refused',
            'connection timed out',
            'server closed the connection',
            'server has gone away',
            'lost connection',
            'broken pipe',
            'pg_query',
            'pg_connect',
        ];

        $message = strtolower($exception->getMessage());
        foreach ($needles as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        if ($exception->getPrevious() instanceof \Throwable) {
            return $this->isDatabaseFailure($exception->getPrevious());
        }

        return false;
    }

    /**
     * Keep attempting to reconnect until the link is restored or the daemon
     * receives a stop signal.
     *
     * @param  object          $application
     * @param  OutputInterface $output
     * @param  string          $appName
     * @param  array<string,mixed> $dashboardData
     * @return bool  True when connection is restored; false when we should exit
     */
    protected function recoverDatabaseConnection(
        object $application,
        OutputInterface $output,
        string $appName,
        array $dashboardData = []
    ): bool {
        $this->addStatusMessage('warning', 'Database unavailable. Waiting for reconnect');

        while ($this->shouldContinue) {
            $varBase = defined('ROOT') ? ROOT : sys_get_temp_dir();
            if (!file_exists($varBase . '/var/' . $this->jobname)
                || file_exists($varBase . '/var/' . $this->jobname . '.stop')) {
                return false;
            }

            if (($dashboardData['maxRuntime'] ?? 0) > 0
                && ($this->now() - ($dashboardData['startedAt'] ?? $this->now())) >= $dashboardData['maxRuntime']) {
                return false;
            }

            $this->updateSystemMetrics();
            $this->renderDashboard($output, [
                'mode'          => 'daemon',
                'state'         => 'reconnecting',
                'sleepRemaining' => $this->databaseRetryDelay,
                'batchCount'    => 0,
                'stats'         => $dashboardData['stats'] ?? null,
                'taskTypes'     => $dashboardData['taskTypes'] ?? null,
                'maxRuntime'    => $dashboardData['maxRuntime'] ?? 0,
                'taskLimit'     => $dashboardData['taskLimit'] ?? 0,
                'batchLimit'    => $dashboardData['batchLimit'] ?? 0,
                'startFrom'     => $dashboardData['startFrom'] ?? null,
                'reverseOrder'  => $dashboardData['reverseOrder'] ?? false,
            ]);

            if ($this->attemptDatabaseReconnect($application->database)) {
                $this->applyDatabaseTrackingInfo($application, $appName);
                $this->addStatusMessage('info', 'Database reconnected');
                return true;
            }

            $this->addStatusMessage('warning', 'Reconnect failed. Retrying in ' . $this->databaseRetryDelay . 's');
            $this->sleepSeconds($this->databaseRetryDelay);
        }

        return false;
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    /**
     * Render the queue processor dashboard frame.
     *
     * @param  OutputInterface     $output
     * @param  array<string,mixed> $data
     * @return void
     */
    protected function renderDashboard(OutputInterface $output, array $data): void
    {
        $state = (string)($data['state'] ?? 'unknown');

        if ($state === 'reconnecting') {
            $this->renderDashboardGameMode(
                $output,
                $this->getDashboardTitle(),
                'Database Reconnection Intermission',
                'Database connection lost. Retrying until the link is restored.',
                (int)($data['sleepRemaining'] ?? 0),
                $this->terminalWidth
            );
            return;
        }

        $borderLen = $this->terminalWidth - 2;

        // Queue stats section
        $stats    = $data['stats'] ?? null;
        $statsSeg = $stats ? [
            'Queue Status: Pending: '    . ($stats['pending']    ?? 0),
            'Processing: '               . ($stats['processing'] ?? 0),
            'Completed: '               . ($stats['completed']  ?? 0),
            'Warning: '                 . ($stats['warning']    ?? 0),
            'Failed: '                  . ($stats['failed']     ?? 0),
        ] : ['Queue Status: Unknown (stats not yet loaded)'];

        $statsSection = $this->buildDashboardRows($statsSeg, $borderLen);

        // Processing info section
        $taskTypesLabel = (isset($data['taskTypes']) && $data['taskTypes'])
            ? implode(', ', (array)$data['taskTypes'])
            : 'All';
        $maxRuntimeLabel = (isset($data['maxRuntime']) && $data['maxRuntime'] > 0)
            ? $this->formatTime((int)$data['maxRuntime'])
            : 'Unlimited';
        $taskLimitLabel = (isset($data['taskLimit']) && $data['taskLimit'] > 0)
            ? (string)(int)$data['taskLimit']
            : 'Unlimited';

        $processInfo = $this->buildCommandStateSection($borderLen, (string)($data['mode'] ?? 'unknown'), $state, [
            'Task Types: '  . $taskTypesLabel,
            'Max Runtime: ' . $maxRuntimeLabel,
            'Task Limit: '  . $taskLimitLabel,
        ]);

        // Progress section
        $progressSeg = [
            'Processed: ' . $this->processedTotal . ' tasks',
            'Rate: '      . sprintf('%.2f', $this->taskPerSecond) . ' tasks/sec',
        ];
        if ($state === 'sleeping') {
            $progressSeg[] = 'Next batch in: ' . ($data['sleepRemaining'] ?? 0) . 's';
        }
        $progressSection = $this->buildDashboardRows($progressSeg, $borderLen);

        // Recent tasks section
        $tasksSection  = $this->padDashboardRow('│ Recent Tasks:', $borderLen);
        if (empty($this->recentTasks)) {
            $tasksSection .= $this->padDashboardRow('│ No tasks processed yet', $borderLen);
        } else {
            foreach ($this->recentTasks as $task) {
                $status = (string)($task['status'] ?? '');
                $colored = match ($status) {
                    'completed'  => "\033[32m{$status}\033[0m",
                    'warning'    => "\033[33m{$status}\033[0m",
                    'failed'     => "\033[31m{$status}\033[0m",
                    'processing' => "\033[36m{$status}\033[0m",
                    default      => $status,
                };
                $line = "│ [{$task['time']}] ID: {$task['id']} │ Type: {$task['type']} │ Status: {$colored}";
                if (!empty($task['execution_time'])) {
                    $line .= " │ Time: {$task['execution_time']}";
                }
                $tasksSection .= $this->padDashboardRow($line, $borderLen);
                if (!empty($task['message'])) {
                    $maxMsg = $borderLen - 16;
                    $msg    = $this->truncateText((string)$task['message'], max(10, $maxMsg));
                    $tasksSection .= $this->padDashboardRow("│   Message: {$msg}", $borderLen);
                }
            }
        }

        // Status messages section
        $msgSection = $this->padDashboardRow('│ Status Messages:', $borderLen);
        if (empty($this->statusMessages)) {
            $msgSection .= $this->padDashboardRow('│ No messages', $borderLen);
        } else {
            foreach ($this->statusMessages as $msg) {
                $type    = strtoupper((string)($msg['type'] ?? ''));
                $colored = match (strtolower((string)($msg['type'] ?? ''))) {
                    'error'   => "\033[31m{$type}\033[0m",
                    'warning' => "\033[33m{$type}\033[0m",
                    'info'    => "\033[32m{$type}\033[0m",
                    default   => $type,
                };
                $msgSection .= $this->padDashboardRow(
                    "│ [{$msg['time']}] {$colored}: {$msg['message']}",
                    $borderLen
                );
            }
        }

        $helpSection = $this->buildDashboardHelpSection($borderLen);

        $this->renderDashboardFrameAutoSystem(
            $output,
            $this->getDashboardTitle(),
            [$statsSection, $processInfo, $progressSection, $tasksSection, $msgSection, $helpSection],
            $this->terminalWidth
        );
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $taskInfo
     * @return void
     */
    protected function addRecentTask(array $taskInfo): void
    {
        if (count($this->recentTasks) >= $this->maxRecentTasks) {
            array_shift($this->recentTasks);
        }
        $this->recentTasks[] = [
            'id'             => $taskInfo['id']             ?? 'unknown',
            'type'           => $taskInfo['type']           ?? 'unknown',
            'status'         => $taskInfo['status']         ?? 'processed',
            'time'           => date('H:i:s'),
            'message'        => $taskInfo['message']        ?? null,
            'execution_time' => isset($taskInfo['execution_time'])
                ? sprintf('%.3fs', $taskInfo['execution_time'])
                : null,
        ];
    }

    /**
     * @param  string $type     info|warning|error
     * @param  string $message
     * @return void
     */
    protected function addStatusMessage(string $type, string $message): void
    {
        if (count($this->statusMessages) >= 5) {
            array_shift($this->statusMessages);
        }
        $this->statusMessages[] = ['type' => $type, 'message' => $message, 'time' => date('H:i:s')];
    }

    /**
     * @return void
     */
    protected function updateSystemMetrics(): void
    {
        $this->memoryUsage = memory_get_usage(true);
        if ($this->supportsSysGetLoadAvg()) {
            $load            = $this->getLoadAvg();
            $this->cpuUsage  = isset($load[0]) ? (float)$load[0] : 0.0;
        }
        $this->updateTerminalSize();
    }

    /**
     * @return void
     */
    protected function updateTerminalSize(): void
    {
        [$height, $width]      = $this->detectTerminalSize();
        $this->terminalHeight  = $height;
        $this->terminalWidth   = $width;
    }

    /**
     * Signal handler — called on Ctrl+C or SIGTERM.
     *
     * @param  int $signal
     * @return void
     */
    public function handleSignal(int $signal = 0): void
    {
        if ($this->signalOutput) {
            $this->signalOutput->writeln("\n<info>Caught shutdown signal. Cleaning up…</info>");
        }
        $this->endJob();
        $this->shouldContinue = false;
    }
}
