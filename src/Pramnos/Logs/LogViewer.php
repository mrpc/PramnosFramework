<?php

namespace Pramnos\Logs;

use Pramnos\Html\Logs\LogViewerView;

/**
 * LogViewer class for handling log file viewing, searching, and pagination
 * @package     PramnosFramework
 * @subpackage  Logs
 */
class LogViewer
{
    /**
     * Default log directory path
     */
    private const DEFAULT_LOG_PATH = LOG_PATH . DS . 'logs';

    /**
     * Path to the log file
     * @var string
     */
    private $filePath;

    /**
     * Name of the log file
     * @var string
     */
    private $filename;

    /**
     * Whether to read in reverse
     * @var bool
     */
    private $reverse;

    /**
     * Current page number
     * @var int
     */
    private $page;

    /**
     * Maximum lines per page
     * @var int
     */
    private $maxLines;

    /**
     * Search term
     * @var string
     */
    private $search;

    /**
     * Whitelist of allowed log files
     * @var array
     */
    private $whitelist;

    /**
     * Log viewer view instance
     * @var LogViewerView
     */
    private $view;

    /**
     * Current log level filter
     * @var string|null
     */
    private $logLevel = null;

    /**
     * Constructor
     * @param array $whitelist Whitelist of allowed log files
     * @param \Pramnos\Application\Controller|null $controller Controller instance for view rendering
     */
    public function __construct(array $whitelist = [], ?\Pramnos\Application\Controller $controller = null)
    {
        $this->whitelist = $whitelist;
        if ($controller) {
            $this->view = new LogViewerView($controller);
        }
    }

    /**
     * Set the log file to view
     * @param string $filename Name of the log file
     * @param bool $checkWhitelist Whether to check the whitelist
     * @return self
     */
    public function setFile(string $filename, bool $checkWhitelist = true): self
    {
        if ($checkWhitelist && !empty($this->whitelist) && !in_array($filename, $this->whitelist)) {
            throw new \InvalidArgumentException('Invalid log file specified');
        }

        $this->filename = $filename;
        $this->filePath = $this->getLogFilePath($filename);
        return $this;
    }

    /**
     * Get the path to the log file
     * @param string $filename Name of the log file
     * @return string Path to the log file
     */
    private function getLogFilePath(string $filename): string
    {
        if ($filename === 'GitDeploy') {
            return ROOT . DS . 'www' . DS . 'api' . DS . 'deploy.log';
        } elseif ($filename === 'GitWebhookDebug') {
            return ROOT . DS . 'www' . DS . 'api' . DS . 'webhook_debug.log';
        } else {
            return self::DEFAULT_LOG_PATH . DS . $filename;
        }
    }

    /**
     * Set pagination and search parameters
     * @param bool $reverse Whether to read in reverse
     * @param int $page Current page number
     * @param int $maxLines Maximum lines per page
     * @param string $search Search term
     * @return self
     */
    public function setParameters(bool $reverse = true, int $page = 1, int $maxLines = 20, string $search = ''): self
    {
        $this->reverse = $reverse;
        $this->page = max(1, $page);
        $this->maxLines = max(1, $maxLines);
        $this->search = str_replace('{space}', ' ', trim(urldecode($search)));
        return $this;
    }

    /**
     * Set log level filter
     * @param string $level The log level to filter by
     * @return self
     */
    public function setLogLevel(string $level): self
    {
        $this->logLevel = strtolower($level);
        return $this;
    }

    /**
     * Process log file and return results
     * @return array Array containing lines, total count, and matched count
     */
    public function processFile(): array
    {
        if (!file_exists($this->filePath)) {
            throw new \RuntimeException("Log file not found: " . $this->filename);
        }

        // Get file size
        $fileSize = filesize($this->filePath);

        // If file is larger than 50MB, use chunked reading approach
        $useChunkedReading = $fileSize > 50 * 1024 * 1024;

        // Process file based on its type
        if ($this->filename === 'php_error.log' || $this->filename === 'php_dev_error.log') {
            if ($useChunkedReading) {
                return $this->readLargePhpErrorFile();
            } else {
                $file = new \SplFileObject($this->filePath, 'r');
                return $this->readSmallPhpErrorFile($file);
            }
        }

        // Use original implementation for other log files
        if ($useChunkedReading) {
            return $this->readLargeFileChunked();
        } else {
            $file = new \SplFileObject($this->filePath, 'r');
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key();

            $matchedLines = [];
            $totalMatchedLines = $this->countMatchedLines($file);
            $matchedLines = $this->getLinesForPage($file, $totalMatchedLines);

            return [
                'lines' => $matchedLines,
                'total' => $totalLines,
                'matched_total' => $totalMatchedLines
            ];
        }
    }

    /**
     * Process lines from file based on log type
     * @return array Array containing lines, total count and matched count information
     */
    public function getLogContent(): array
    {
        return $this->processFile();
    }

