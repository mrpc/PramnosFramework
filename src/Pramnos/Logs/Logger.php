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
     * @return void
     */
    public static function logJson($data, string $file = 'pramnosframework', string $ext = "log"): void
    {
        $jsonString = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES);
        self::log($jsonString, $file, $ext, false, ['type' => 'json']);
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
        $context = ['type' => 'error'];
        
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
}