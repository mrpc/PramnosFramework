<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

/**
 * Log-file driver — writes every broadcast event to a log file.
 *
 * Designed for testing and local development.  Each event is written as a
 * JSON line so the log is easily parseable.
 *
 * The log path defaults to ROOT/logs/broadcasting.log when ROOT is defined;
 * otherwise the system temp directory is used.
 *
 */
class LogDriver implements DriverInterface
{
    private readonly string $logPath;

    /**
     * @param string|null $logPath Absolute path to the log file.
     *                             Defaults to ROOT/logs/broadcasting.log.
     */
    public function __construct(?string $logPath = null)
    {
        if ($logPath !== null) {
            $this->logPath = $logPath;
        } elseif (defined('ROOT')) {
            $this->logPath = rtrim(constant('ROOT'), '/') . '/logs/broadcasting.log';
        } else {
            $this->logPath = sys_get_temp_dir() . '/pramnos_broadcasting.log';
        }
    }

    public function broadcast(string $channel, string $event, array $payload): void
    {
        $line = json_encode([
            'timestamp' => date('Y-m-d\TH:i:s'),
            'channel'   => $channel,
            'event'     => $event,
            'payload'   => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($this->logPath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function name(): string
    {
        return 'log';
    }

    /**
     * Returns the absolute path of the log file used by this driver.
     */
    public function getLogPath(): string
    {
        return $this->logPath;
    }

    /**
     * Reads all log entries written since this driver was created.
     *
     * @return list<array{timestamp:string,channel:string,event:string,payload:array}>
     */
    public function getEntries(): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }

        $entries = [];
        $lines   = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    /**
     * Clears the log file.
     */
    public function clear(): void
    {
        if (is_file($this->logPath)) {
            @file_put_contents($this->logPath, '');
        }
    }
}
