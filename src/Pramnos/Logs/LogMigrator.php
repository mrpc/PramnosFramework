<?php

namespace Pramnos\Logs;

/**
 * Log Migration Tool for converting old log formats to structured format
 */
class LogMigrator
{
    /**
     * Size of chunks to read at a time (8MB)
     */
    private const CHUNK_SIZE = 8 * 1024 * 1024;

    /**
     * Maximum line length to process (1MB)
     * Prevents memory issues with malformed files
     */
    private const MAX_LINE_LENGTH = 1024 * 1024;

    /**
     * Backup suffix for original files
     */
    private const BACKUP_SUFFIX = '.bak';

    /**
     * Migration progress callback
     * @var callable|null
     */
    private $progressCallback;

    /**
     * @var string Last timestamp processed
     */
    private $lastTimestamp;

    /**
     * Constructor
     * @param callable|null $progressCallback Optional callback for progress updates
     */
    public function __construct(?callable $progressCallback = null)
    {
        $this->progressCallback = $progressCallback;
    }

    /**
     * Migrates a log file to the new format
     * @param string $filepath Path to the log file
     * @param bool $createBackup Whether to create a backup of the original file
     * @return array Statistics about the migration
     * @throws \RuntimeException If file operations fail
     */
    public function migrateFile(string $filepath, bool $createBackup = true): array
    {
        if (!file_exists($filepath)) {
            throw new \RuntimeException("File not found: $filepath");
        }

        $stats = [
            'total_lines' => 0,
            'processed_lines' => 0,
            'converted_lines' => 0,
            'errors' => 0,
            'start_time' => microtime(true),
            'end_time' => 0,
            'file_size' => filesize($filepath)
        ];

        // Create a temporary file
        $tempFile = $filepath . '.tmp';
        $handle = @fopen($filepath, 'r');
        $tempHandle = @fopen($tempFile, 'w');

        if (!$handle || !$tempHandle) {
            throw new \RuntimeException("Failed to open files for migration");
        }

        try {
            $this->processFileContents($handle, $tempHandle, $stats);

            // Close file handles
            fclose($handle);
            fclose($tempHandle);

            // Create backup if requested
            if ($createBackup) {
                rename($filepath, $filepath . self::BACKUP_SUFFIX);
            } else {
                unlink($filepath);
            }

            // Move temporary file to original location
            rename($tempFile, $filepath);

            $stats['end_time'] = microtime(true);
            $stats['duration'] = $stats['end_time'] - $stats['start_time'];

            return $stats;
        } catch (\Exception $e) {
            // Clean up on error
            @fclose($handle);
            @fclose($tempHandle);
            @unlink($tempFile);
            throw $e;
        }
    }

    /**
     * Process file contents in chunks
     * @param resource $handle Input file handle
     * @param resource $tempHandle Output file handle
     * @param array &$stats Statistics array
     */
    private function processFileContents($handle, $tempHandle, array &$stats): void
    {
        $buffer = '';
        $bytesProcessed = 0;

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);
            $buffer .= $chunk;
            $bytesProcessed += strlen($chunk);

            // Process complete lines in buffer
            $lastNewline = strrpos($buffer, "\n");

            if ($lastNewline !== false) {
                $processBuffer = substr($buffer, 0, $lastNewline + 1);
                $buffer = substr($buffer, $lastNewline + 1);

                $this->processLines($processBuffer, $tempHandle, $stats);
            }

            // Report progress
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $bytesProcessed, $stats['file_size']);
            }
        }

        // Process any remaining content
        if ($buffer !== '') {
            $this->processLines($buffer, $tempHandle, $stats);
        }
    }
    

    /**
 * Process individual lines from the buffer
 * @param string $buffer Text buffer to process
 * @param resource $tempHandle Output file handle
 * @param array &$stats Statistics array
 */