    /**
     * Read large file in chunks and search efficiently
     * @return array Array containing lines, total count and matched count
     */
    private function readLargeFileChunked(): array
    {
        $chunkSize = 8 * 1024 * 1024; // 8MB chunks
        $handle = fopen($this->filePath, 'rb');
        $fileSize = filesize($this->filePath);

        $lines = [];
        $totalLines = 0;
        $matchedTotal = 0;
        $startLine = ($this->page - 1) * $this->maxLines;
        $endLine = $startLine + $this->maxLines;

        // Normalize search term by removing extra spaces
        $search = $this->normalizeSearchTerm($this->search);

        if ($this->reverse) {
            // For reverse reading, start from the end
            $pos = $fileSize;
            $pendingLine = '';

            while ($pos > 0 && count($lines) < $this->maxLines) {
                $amt = min($chunkSize, $pos);
                $pos -= $amt;
                fseek($handle, $pos);
                $chunk = fread($handle, $amt);

                // Normalize line endings in chunk
                $chunk = $this->normalizeLineEndings($chunk);

                // Handle split lines
                if ($pendingLine !== '') {
                    $chunk .= $pendingLine;
                    $pendingLine = '';
                }

                // Split chunk into lines
                $chunkLines = array_filter(explode("\n", $chunk));

                // If this isn't the start of the file, save the first line
                if ($pos > 0) {
                    $pendingLine = array_shift($chunkLines);
                }

                // Process lines in reverse
                $chunkLines = array_reverse($chunkLines);

                foreach ($chunkLines as $line) {
                    $line = $this->safeTrim($line);
                    if (empty($line)) continue;
                    if ($this->searchInLine($line, $search) && $this->matchesLogLevel($line)) {
                        $matchedTotal++;

                        // If we're in the target page range
                        if ($matchedTotal > $startLine && $matchedTotal <= $endLine) {
                            $lines[] = $line;
                        }

                        // If we've got enough lines, stop reading
                        if ($matchedTotal > $endLine) {
                            break 2;
                        }
                    }
                    $totalLines++;
                }
            }

            // Reverse the lines to maintain correct order
            $lines = array_reverse($lines);
        } else {
            // For forward reading
            while (!feof($handle) && count($lines) < $this->maxLines) {
                $chunk = fread($handle, $chunkSize);
                $lines_in_chunk = array_filter(explode("\n", $chunk));

                foreach ($lines_in_chunk as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    if ($this->searchInLine($line, $search) && $this->matchesLogLevel($line)) {
                        $matchedTotal++;

                        if ($matchedTotal > $startLine && $matchedTotal <= $endLine) {
                            $lines[] = $line;
                        }
                    }
                    $totalLines++;

                    if ($matchedTotal > $endLine) {
                        break 2;
                    }
                }
            }
        }

        fclose($handle);

        return [
            'lines' => $lines,
            'total' => $totalLines,
            'matched_total' => $matchedTotal
        ];
    }

    /**
     * Read small PHP error log file using SplFileObject
     * @param \SplFileObject $file File object
     * @return array Array containing lines, total count and matched count
     */
    private function readSmallPhpErrorFile(\SplFileObject $file): array
    {
        $entries = [];
        $currentEntry = '';
        $allEntries = [];
        $totalEntries = 0;
        $matchedEntries = 0;

        // First pass: collect all entries
        $file->rewind();
        while (!$file->eof()) {
            $line = $this->normalizeLineEndings($file->current());
            $line = $this->safeTrim($line);

            // Check if this is the start of a new entry
            if (preg_match('/^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4}/', $line)) {
                if (!empty($currentEntry)) {
                    if ($this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                        $allEntries[] = $this->formatPhpErrorEntry($currentEntry);
                        $matchedEntries++;
                    }
                    $totalEntries++;
                }
                $currentEntry = $line;
            } else {
                $currentEntry .= "\n" . $line;
            }
            $file->next();
        }

        // Process the last entry if exists
        if (!empty($currentEntry)) {
            if ($this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                $allEntries[] = $this->formatPhpErrorEntry($currentEntry);
                $matchedEntries++;
            }
            $totalEntries++;
        }

        // Calculate pagination
        $startIndex = ($this->page - 1) * $this->maxLines;

        if ($this->reverse) {
            $allEntries = array_reverse($allEntries);
        }

        $entries = array_slice($allEntries, $startIndex, $this->maxLines);

        return [
            'lines' => $entries,
            'total' => $totalEntries,
            'matched_total' => $matchedEntries
        ];
    }

