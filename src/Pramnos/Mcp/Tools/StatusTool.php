<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Application\Application;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;
use Pramnos\Health\HealthRegistry;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: is this installation working, right now, and what is waiting.
 *
 * The first question of every session, and it was four separate ones: is the database up, are
 * there migrations to run, is anything stuck in the queue, and when did something last go
 * wrong. Asked separately they are four round trips; asked not at all — which is what happens
 * — the answer arrives as a confusing failure ten minutes later, from a container that was
 * never running.
 *
 * Everything here is read. Nothing is started, migrated, cleared or retried: a tool called at
 * the beginning of a session, reflexively, must not be able to change anything.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class StatusTool implements McpToolInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'status';
    }

    public function description(): string
    {
        return 'Is this installation working: database, cache, health checks, pending '
            . 'migrations, queue depth and the last error, in one answer. Read-only, and the '
            . 'right first call of a session — the alternative is finding out from a failure.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): mixed
    {
        $status = [
            'database'   => $this->database(),
            'migrations' => $this->migrations(),
            'queue'      => $this->queue(),
            'health'     => $this->health(),
            'push'       => $this->push(),
            'errors'     => $this->errors(),
        ];

        $status['verdict'] = $this->verdict($status);

        return $status;
    }

    /** @return array<string, mixed> */
    protected function database(): array
    {
        $db = $this->app->database ?? null;

        if ($db === null) {
            return ['connected' => false, 'note' => 'The application has no database at all.'];
        }

        try {
            if (!$db->connected) {
                $db->connect();
            }
        } catch (\Throwable $exception) {
            return [
                'connected' => false,
                'error'     => $exception->getMessage(),
                'note'      => 'Everything below is unanswerable until this is fixed — and this '
                    . 'is usually a container that is not running.',
            ];
        }

        return array_filter([
            'connected' => (bool) $db->connected,
            'type'      => (string) ($db->type ?? ''),
            'database'  => (string) ($db->database ?? ''),
            'prefix'    => (string) ($db->prefix ?? ''),
        ], static fn ($value): bool => $value !== '');
    }

    /** @return array<string, mixed> */
    protected function migrations(): array
    {
        $db = $this->app->database ?? null;

        if ($db === null || !$db->connected) {
            return ['unknown' => true];
        }

        try {
            // Feature-gated and cutoff-filtered, the same way the web path and
            // the CLI decide it — an unfiltered count here reported the baseline
            // epoch as pending work on installations that will never run it.
            $scope      = MigrationLoader::scopeFor($this->app, true);
            $runner     = new MigrationRunner($db);
            $migrations = MigrationLoader::loadFromDirectories($scope['dirs'], $this->app);
            if ($scope['cutoff'] !== '') {
                $migrations = $runner->filterCutoff($migrations, $scope['cutoff']);
            }
            $history = $runner->getHistory();
        } catch (\Throwable $exception) {
            return ['unknown' => true, 'error' => $exception->getMessage()];
        }

        $applied = [];

        foreach ($history as $row) {
            $applied[(string) ($row['key'] ?? '')] = true;
        }

        $pending = [];

        foreach ($migrations as $migration) {
            $slug = $migration->getSlug();

            if (!isset($applied[$slug])) {
                $pending[] = $slug;
            }
        }

        return array_filter([
            'applied' => count($applied),
            'pending' => count($pending),
            // Named rather than counted: "3 pending" is a number, and the names say whether
            // they are this afternoon's work or somebody else's from a branch.
            'names'   => $pending === [] ? null : array_slice($pending, 0, 20),
        ], static fn ($value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    protected function queue(): array
    {
        $db = $this->app->database ?? null;

        if ($db === null || !$db->connected) {
            return ['unknown' => true];
        }

        try {
            $result = $db->query(
                'SELECT status, COUNT(*) AS total FROM queueitems GROUP BY status'
            );
        } catch (\Throwable) {
            // No queue feature, or no table. Not a problem to report.
            return ['enabled' => false];
        }

        $counts = [];

        foreach (($result ? $result->fetchAll() : []) as $row) {
            $counts[(string) ($row['status'] ?? 'unknown')] = (int) ($row['total'] ?? 0);
        }

        return ['enabled' => true, 'by_status' => $counts];
    }

    /** @return array<string, mixed> */
    protected function health(): array
    {
        try {
            $results = HealthRegistry::runAll();
        } catch (\Throwable $exception) {
            return ['unknown' => true, 'error' => $exception->getMessage()];
        }

        $checks = [];

        foreach ((array) ($results['checks'] ?? []) as $name => $row) {
            $checks[(string) $name] = [
                'status'  => (string) ($row['status'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
            ];
        }

        return [
            'status' => (string) ($results['status'] ?? ''),
            'checks' => $checks,
        ];
    }

    /**
     * Whether this installation could deliver a push notification if it tried.
     *
     * Three things have to be true and only one of them is visible from the application: a key
     * pair, the encryption library, and a service worker that is listening. The third fails
     * silently — the send succeeds, the subscription stays healthy, and nobody ever mentions
     * receiving anything.
     *
     * @return array<string, mixed>
     */
    /**
     * The three facts the push section is built from, as one seam.
     *
     * Statics over a key file, a class map and a service worker on disk — none of which a test
     * can arrange, and all three of which decide what this section says.
     *
     * @return array{0: bool, 1: bool, 2: array<string, string>}
     */
    protected function pushParts(): array
    {
        return [
            \Pramnos\Push\Vapid::configured(),
            class_exists(ltrim(\Pramnos\Notification\Channels\PushChannel::LIBRARY, '\\')),
            \Pramnos\Push\ServiceWorker::missing(),
        ];
    }

    protected function push(): array
    {
        [$keys, $library, $missing] = $this->pushParts();

        if (!$keys && !$library && $missing === \Pramnos\Push\ServiceWorker::HANDLERS) {
            // Nothing set up at all: an installation that does not send push, which is most of
            // them. Not a finding.
            return ['configured' => false];
        }

        return array_filter([
            'configured'      => $keys && $library && $missing === [],
            'vapid_keys'      => $keys,
            'library'         => $library,
            'service_worker'  => \Pramnos\Push\ServiceWorker::path(),
            'missing_handlers' => $missing === [] ? null : array_keys($missing),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * When something last went wrong, and what it said.
     *
     * The single most useful line at the start of a session: "nothing since Tuesday" and "four
     * minutes ago" lead to completely different afternoons.
     *
     * @return array<string, mixed>
     */
    protected function errors(): array
    {
        $directory = \Pramnos\Logs\Logger::logDirectory();

        if (!is_dir($directory)) {
            return ['note' => 'No log directory yet — nothing has been logged.'];
        }

        $latest = null;

        foreach ((array) glob($directory . DIRECTORY_SEPARATOR . '*.log') as $path) {
            if (!is_file((string) $path)) {
                continue;
            }

            foreach ($this->tail((string) $path) as $line) {
                $entry = json_decode($line, true);

                if (!is_array($entry)) {
                    continue;
                }

                $level = strtolower((string) ($entry['level'] ?? ''));

                if ($level !== 'error' && $level !== 'critical') {
                    continue;
                }

                $timestamp = (string) ($entry['timestamp'] ?? '');
                $when      = \Pramnos\Logs\Logger::timestampOf($timestamp);

                if ($latest === null || $when > $latest['when']) {
                    $latest = [
                        'when'    => $when,
                        'at'      => $timestamp,
                        'level'   => $level,
                        'message' => mb_substr((string) ($entry['message'] ?? ''), 0, 300),
                        'file'    => basename((string) $path),
                        'request' => (string) ($entry['request'] ?? ''),
                    ];
                }
            }
        }

        if ($latest === null) {
            return ['last' => null, 'note' => 'No error in the tail of any log file.'];
        }

        unset($latest['when']);

        return array_filter($latest, static fn ($value): bool => $value !== '');
    }

    /**
     * The last 256KB of a file, as lines.
     *
     * @return list<string>
     */
    protected function tail(string $path, int $bytes = 262144): array
    {
        $size   = (int) @filesize($path);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        if ($size > $bytes) {
            fseek($handle, -$bytes, SEEK_END);
            fgets($handle);   // the first line of the window is almost certainly cut in half
        }

        $lines = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        fclose($handle);

        return $lines;
    }

    /**
     * @param  array<string, mixed> $status
     */
    protected function verdict(array $status): string
    {
        if (empty($status['database']['connected'])) {
            return 'The database is not reachable. Nothing else here is trustworthy, and the '
                . 'usual cause is a container that is not running.';
        }

        $parts = [];

        $pending = (int) ($status['migrations']['pending'] ?? 0);

        if ($pending > 0) {
            $parts[] = $pending . ' pending migration(s) — run `migrate`';
        }

        foreach ((array) ($status['health']['checks'] ?? []) as $name => $check) {
            $state = strtolower((string) ($check['status'] ?? ''));

            if ($state !== '' && $state !== 'ok' && $state !== 'healthy' && $state !== 'pass') {
                $parts[] = $name . ' is ' . $state;
            }
        }

        $handlers = (array) ($status['push']['missing_handlers'] ?? []);

        if ($handlers !== []) {
            $parts[] = 'the service worker has no ' . implode(' or ', $handlers)
                . ' handler, so push is discarded silently';
        }

        $failed = (int) ($status['queue']['by_status']['failed'] ?? 0);

        if ($failed > 0) {
            $parts[] = $failed . ' failed queue job(s)';
        }

        $lastError = $status['errors']['at'] ?? null;

        if ($lastError !== null) {
            $parts[] = 'last error ' . $lastError;
        }

        return $parts === []
            ? 'Everything is up, nothing is pending, and nothing has errored.'
            : implode('; ', $parts) . '.';
    }
}