private function processLines(string $buffer, $tempHandle, array &$stats): void 
{
    // Split buffer into lines
    $lines = explode("\n", $buffer);
    
    $multilineBuffer = '';
    $currentTimestamp = null;
    $inStackTrace = false;

    foreach ($lines as $line) {
        // Skip completely empty lines
        if ($line === '') {
            continue;
        }

        $stats['total_lines']++;

        try {
            // Check for different timestamp formats
            if (preg_match('/^\[([\d\/\s\:]+)\]\s*(.*)$/s', $line, $matches) || 
                preg_match('/^\[([\d\-\w\s\:\/]+)\]\s*(.*)$/s', $line, $matches)) {
                
                // If we have buffered content, write it with its timestamp
                if (!empty($multilineBuffer)) {
                    $convertedLine = json_encode([
                        'timestamp' => $currentTimestamp,
                        'message' => trim($multilineBuffer)
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    fwrite($tempHandle, $convertedLine . "\n");
                    $multilineBuffer = '';
                }

                $currentTimestamp = $matches[1];
                $message = $matches[2];
                $inStackTrace = (strpos($message, 'Stack trace:') !== false);

                // If this is the start of an error message, begin buffering
                if (strpos($message, 'PHP Notice:') !== false || 
                    strpos($message, 'PHP Warning:') !== false || 
                    strpos($message, 'PHP Fatal error:') !== false || 
                    strpos($message, 'PHP Parse error:') !== false) {
                    $multilineBuffer = $message;
                    continue;
                }

                // If the message part is empty, skip
                if (trim($message) === '') {
                    continue;
                }

                // Write the line with its timestamp if not starting a stack trace
                if (!$inStackTrace) {
                    $convertedLine = json_encode([
                        'timestamp' => $currentTimestamp,
                        'message' => trim($message)
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    fwrite($tempHandle, $convertedLine . "\n");
                }

            } else {
                // Line without timestamp
                $trimmedLine = trim($line);
                if (!empty($trimmedLine) && $currentTimestamp !== null) {
                    // If this is a stack trace line or we're in a stack trace
                    if ($inStackTrace || strpos($line, '#') === 0 || strpos($line, 'thrown') === 0) {
                        if (!empty($multilineBuffer)) {
                            $multilineBuffer .= "\n";
                        }
                        $multilineBuffer .= rtrim($line); // preserve indentation
                    } else {
                        if (!empty($multilineBuffer)) {
                            $multilineBuffer .= "\n";
                        }
                        $multilineBuffer .= $trimmedLine;
                    }
                }
            }

            $stats['processed_lines']++;

        } catch (\Exception $e) {
            $stats['errors']++;
        }
    }

    // Handle any remaining buffered content
    if (!empty($multilineBuffer) && $currentTimestamp !== null) {
        $convertedLine = json_encode([
            'timestamp' => $currentTimestamp,
            'message' => trim($multilineBuffer)
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fwrite($tempHandle, $convertedLine . "\n");
    }
}

    /**
     * Convert a single log line to structured format
     * @param string $line Original log line
     * @param string &$multilineBuffer Buffer for multiline logs
     * @param bool &$isMultiline Flag indicating if we're processing a multiline log
     * @return string|null Returns null if line is part of a multiline log
     */
    private function convertLine(string $line, string &$multilineBuffer = '', bool &$isMultiline = false): ?string
    {
        // Skip empty lines
        if (empty(trim($line))) {
            return null;
        }

        // Check if this is a new log entry
        if (preg_match('/^\[([\d\-]+(?:[^\]]+)?)\]\s*(.*)$/s', $line, $matches)) {
            // If we were processing a multiline log, finish it
            if ($isMultiline) {
                $result = json_encode([
                    'timestamp' => $this->lastTimestamp,
                    'message' => trim($multilineBuffer)
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                // Reset multiline state
                $isMultiline = false;
                $multilineBuffer = '';

                // Process the current line
                $this->lastTimestamp = $matches[1];
                $message = trim($matches[2]);

                // Check if this line might start a new multiline log
                if (
                    strpos($message, "\n") !== false ||
                    strpos($message, 'Stack trace:') !== false ||
                    strpos($message, 'PHP Notice:') !== false ||
                    strpos($message, 'PHP Warning:') !== false ||
                    strpos($message, 'PHP Fatal error:') !== false
                ) {
                    $isMultiline = true;
                    $multilineBuffer = $message;
                    return $result;
                }

                return json_encode([
                    'timestamp' => $this->lastTimestamp,
                    'message' => $message
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            // Start of a new log entry
            $this->lastTimestamp = $matches[1];
            $message = trim($matches[2]);

            // Check if this might be the start of a multiline log
            if (
                strpos($message, "\n") !== false ||
                strpos($message, 'Stack trace:') !== false ||
                strpos($message, 'PHP Notice:') !== false ||
                strpos($message, 'PHP Warning:') !== false ||
                strpos($message, 'PHP Fatal error:') !== false
            ) {
                $isMultiline = true;
                $multilineBuffer = $message;
                return null;
            }

            return json_encode([
                'timestamp' => $this->lastTimestamp,
                'message' => $message
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // If this line doesn't start with a timestamp and we're in multiline mode,
        // append it to the buffer
        if ($isMultiline) {
            // Add line to buffer, maintaining formatting but skipping empty lines
            $trimmedLine = rtrim($line);  // Keep left padding for stack traces
            if (!empty($trimmedLine)) {
                $multilineBuffer .= "\n" . $trimmedLine;
            }
            return null;
        }

        // Standalone line without timestamp (if not empty)
        $trimmedLine = trim($line);
        if (!empty($trimmedLine)) {
            return json_encode([
                'timestamp' => date('d/m/Y H:i:s'),
                'message' => $trimmedLine
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }
}