    /**
     * Read large PHP error log file in chunks
     * @return array Array containing lines, total count and matched count
     */
    private function readLargePhpErrorFile(): array
    {
        $handle = fopen($this->filePath, 'rb');
        $entries = [];
        $currentEntry = '';
        $allEntries = [];
        $totalEntries = 0;
        $matchedEntries = 0;
        $fileSize = filesize($this->filePath);
        $chunkSize = 8 * 1024 * 1024; // 8MB chunks

        if ($this->reverse) {
            // For reverse reading, we need to process the whole file
            // Use temporary storage for large files
            $tmpFile = tmpfile();

            // Process file in chunks from the end
            $pos = $fileSize;
            $pendingLine = '';

            while ($pos > 0) {
                $amt = min($chunkSize, $pos);
                $pos -= $amt;
                fseek($handle, $pos);
                $chunk = fread($handle, $amt);

                // Handle line splitting
                if ($pendingLine !== '') {
                    $chunk .= $pendingLine;
                }

                // Normalize line endings
                $chunk = $this->normalizeLineEndings($chunk);

                // Handle line splitting
                if ($pendingLine !== '') {
                    $chunk .= $pendingLine;
                }

                $lines = explode("\n", $chunk);

                // Save potentially incomplete first line for next iteration
                if ($pos > 0) {
                    $pendingLine = array_shift($lines);
                }

                foreach ($lines as $line) {
                    $line = $this->safeTrim($line);
                    if (empty($line)) continue;

                    if (preg_match('/^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4}/', $line)) {
                        if (!empty($currentEntry)) {
                            fwrite($tmpFile, $currentEntry . "\n");
                            if ($this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                                $matchedEntries++;
                            }
                            $totalEntries++;
                        }
                        $currentEntry = $line;
                    } else {
                        $currentEntry = $line . "\n" . $currentEntry;
                    }
                }
            }

            // Process final entry
            if (!empty($currentEntry)) {
                fwrite($tmpFile, $currentEntry . "\n");
                if ($this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                    $matchedEntries++;
                }
                $totalEntries++;
            }

            // Read the relevant portion for the current page
            $startIndex = ($this->page - 1) * $this->maxLines;
            fseek($tmpFile, 0);
            $matched = 0;
            $currentEntry = '';

            while (!feof($tmpFile)) {
                $line = fgets($tmpFile);
                if ($line === false) break;

                if (preg_match('/^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4}/', $line)) {
                    if (!empty($currentEntry)) {
                        if ($this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                            $matched++;
                            if ($matched > $startIndex && count($entries) < $this->maxLines) {
                                $entries[] = $this->formatPhpErrorEntry($currentEntry);
                            }
                        }
                    }
                    $currentEntry = $line;
                } else {
                    $currentEntry .= $line;
                }
            }

            // Process final entry
            if (!empty($currentEntry) && $this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                $matched++;
                if ($matched > $startIndex && count($entries) < $this->maxLines) {
                    $entries[] = $this->formatPhpErrorEntry($currentEntry);
                }
            }

            fclose($tmpFile);
            $entries = array_reverse($entries);
        } else {
            // Forward reading
            $startIndex = ($this->page - 1) * $this->maxLines;
            $matched = 0;

            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false) break;

                $line = rtrim($line);
                if (empty($line)) continue;

                if (preg_match('/^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4}/', $line)) {
                    if (!empty($currentEntry)) {
                        if ($this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                            $matched++;
                            if ($matched > $startIndex && count($entries) < $this->maxLines) {
                                $entries[] = $this->formatPhpErrorEntry($currentEntry);
                            }
                        }
                        $totalEntries++;
                    }
                    $currentEntry = $line;
                } else {
                    $currentEntry .= "\n" . $line;
                }
            }

