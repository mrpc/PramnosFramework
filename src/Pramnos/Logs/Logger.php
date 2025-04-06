<?php

namespace Pramnos\Logs;

/**
 * Enhanced Logger class with structured logging support
 * @package     PramnosFramework
 * @subpackage  Logs
 */
class Logger
{
    /**
     * Default log directory paths
     */
    private const DEFAULT_LOG_PATH = LOG_PATH . DS . 'logs';

    /**
     * Log levels based on PSR-3 standards
     */
    public const LEVEL_EMERGENCY = 'emergency';
    public const LEVEL_ALERT     = 'alert';
    public const LEVEL_CRITICAL  = 'critical';
    public const LEVEL_ERROR     = 'error';
    public const LEVEL_WARNING   = 'warning';
    public const LEVEL_NOTICE    = 'notice';
    public const LEVEL_INFO      = 'info';
    public const LEVEL_DEBUG     = 'debug';

    /**
     * Ensures log directories exist
     */
    private static function ensureLogDirectories(): void
    {
        if (!file_exists(LOG_PATH)) {
            @mkdir(LOG_PATH, 0777, true);
        }
        if (!file_exists(self::DEFAULT_LOG_PATH)) {
            @mkdir(self::DEFAULT_LOG_PATH, 0777, true);
        }
    }