            // Process final entry
            if (!empty($currentEntry)) {
                if ($this->searchInPhpErrorEntry($currentEntry, $this->search) && $this->matchesLogLevel($currentEntry)) {
                    $matched++;
                    if ($matched > $startIndex && count($entries) < $this->maxLines) {
                        $entries[] = $this->formatPhpErrorEntry($currentEntry);
                    }
                }
                $totalEntries++;
            }
        }

        fclose($handle);

        return [
            'lines' => $entries,
            'total' => $totalEntries,
            'matched_total' => $matchedEntries
        ];
    }

    /**
     * Format a PHP error entry into JSON format
     * @param string $entry Raw PHP error entry
     * @return string JSON formatted entry
     */
    private function formatPhpErrorEntry($entry)
    {
        // Extract timestamp and message
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $this->normalizeLineEndings($entry), $matches)) {
            $timestamp = $matches[1];
            $fullMessage = $matches[2];

            // Extract error type
            $errorType = '';
            if (preg_match('/(Fatal error|Warning|Notice|Deprecated|Parse error|Error):/i', $fullMessage, $typeMatches)) {
                $errorType = strtolower($typeMatches[1]);
            }

            // Create structured log entry
            $logEntry = [
                'timestamp' => $timestamp,
                'message' => $fullMessage,
                'context' => [
                    'type' => $errorType,
                    'raw_entry' => $entry
                ]
            ];

            // Extract stack trace if exists
            if (strpos($fullMessage, 'Stack trace:') !== false) {
                $parts = explode('Stack trace:', $fullMessage, 2);
                $logEntry['message'] = trim($parts[0]);
                $logEntry['context']['stack_trace'] = array_filter(
                    array_map(
                        'trim',
                        explode("\n", trim($parts[1]))
                    )
                );
            }

            return json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Fallback for unrecognized format
        return json_encode([
            'timestamp' => date('d-M-Y H:i:s'),
            'message' => $entry,
            'context' => [
                'type' => 'unknown',
                'raw_entry' => $entry
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Count total matched lines in file with JSON support
     * @param \SplFileObject $file File object
     * @return int Number of matched lines
     */
    private function countMatchedLines(\SplFileObject $file): int
    {
        if (empty($this->search) && $this->logLevel === null) {
            $file->seek(PHP_INT_MAX);
            return $file->key();
        }

        $count = 0;
        $file->rewind();
        while (!$file->eof()) {
            $line = trim($file->current());
            if ($this->searchInLine($line, $this->search) && $this->matchesLogLevel($line)) {
                $count++;
            }
            $file->next();
        }
        return $count;
    }

    /**
     * Get lines for the current page
     * @param \SplFileObject $file File object
     * @param int $totalMatchedLines Total number of matched lines
     * @return array Array of lines
     */
    private function getLinesForPage(\SplFileObject $file, int $totalMatchedLines): array
    {
        $startLine = ($this->page - 1) * $this->maxLines;
        $endLine = $startLine + $this->maxLines;
        $matchedLines = [];
        $lineCount = 0;

        if ($this->reverse) {
            $file->seek($file->key());
            $currentLine = $file->key();

            while ($currentLine > 0 && count($matchedLines) < $this->maxLines) {
                $file->seek($currentLine - 1);
                $line = trim($file->current());

                if ($this->searchInLine($line, $this->search) && $this->matchesLogLevel($line)) {
                    if ($lineCount >= $startLine && $lineCount < $endLine) {
                        $matchedLines[] = $line;
                    }
                    $lineCount++;
                }
                $currentLine--;
            }
        } else {
            $file->rewind();
            while (!$file->eof() && count($matchedLines) < $this->maxLines) {
                $line = trim($file->current());

                if ($this->searchInLine($line, $this->search) && $this->matchesLogLevel($line)) {
                    if ($lineCount >= $startLine && $lineCount < $endLine) {
                        $matchedLines[] = $line;
                    }
                    $lineCount++;
                }
                $file->next();
            }
        }

        return $matchedLines;
    }

    /**
     * Check if a line matches the specified log level
     * @param string $line The log line to check
     * @return bool Whether the line matches the log level
     */
    private function matchesLogLevel($line): bool
    {
        if ($this->logLevel === null) {
            return true;
        }

        // Try to decode JSON first
        try {
            $jsonData = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Check if the log level matches
                if (isset($jsonData['level'])) {
                    return strtolower($jsonData['level']) === $this->logLevel;
                }

                // Check if there's level in context
                if (isset($jsonData['context']['level'])) {
                    return strtolower($jsonData['context']['level']) === $this->logLevel;
                }
            }
        } catch (\Exception $e) {
            // If JSON parsing fails, fall back to text search
        }

        // For non-JSON logs, check if the log level is mentioned in the line
        if (preg_match('/\b' . preg_quote($this->logLevel, '/') . '\b/i', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Process file content to normalize line endings
     * @param string $content The file content
     * @return string Normalized content
     */
    private function normalizeLineEndings($content)
    {
        // Convert all line endings to \n
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        return $content;
    }

    /**
     * Safely trim line while preserving newlines
     * @param string $line The line to trim
     * @return string Trimmed line
     */
    private function safeTrim($line)
    {
        return rtrim($line, " \t");
    }

    /**
     * Normalize search term by handling JSON key-value pairs
     * @param string $search The search term
     * @return string Normalized search term
     */
    private function normalizeSearchTerm($search)
    {
        if (empty($search)) {
            return '';
        }

        // Remove extra spaces around colons and between quotes
        $search = preg_replace('/\s*:\s*/', ':', $search);
        $search = preg_replace('/"\s+/', '"', $search);
        $search = preg_replace('/\s+"/', '"', $search);

        return $search;
    }

    /**
     * Search within a PHP error entry
     * @param string $entry Complete error entry
     * @param string $search Search term
     * @return bool
     */
    private function searchInPhpErrorEntry($entry, $search)
    {
        if (empty($search)) {
            return true;
        }
        return stripos($entry, $search) !== false;
    }

    /**
     * Search within a line, including JSON content
     * @param string $line The line to search in
     * @param string $search The search term
     * @return bool Whether the line matches the search
     */
    private function searchInLine($line, $search)
    {
        if (empty($search)) {
            return true;
        }

        // Normalize search term and remove trailing comma
        $search = rtrim(preg_replace('/\s+/', ' ', trim($search)), ',');

        // Try to decode JSON first
        try {
            $jsonData = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Search in the main JSON structure
                if ($this->searchInJson($jsonData, $search)) {
                    return true;
                }

                // If there's a nested message field that's also JSON, decode and search it
                if (isset($jsonData['message'])) {
                    $messageData = json_decode($jsonData['message'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $this->searchInJson($messageData, $search);
                    }
                }
            }
        } catch (\Exception $e) {
            // If JSON parsing fails, fall back to text search
        }

        // Normalize the line content for searching
        $normalizedLine = preg_replace('/\s+/', ' ', trim($line));

        // Check the normalized line
        return stripos($normalizedLine, $search) !== false;
    }

    /**
     * Recursively search through JSON data with improved matching
     * @param array $data The JSON data as array
     * @param string $search The search term
     * @return bool Whether the search term was found
     */
    private function searchInJson($data, $search)
    {
        // Handle key-value pair search
        if (strpos($search, ':') !== false) {
            list($searchKey, $searchValue) = array_map('trim', explode(':', $search, 2));
            // Remove quotes if present
            $searchKey = trim($searchKey, '"');
            $searchValue = trim($searchValue, '"');

            return $this->searchKeyValuePair($data, $searchKey, $searchValue);
        }

        // Regular search through all values
        foreach ($data as $key => $value) {
            // Check if the key contains the search term
            if (stripos($key, $search) !== false) {
                return true;
            }

            // Check the value based on its type
            if (is_array($value)) {
                if ($this->searchInJson($value, $search)) {
                    return true;
                }
            } elseif (is_string($value)) {
                if (stripos($value, $search) !== false) {
                    return true;
                }
            } elseif (is_numeric($value) && (string)$value === $search) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search for specific key-value pair in JSON data
     * @param array $data The JSON data
     * @param string $searchKey The key to search for
     * @param string $searchValue The value to search for
     * @return bool Whether the key-value pair was found
     */
    private function searchKeyValuePair($data, $searchKey, $searchValue)
    {
        foreach ($data as $key => $value) {
            // Check current level
            if (strcasecmp($key, $searchKey) === 0) {
                if (is_string($value) && stripos($value, $searchValue) !== false) {
                    return true;
                } elseif (is_numeric($value) && (string)$value === $searchValue) {
                    return true;
                }
            }

            // Recurse into nested arrays
            if (is_array($value)) {
                if ($this->searchKeyValuePair($value, $searchKey, $searchValue)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the error level from a message
     * @param string $message The log message
     * @return string|null Error level or null if not found
     */
    public static function getErrorLevel($message): ?string
    {
        $message = strtolower($message);

        if (strpos($message, 'fatal error') !== false) {
            return 'fatal';
        }
        if (strpos($message, 'warning') !== false) {
            return 'warning';
        }
        if (strpos($message, 'notice') !== false) {
            return 'notice';
        }
        if (strpos($message, 'deprecated') !== false) {
            return 'deprecated';
        }
        if (strpos($message, 'strict standards') !== false) {
            return 'strict';
        }

        return null;
    }

    /**
     * Render HTML output for logs with pagination, styling, and search highlighting
     * @param array $result Result from processFile
     * @param string $search Search term
     * @return string HTML content
     */
    public function renderHtml(array $result): string
    {
        $lines = $result['lines'];
        $totalMatchedLines = $result['matched_total'];
        $totalPages = max(1, ceil($totalMatchedLines / $this->maxLines));
        $currentPage = min($this->page, $totalPages);

        $html = '<!DOCTYPE html><html><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
            ' . $this->getStylesAndScripts() . '
            </head><body><ul>';

        // Display the lines
        if (empty($lines)) {
            $html .= $this->renderNoResults($this->search);
        } else {
            $html .= $this->renderLines($lines, $this->filename, $this->search);
        }

        // Add pagination information
        $html .= $this->renderPagination($currentPage, $totalPages, $this->maxLines, $totalMatchedLines);

        // Add script to communicate total pages to parent
        $html .= "<script>
            window.parent.postMessage({totalPages: $totalPages}, '*');
        </script>";

        $html .= '</ul></body></html>';
        return $html;
    }

    /**
     * Render pagination information
     * @param int $currentPage Current page number
     * @param int $totalPages Total number of pages
     * @param int $maxLines Lines per page
     * @param int $totalMatchedLines Total number of matched lines
     * @return string HTML content
     */
    private function renderPagination($currentPage, $totalPages, $maxLines, $totalMatchedLines)
    {
        $html = '<div class="pagination">';
        if ($totalMatchedLines > 0) {
            $startEntry = ($currentPage - 1) * $maxLines + 1;
            $endEntry = min($startEntry + $maxLines - 1, $totalMatchedLines);
            $html .= "<span><i class='fas fa-list'></i> Showing entries $startEntry-$endEntry of $totalMatchedLines</span>";
            $html .= "<span><i class='fas fa-file'></i> Page $currentPage of $totalPages</span>";
        } else {
            $html .= "<span><i class='fas fa-info-circle'></i> No entries found</span>";
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render log lines with highlighting and error level coloring
     * @param array $lines Array of log lines
     * @param string $filename Name of the log file
     * @param string $search Search term
     * @return string HTML content
     */
    private function renderLines($lines, $filename, $search): string
    {
        $html = '';

        foreach ($lines as $string) {
            if (empty(trim($string))) {
                continue;
            }

            // Check for alternative format: [timestamp] json
            if (preg_match('/^\[([\d\/]+ [\d:]+)\]\s*({.+})$/', $string, $matches)) {
                // Convert to standard format
                $standardFormat = json_encode([
                    'timestamp' => $matches[1],
                    'message' => $matches[2]
                ]);
                $string = $standardFormat;
            }

            try {
                // Parse the JSON structure
                $data = json_decode($string, true, 512, JSON_THROW_ON_ERROR);

                // Skip entries with empty messages
                if (empty(trim($data['message'] ?? ''))) {
                    continue;
                }

                // Determine error level from PHP error logs
                if (isset($data['context']['type'])) {
                    $errorLevel = $data['context']['type'];
                } else {
                    $errorLevel = self::getErrorLevel($data['message'] ?? '');
                }
                $errorClass = $errorLevel ? "error-level-{$errorLevel}" : '';

                $html .= "<li class=\"{$errorClass}\">";

                // Add timestamp
                if (isset($data['timestamp'])) {
                    $html .= '<span class="timestamp">' . $this->highlightText($data['timestamp'], $search) . '</span>';
                }

                // Add message content
                $html .= '<span class="log-content">';
                $message = $data['message'] ?? '';

                // Handle context if exists
                if (isset($data['context']) && !empty($data['context'])) {
                    $compactJson =  $this->getContextSummary($data['context']);
                    $contextJson = $data['context'];
                    $expandedJson = json_encode($contextJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $html .= '<div class="json-container context-container">';
                    // Add context indicator button that also acts as toggle
                    $html .= '<div class="context-indicator">';
                    $html .= '<i class="fas fa-info-circle"></i> ';
                    $html .= '<span class="context-summary">' . $this->getContextSummary($data['context']) . '</span>';
                    $html .= '</div>';

                    // Create button container
                    $html .= '<div class="json-container">';
                    $html .= '<div class="json-content context-summary compact active">'
                        . $this->highlightText($compactJson, $search, true) . '</div>';
                    $html .= '<div class="json-content expanded">'
                        . $this->highlightText($expandedJson, $search, true) . '</div>';

                    $html .= '</div>';
                }

                // If message is JSON, handle it specially
                if (!empty($message) && $message[0] === '{' && substr($message, -1) === '}') {
                    try {
                        $messageJson = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

                        // Create both compact and expanded versions
                        $compactJson = json_encode($messageJson);
                        $expandedJson = json_encode($messageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                        // Create button container
                        $html .= '<div class="json-container main-json-container">';
                        $html .= '<div class="json-content compact active">'
                            . $this->highlightText($compactJson, $search, true) . '</div>';
                        $html .= '<div class="json-content expanded">'
                            . $this->highlightText($expandedJson, $search, true) . '</div>';
                        $html .= '<div class="buttons-container">';
                        $html .= '<button class="copy-btn" onclick="copyToClipboard(this)" title="Copy to clipboard">';
                        $html .= '<i class="fas fa-copy"></i></button>';
                        $html .= '<button class="toggle-json" title="Toggle JSON format">';
                        $html .= '<i class="fas fa-expand"></i>';
                        $html .= '</button>';
                        $html .= '</div>';
                        $html .= '</div>';
                    } catch (\JsonException $e) {
                        $message = $this->highlightText(htmlspecialchars($message), $search);
                        $html .= $message;
                        $html .= '<div class="buttons-container">';
                        $html .= '<button class="copy-btn" onclick="copyToClipboard(this)" title="Copy to clipboard">';
                        $html .= '<i class="fas fa-copy"></i></button>';
                        $html .= '</div>';
                    }
                } else {
                    $message = $this->highlightText(htmlspecialchars($message), $search);
                    $html .= str_replace("\\n", "<br>", $message);
                    $html .= '<div class="buttons-container">';
                    $html .= '<button class="copy-btn" onclick="copyToClipboard(this)" title="Copy to clipboard">';
                    $html .= '<i class="fas fa-copy"></i></button>';
                    if (isset($data['context']) && !empty($data['context'])) {
                        $html .= '<button class="toggle-json" title="Toggle Context">';
                        $html .= '<i class="fas fa-' . (empty($search) ? 'expand' : 'compress') . '"></i>';
                        $html .= '</button>';
                    }
                    $html .= '</div>';
                }

                $html .= '</span>';
            } catch (\JsonException $e) {
                // Determine error level for non-JSON lines
                $errorLevel = self::getErrorLevel($string);
                $errorClass = $errorLevel ? "error-level-{$errorLevel}" : '';

                $html .= "<li class=\"{$errorClass}\">";

                // Try to extract timestamp from traditional format
                if (preg_match('/^\[(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}(?::\d{2})?)\]\s*(.*)$/s', $string, $matches)) {
                    $html .= '<span class="timestamp">' . $this->highlightText(htmlspecialchars($matches[1]), $search) . '</span>';
                    $message = $matches[2];
                } else {
                    $message = $string;
                }

                $html .= '<span class="log-content">';
                $message = $this->highlightText(htmlspecialchars($message), $search);
                $html .= trim($message);
                $html .= '</span>';
                $html .= '<div class="buttons-container">';
                $html .= '<button class="copy-btn" onclick="copyToClipboard(this)" title="Copy to clipboard">';
                $html .= '<i class="fas fa-copy"></i></button>';
                $html .= '</div>';
            }

            $html .= '</li>';
        }

        return $html;
    }

    /**
     * Get a brief summary of the context
     * @param array $context The context array
     * @return string A brief summary
     */
    private function getContextSummary($context): string
    {
        $summary = [];

        if (isset($context['type'])) {
            $summary[] = ucfirst($context['type']);
        }

        if (isset($context['exception'])) {
            $exception = $context['exception'];
            if (isset($exception['class'])) {
                $summary[] = $exception['class'];
            }
            if (isset($exception['message'])) {
                $summary[] = $exception['message'];
            }
        }

        return implode(' - ', $summary);
    }

    /**
     * Highlight text in JSON content
     * @param string $content The content to highlight
     * @param string $search The search term
     * @param bool $isJson Whether the content is JSON formatted
     * @return string Highlighted content
     */
    private function highlightText($content, $search, $isJson = false): string
    {
        if (empty($search)) {
            return $content;
        }

        // Normalize search term
        $search = rtrim(preg_replace('/\s+/', ' ', trim($search)), ',');

        if ($isJson) {
            // First, HTML encode the content
            $encodedContent = htmlspecialchars($content);
            
            // Create the pattern to match the search term in different JSON contexts
            $escapedSearch = preg_quote($search, '/');
            
            $patterns = [
                // Match the exact term with quotes (for string values)
                '/(&quot;' . $escapedSearch . '&quot;)(?=[,}\s])/',
                
                // Match within quoted strings without using lookbehind
                '/(&quot;[^&]*?)(' . $escapedSearch . ')([^&]*?&quot;)/',
                
                // Match numbers and non-quoted values using word boundaries
                '/([,{\s:])(' . $escapedSearch . ')(?=[,}\s])/'
            ];

            $replacements = [
                '<span class="highlight">$1</span>',
                
                // For quoted strings, preserve the quotes and only highlight the match
                '$1<span class="highlight">$2</span>$3',
                
                // For non-quoted values, preserve the separator and highlight the match
                '$1<span class="highlight">$2</span>'
            ];

            // Apply each pattern with its corresponding replacement
            for ($i = 0; $i < count($patterns); $i++) {
                $encodedContent = preg_replace(
                    $patterns[$i],
                    $replacements[$i],
                    $encodedContent
                );
            }

            return $encodedContent;
        }

        // For non-JSON content
        return $this->regularHighlight($content, $search);
    }

    /**
     * Regular text highlighting
     * @param string $content
     * @param string $search
     * @return string
     */
    private function regularHighlight($content, $search): string
    {
        if (strpos($search, ':') !== false) {
            list($searchKey, $searchValue) = array_map('trim', explode(':', $search, 2));
            $searchKey = trim($searchKey, '"');
            $searchValue = trim($searchValue, '"');

            return preg_replace(
                '/(' . preg_quote($searchKey, '/') . ')\s*:\s*(' . preg_quote($searchValue, '/') . ')/i',
                '<span class="highlight-key">$1</span>:<span class="highlight-value">$2</span>',
                $content
            );
        }

        return preg_replace(
            '/(' . preg_quote($search, '/') . ')/i',
            '<span class="highlight">$1</span>',
            $content
        );
    }

    /**
     * Render no results message
     * @param string $search Search term that yielded no results
     * @return string HTML content
     */
    private function renderNoResults($search)
    {
        $html = '<div class="no-results">';
        if ($search) {
            $html .= '<i class="fas fa-search"></i>';
            $html .= '<div>No results found for: <strong>' . htmlspecialchars($search) . '</strong></div>';
            $html .= '<div>Try adjusting your search term</div>';
        } else {
            $html .= '<i class="fas fa-file-alt"></i>';
            $html .= '<div>No log entries available</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Get required styles and scripts for the output
     * @return string HTML styles and scripts
     */
    private function getStylesAndScripts(): string
    {
        return <<<HTML
    <style>
        body { 
            font-family: "Courier New", Courier, monospace; 
            font-size: 0.9em; 
            background-color: #f4f4f9; 
            margin: 0; 
            padding: 20px; 
            padding-bottom: 60px;
        }
        ul { 
            list-style-type: none; 
            padding: 0; 
            margin: 0 0 20px 0;
        }
        li { 
            background: #fff; 
            margin: 8px 0; 
            padding: 12px 15px;
            border-radius: 4px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
            word-wrap: break-word; 
            position: relative;
            line-height: 1.6;
            transition: background-color 0.2s;
            overflow: visible !important;
        }
        li:nth-child(odd) { 
            background: #f8f9fa; 
        }
        li:hover {
            background: #f0f0f0;
        }
        .timestamp {
            color: #666;
            display: inline-block;
            margin-right: 10px;
            user-select: none;
        }
        .log-content {
            display: inline-block;
            white-space: pre-wrap;
            word-break: break-word;
            width: 100%;
        }
        .pagination { 
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, 0.95);
            padding: 15px;
            text-align: center;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        .pagination span {
            font-weight: 500;
            color: #495057;
        }
        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .fa { 
            margin-right: 0; 
        }
        .context-info {
            color: #666;
            font-size: 0.9em;
            margin-left: 10px;
        }
        .context-info small {
            opacity: 0.8;
        }
        
        /* Button styles */
        .buttons-container {
            position: absolute;
            right: 10px;
            top: 15px;
            
            display: flex;
            gap: 8px;
            visibility: hidden;
            z-index: 10;
        }
        .json-container .buttons-container {
            right: -100px;
            top: 0;
        }

        li:hover .buttons-container {
            visibility: visible;
        }

        .copy-btn,
        .toggle-json { 
            visibility: visible;
            background: #007bff; 
            color: #fff; 
            border: none; 
            width: 32px;
            height: 32px;
            padding: 0;
            cursor: pointer; 
            border-radius: 3px;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .copy-btn:hover,
        .toggle-json:hover {
            background: #0056b3;
        }

        .copy-btn.copied { 
            background: #28a745; 
        }
        
        /* JSON handling styles */
        .json-container {
            position: relative;
            display: block;
            width: 100%;
        }
        
        .json-content {
            display: none;
            font-family: "Courier New", Courier, monospace;
            width: 100%;
            overflow-x: hidden;
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        .json-content.active {
            display: block;
        }
        
        .json-content.compact {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .json-content.expanded {
            white-space: pre-wrap;
            animation: fadeIn 0.2s ease-out;
        }

        /* Non-JSON log entries */
        .log-content:not(.json-container) {
            display: inline-block;
            width: calc(100% - 100px);
        }

        /* Error level styling */
        .error-level-fatal {
            border-left: 4px solid #dc3545;
            background-color: #fff5f5 !important;
        }

        .error-level-fatal:hover {
            background-color: #ffe0e0 !important;
        }

        .error-level-warning {
            border-left: 4px solid #ffc107;
            background-color: #fffbf0 !important;
        }

        .error-level-warning:hover {
            background-color: #fff3d0 !important;
        }

        .error-level-notice {
            border-left: 4px solid #17a2b8;
            background-color: #f0f9fc !important;
        }

        .error-level-notice:hover {
            background-color: #e0f4f9 !important;
        }

        .error-level-deprecated {
            border-left: 4px solid #6c757d;
            background-color: #f8f9fa !important;
        }

        .error-level-deprecated:hover {
            background-color: #e9ecef !important;
        }

        .error-level-strict {
            border-left: 4px solid #20c997;
            background-color: #f0fcf9 !important;
        }

        .error-level-strict:hover {
            background-color: #e0f9f4 !important;
        }
        
        /* Search highlighting */
        .highlight {
            background-color: #fff3cd;
            padding: 2px 0;
            border-radius: 2px;
            margin: 0 -2px;
        }
        
        .highlight-key {
            background-color: #cce5ff;
            padding: 2px 0;
            border-radius: 2px;
            margin: 0 -2px;
        }
        
        .highlight-value {
            background-color: #d4edda;
            padding: 2px 0;
            border-radius: 2px;
            margin: 0 -2px;
        }

        .context-container {
            margin-top: 10px;
            border-top: 1px solid rgba(0,0,0,0.1);
            padding-top: 10px;
        }

        .context-indicator {
            cursor: pointer;
            color: #666;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .context-indicator i {
            color: #007bff;
        }

        .context-summary {
            font-size: 0.9em;
            color: #666;
        }


        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        li {
            animation: fadeIn 0.2s ease-out;
        }
    </style>
    <script>
        
        document.addEventListener('click', function(e) {
            const contextIndicator = e.target.closest('.context-indicator');
            const toggleButton = e.target.closest('.toggle-json');
            
            if (contextIndicator || toggleButton) {
                const container = e.target.closest('.json-container');
                const compact = container.querySelector('.json-content.compact');
                const expanded = container.querySelector('.json-content.expanded');
                const button = container.closest('li').querySelector('.toggle-json i');
                
                if (compact.classList.contains('active')) {
                    compact.classList.remove('active');
                    expanded.classList.add('active');
                    button.classList.remove('fa-expand');
                    button.classList.add('fa-compress');
                } else {
                    expanded.classList.remove('active');
                    compact.classList.add('active');
                    button.classList.remove('fa-compress');
                    button.classList.add('fa-expand');
                }
            }
        });

        function copyToClipboard(button) {
            var li = button.closest('li');

            var content = li.querySelector('.log-content .main-json-container .expanded') ? li.querySelector('.log-content .main-json-container .expanded').textContent : li.querySelector('.log-content').textContent;
            navigator.clipboard.writeText(content).then(function() {
                button.classList.add('copied');
                button.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(function() {
                    button.classList.remove('copied');
                    button.innerHTML = '<i class="fas fa-copy"></i>';
                }, 2000);
            }, function(err) {
                console.error("Could not copy text: ", err);
                button.innerHTML = '<i class="fas fa-times"></i>';
                setTimeout(function() {
                    button.innerHTML = '<i class="fas fa-copy"></i>';
                }, 2000);
            });
        }
    </script>
HTML;
    }

    /**
     * Render an error message
     * @param string $message Error message to display
     * @return string HTML content
     */
    public function renderError($message)
    {
        return '<html><head><style>
        body { 
            font-family: "Courier New", Courier, monospace; 
            font-size: 0.9em; 
            background-color: #f4f4f9; 
            margin: 0; 
            padding: 20px; 
        }
        .error-message {
            background: #fff;
            border-left: 4px solid #dc3545;
            padding: 20px;
            margin: 20px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            color: #dc3545;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .error-message i {
            font-size: 1.5em;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    </head><body>
    <div class="error-message">
        <i class="fas fa-exclamation-circle"></i>
        ' . $message . '
    </div>
    </body></html>';
    }

    /**
     * Render the log viewer interface
     * 
     * @param string $currentFile Current selected log file
     * @return string HTML content
     * @throws \RuntimeException If view object is not set
     */
    public function renderViewer(string $currentFile): string
    {
        if (!$this->view) {
            throw new \RuntimeException('View object not initialized. Provide a controller in constructor.');
        }
        return $this->view->render($currentFile, $this->whitelist);
    }
}