    /**
     * Formats the log entry into a structured single line
     * @param string $message The log message
     * @param array $context Additional context data
     * @return string
     */
    private static function formatLogEntry(string $message, array $context = []): string
    {
        $entry = [
            'timestamp' => date('d/m/Y H:i:s', time()),
            'message' => $message,
        ];
        
        // Add level if specified
        if (!empty($context['level'])) {
            $entry['level'] = $context['level'];
            // Remove level from context to avoid duplication
            unset($context['level']);
        }

        // Handle multiline content
        if (strpos($message, "\n") !== false || !empty($context)) {
            // If message contains newlines or JSON, encode it
            $entry['message'] = str_replace("\n", "\\n", $message);
            
            // Add any additional context
            if (!empty($context)) {
                $entry['context'] = $context;
            }
            
            // Convert to JSON, ensuring single line
            return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // For simple messages without context, maintain original format
        return "[" . $entry['timestamp'] . "] " . $entry['message'];
    }

    /**
     * Enhanced log method with structured logging support
     * @param string $message The log message
     * @param string $file The file to write the log
     * @param string $ext The extension of the log file
     * @param bool $startoffile If true, the log will be written at the start of the file
     * @param array $context Additional context data for structured logging
     * @return void
     */
    public static function log(
        string $message,
        string $file = 'pramnosframework',
        string $ext = "log",
        bool $startoffile = false,
        array $context = []
    ): void {
        self::ensureLogDirectories();
        
        // Check if the message is a valid JSON string
        if (!isset($content['type'])) {
            @json_decode($message);
            if (json_last_error() === JSON_ERROR_NONE) {
                $context['type'] = 'json';
            }
        }

        $filepath = self::DEFAULT_LOG_PATH . DS . $file . '.' . $ext;
        $formattedEntry = self::formatLogEntry($message, $context) . "\n";

        if ($startoffile && file_exists($filepath)) {
            $content = @file_get_contents($filepath);
            @file_put_contents($filepath, $formattedEntry . $content);
        } else {
            @file_put_contents($filepath, $formattedEntry, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Log with specific level
     * @param string $level The log level
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    private static function logWithLevel(
        string $level,
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        // Add level to context
        $context['level'] = $level;
        self::log($message, $file, 'log', false, $context);
    }

    /**
     * Log an emergency message
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function emergency(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_EMERGENCY, $message, $context, $file);
    }

    /**
     * Log an alert message
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function alert(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_ALERT, $message, $context, $file);
    }

    /**
     * Log a critical message
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function critical(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_CRITICAL, $message, $context, $file);
    }

    /**
     * Log an error message (shorthand for logError)
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function error(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_ERROR, $message, $context, $file);
    }

    /**
     * Log a warning message
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function warning(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_WARNING, $message, $context, $file);
    }

    /**
     * Log a notice message
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function notice(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_NOTICE, $message, $context, $file);
    }

    /**
     * Log an info message
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function info(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_INFO, $message, $context, $file);
    }

    /**
     * Log a debug message
     * @param string $message The log message
     * @param array $context Additional context
     * @param string $file The log file name
     * @return void
     */
    public static function debug(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        self::logWithLevel(self::LEVEL_DEBUG, $message, $context, $file);
    }

    /**
     * Log something at the start of the file
     * @param string $message The log message
     * @param string $file The file to write the log
     * @param string $ext The extension of the log file
     * @param array $context Additional context data
     * @return void
     */
    public static function logPrepend(
        string $message,
        string $file = 'pramnosframework',
        string $ext = "log",
        array $context = []
    ): void {
        self::log($message, $file, $ext, true, $context);
    }

    /**
     * Specialized method for logging JSON data
     * @param mixed $data The data to log
     * @param string $file The file to write the log
     * @param string $ext The extension of the log file
     * @param string|null $level Log level (optional)
     * @return void
     */
    public static function logJson(
        $data, 
        string $file = 'pramnosframework', 
        string $ext = "log",
        ?string $level = null
    ): void {
        $jsonString = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES);
        $context = ['type' => 'json'];
        
        if ($level) {
            $context['level'] = $level;
        }
        
        self::log($jsonString, $file, $ext, false, $context);
    }

    /**
     * Specialized method for logging errors with stack traces
     * @param string $message Error message
     * @param \Throwable|null $exception Optional exception object
     * @param string $file The file to write the log
     * @param string $ext The extension of the log file
     * @return void
     */
    public static function logError(
        string $message,
        ?\Throwable $exception = null,
        string $file = 'pramnosframework',
        string $ext = "log"
    ): void {
        $context = [
            'type' => 'error',
            'level' => self::LEVEL_ERROR
        ];
        
        if ($exception) {
            $context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        self::log($message, $file, $ext, false, $context);
    }

    /**
     * Truncate or rotate a log file when it exceeds a specified size
     * @param string $file The log file name
     * @param string $ext The log file extension
     * @param int $maxSize Maximum file size in bytes before truncation (default: 10MB)
     * @param bool $rotate Whether to rotate logs instead of truncating
     * @param int $maxBackups Maximum number of backups to keep when rotating
     * @return bool True if truncated/rotated, false otherwise
     */
    public static function truncateLogFile(
        string $file = 'pramnosframework',
        string $ext = 'log',
        int $maxSize = 10485760,
        bool $rotate = true,
        int $maxBackups = 5
    ): bool {
        $filepath = self::DEFAULT_LOG_PATH . DS . $file . '.' . $ext;
        
        if (!file_exists($filepath)) {
            return false;
        }
        
        $filesize = filesize($filepath);
        
        if ($filesize <= $maxSize) {
            return false;
        }
        
        if ($rotate) {
            // Rotate log files
            for ($i = $maxBackups; $i > 0; $i--) {
                $oldFile = $filepath . '.' . $i;
                $newFile = ($i == $maxBackups) ? '' : $filepath . '.' . ($i + 1);
                
                if (file_exists($oldFile) && $i == $maxBackups) {
                    @unlink($oldFile);
                } elseif (file_exists($oldFile)) {
                    @rename($oldFile, $newFile);
                }
            }
            
            @rename($filepath, $filepath . '.1');
            
            // Add a notice about rotation in the new log file
            self::notice(
                "Log file rotated due to size exceeding " . self::formatBytes($maxSize),
                ['previous_file' => $file . '.' . $ext . '.1']
            );
            
            return true;
        } else {
            // Simply truncate the file
            $fp = fopen($filepath, 'w');
            if ($fp) {
                fwrite($fp, self::formatLogEntry(
                    "Log file truncated due to size exceeding " . self::formatBytes($maxSize),
                    ['level' => 'notice', 'previous_size' => $filesize]
                ) . "\n");
                fclose($fp);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Format bytes to human-readable format
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted string
     */
    private static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Clear a log file
     * @param string $file The log file name
     * @param string $ext The log file extension
     * @return bool True if cleared, false otherwise
     */
    public static function clearLog(string $file, string $ext = 'log'): bool
    {
        $filepath = self::DEFAULT_LOG_PATH . DS . $file . '.' . $ext;
        
        if (!file_exists($filepath)) {
            return false;
        }
        
        return (file_put_contents($filepath, '') !== false);
    }
    
    /**
     * Get the path to a log file
     * @param string $file The log file name
     * @param string $ext The log file extension
     * @return string Full path to the log file
     */
    public static function getLogPath(string $file, string $ext = 'log'): string
    {
        return self::DEFAULT_LOG_PATH . DS . $file . '.' . $ext;
    }
}