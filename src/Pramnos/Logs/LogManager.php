<?php

namespace Pramnos\Logs;

/**
 * LogManager class for handling log file maintenance tasks
 * @package     PramnosFramework
 * @subpackage  Logs
 */
class LogManager
{
    /**
     * Default log directory paths
     */
    private const DEFAULT_LOG_PATH = LOG_PATH . DS . 'logs';

    /**
     * Get a list of all log files
     * @param bool $includePath Whether to include the full path
     * @param bool $includeSize Whether to include file size
     * @param string $filter Optional filter pattern for filenames
     * @return array List of log files
     */
    public static function getLogFiles(bool $includePath = false, bool $includeSize = false, string $filter = '*.log'): array
    {
        if (!file_exists(self::DEFAULT_LOG_PATH)) {
            return [];
        }

        $result = [];
        $files = glob(self::DEFAULT_LOG_PATH . DS . $filter);

        foreach ($files as $file) {
            $basename = basename($file);
            
            if ($includeSize) {
                $entry = [
                    'name' => $basename,
                    'size' => filesize($file),
                    'size_formatted' => \Pramnos\General\Helpers::formatBytes(filesize($file)),
                    'modified' => filemtime($file),
                    'modified_formatted' => date('Y-m-d H:i:s', filemtime($file))
                ];
            } else {
                $entry = $includePath ? $file : $basename;
            }
            
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Get file statistics for a specific log file
     * @param string $filename Name of the log file
     * @param string $ext Extension of the log file
     * @return array|null File statistics or null if not found
     */
    public static function getLogFileStats(string $filename, string $ext = 'log'): ?array
    {
        $filepath = self::DEFAULT_LOG_PATH . DS . $filename . '.' . $ext;
        
        if (!file_exists($filepath)) {
            return null;
        }
        
        $size = filesize($filepath);
        $lineCount = 0;
        $jsonCount = 0;
        $levelCounts = [];
        
        // Sample the file for statistics (analyze up to 1000 lines)
        $handle = fopen($filepath, 'r');
        if ($handle) {
            $lineCount = 0;
            $sampleCount = 0;
            $sampleMax = 1000;
            
            while (($line = fgets($handle)) !== false && $sampleCount < $sampleMax) {
                $lineCount++;
                $sampleCount++;
                
                // Try to parse as JSON
                $data = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $jsonCount++;
                    
                    // Count log levels
                    if (isset($data['level'])) {
                        $level = $data['level'];
                        if (!isset($levelCounts[$level])) {
                            $levelCounts[$level] = 0;
                        }
                        $levelCounts[$level]++;
                    }
                }
            }
            
            // If we didn't read the whole file, estimate total lines
            if (!feof($handle)) {
                $position = ftell($handle);
                if ($position > 0) {
                    $lineCount = (int)($lineCount * ($size / $position));
                }
            }
            
            fclose($handle);
        }
        
        return [
            'name' => $filename . '.' . $ext,
            'path' => $filepath,
            'size' => $size,
            'size_formatted' => \Pramnos\General\Helpers::formatBytes($size),
            'lines' => $lineCount,
            'json_percentage' => $sampleCount > 0 ? round(($jsonCount / $sampleCount) * 100) : 0,
            'modified' => filemtime($filepath),
            'modified_formatted' => date('Y-m-d H:i:s', filemtime($filepath)),
            'level_distribution' => $levelCounts
        ];
    }

    /**
     * Clear all log files or a specific set of files
     * @param array|null $fileList List of files to clear, or null for all
     * @return int Number of cleared files
     */
    public static function clearAllLogs(?array $fileList = null): int
    {
        if (!file_exists(self::DEFAULT_LOG_PATH)) {
            return 0;
        }
        
        if ($fileList === null) {
            $files = glob(self::DEFAULT_LOG_PATH . DS . '*.log');
        } else {
            $files = [];
            foreach ($fileList as $file) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if (empty($ext)) {
                    $file .= '.log';
                }
                $files[] = self::DEFAULT_LOG_PATH . DS . $file;
            }
        }
        
        $clearedCount = 0;
        foreach ($files as $file) {
            if (file_exists($file) && is_file($file)) {
                file_put_contents($file, '');
                $clearedCount++;
            }
        }
        
        return $clearedCount;
    }

    /**
     * Archive old log files into zip archives
     * @param int $daysOld Archive files older than this many days
     * @param string $archiveDir Directory to store archives (relative to logs dir)
     * @return array Results of the archiving operation
     */
    public static function archiveOldLogs(int $daysOld = 30, string $archiveDir = 'archives'): array
    {
        if (!file_exists(self::DEFAULT_LOG_PATH)) {
            return ['archived' => 0, 'errors' => ['Log directory does not exist']];
        }
        
        $archivePath = self::DEFAULT_LOG_PATH . DS . $archiveDir;
        if (!file_exists($archivePath)) {
            mkdir($archivePath, 0777, true);
        }
        
        $cutoffTime = time() - ($daysOld * 86400);
        $files = glob(self::DEFAULT_LOG_PATH . DS . '*.log');
        $archived = 0;
        $errors = [];
        
        if (!class_exists('ZipArchive')) {
            return ['archived' => 0, 'errors' => ['ZipArchive not available']];
        }
        
        $zipFile = $archivePath . DS . 'logs_' . date('Y-m-d_His') . '.zip';
        $zip = new \ZipArchive();
        
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            return ['archived' => 0, 'errors' => ['Failed to create ZIP archive']];
        }
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                $basename = basename($file);
                
                if ($zip->addFile($file, $basename)) {
                    $archived++;
                } else {
                    $errors[] = "Failed to add {$basename} to archive";
                }
            }
        }
        
        $zip->close();
        
        // Delete the original files if they were successfully archived
        if ($archived > 0 && empty($errors)) {
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    unlink($file);
                }
            }
        }
        
        return [
            'archived' => $archived,
            'archive_file' => $zipFile,
            'errors' => $errors
        ];
    }

    /**
     * Scan log files for specific text
     * @param string $searchText Text to search for
     * @param array|null $fileList Files to search in, or null for all
     * @param int $contextLines Number of context lines to include
     * @param bool $caseSensitive Whether search should be case sensitive
     * @return array Search results
     */
    public static function searchInLogs(
        string $searchText,
        ?array $fileList = null,
        int $contextLines = 2,
        bool $caseSensitive = false
    ): array {
        if (!file_exists(self::DEFAULT_LOG_PATH)) {
            return [];
        }
        
        if ($fileList === null) {
            $files = glob(self::DEFAULT_LOG_PATH . DS . '*.log');
        } else {
            $files = [];
            foreach ($fileList as $file) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if (empty($ext)) {
                    $file .= '.log';
                }
                $files[] = self::DEFAULT_LOG_PATH . DS . $file;
            }
        }
        
        $results = [];
        
        foreach ($files as $file) {
            if (!file_exists($file) || !is_file($file)) {
                continue;
            }
            
            $basename = basename($file);
            $matches = [];
            $handle = fopen($file, 'r');
            
            if (!$handle) {
                continue;
            }
            
            $lineBuffer = [];
            $lineNumber = 0;
            
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                
                // Store line in circular buffer for context
                $lineBuffer[$lineNumber % ($contextLines * 2 + 1)] = $line;
                
                // Check if line contains search text
                if (($caseSensitive && strpos($line, $searchText) !== false) ||
                    (!$caseSensitive && stripos($line, $searchText) !== false)) {
                    
                    // Get context lines
                    $context = [];
                    for ($i = max(1, $lineNumber - $contextLines); $i <= $lineNumber + $contextLines; $i++) {
                        if ($i == $lineNumber) {
                            // Mark the matching line
                            $context[$i] = [
                                'text' => $i <= $lineNumber ? ($lineBuffer[$i % ($contextLines * 2 + 1)] ?? '') : '',
                                'match' => true
                            ];
                        } else {
                            $context[$i] = [
                                'text' => $i <= $lineNumber ? ($lineBuffer[$i % ($contextLines * 2 + 1)] ?? '') : '',
                                'match' => false
                            ];
                        }
                    }
                    
                    $matches[] = [
                        'line' => $lineNumber,
                        'context' => $context
                    ];
                    
                    // Limit the number of matches per file to prevent huge result sets
                    if (count($matches) >= 100) {
                        break;
                    }
                }
            }
            
            fclose($handle);
            
            if (!empty($matches)) {
                $results[] = [
                    'file' => $basename,
                    'path' => $file,
                    'matches' => $matches,
                    'count' => count($matches)
                ];
            }
        }
        
        return $results;
    }



    /**
     * Process a log file with a callback function
     * @param string $filename The log file name
     * @param string $ext The log file extension 
     * @param callable $callback Callback function to process each line
     * @return bool Success status
     */
    public static function processLogFileWithCallback(string $filename, string $ext, callable $callback): bool
    {
        $filepath = self::DEFAULT_LOG_PATH . DS . $filename . '.' . $ext;
        
        if (!file_exists($filepath)) {
            return false;
        }
        
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return false;
        }
        
        while (($line = fgets($handle)) !== false) {
            $callback(trim($line));
        }
        
        fclose($handle);
        return true;
    }

    /**
     * Get path to a log file
     * @param string $filename The log file name
     * @param string $ext The log file extension
     * @return string Full path to the log file
     */
    public static function getLogFilePath(string $filename, string $ext = 'log'): string
    {
        if ($filename === 'GitDeploy' || $filename === 'GitWebhookDebug') {
            return ROOT . DS . 'www' . DS . 'api' . DS . $filename . ($ext ? '.' . $ext : '');
        } else {
            return self::DEFAULT_LOG_PATH . DS . $filename . ($ext ? '.' . $ext : '');
        }
    }
    
    /**
     * Get log analytics for a time period
     * @param string $filename The log file name
     * @param string $ext The log file extension
     * @param int $startTime Start timestamp
     * @param int $endTime End timestamp
     * @param string $groupBy How to group data (minute, hour, day)
     * @return array Analytics data
     */
    public static function getLogAnalytics(
        string $filename, 
        string $ext = 'log', 
        int $startTime = 0, 
        int $endTime = 0,
        string $groupBy = 'hour'
    ): array
    {
        $filepath = self::getLogFilePath($filename, $ext);
        
        if (!file_exists($filepath)) {
            return [];
        }
        
        // Default to last 24 hours if not specified
        if ($startTime === 0) {
            $startTime = time() - 86400;
        }
        if ($endTime === 0) {
            $endTime = time();
        }
        
        $trends = [];
        $levels = [];
        $topErrors = [];
        $lastEntry = null;
        $totalEntries = 0;
        $errorCount = 0;
        
        // Initialize time buckets based on groupBy
        $currentTime = $startTime;
        while ($currentTime <= $endTime) {
            $bucket = $currentTime;
            $trends[$bucket] = 0;
            
            switch ($groupBy) {
                case 'minute':
                    $currentTime += 60; // 1 minute
                    break;
                case 'day':
                    $currentTime += 86400; // 1 day
                    break;
                case 'hour':
                default:
                    $currentTime += 3600; // 1 hour
                    break;
            }
        }
        
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return [];
        }
        
        while (($line = fgets($handle)) !== false) {
            $timestamp = time(); // Default to current time
            $level = 'info'; // Default level
            $message = '';
            $isError = false;
            
            // Check if line is JSON formatted
            if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
                try {
                    $data = json_decode($line, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // Extract timestamp
                        $timestampStr = $data['timestamp'] ?? $data['datetime'] ?? '';
                        if ($timestampStr) {
                            $parsedTime = strtotime($timestampStr);
                            if ($parsedTime !== false) {
                                $timestamp = $parsedTime;
                            }
                        }
                        
                        // Extract level
                        $level = strtolower($data['level'] ?? 'info');
                        
                        // Extract message
                        $message = $data['message'] ?? '';
                        
                        // Check if error
                        $isError = in_array($level, ['emergency', 'alert', 'critical', 'error']);
                    }
                } catch (\Exception $e) {
                    // Not valid JSON, continue with raw line
                }
            } 
            // Try to extract timestamp from standard log format [date/time]
            elseif (preg_match('/^\[([\d\/]+ [\d:]+)\]/', $line, $matches)) {
                $timestampStr = $matches[1];
                $parsedTime = strtotime($timestampStr);
                if ($parsedTime !== false) {
                    $timestamp = $parsedTime;
                }
                
                $message = $line;
                
                // Try to guess if it's an error
                if (stripos($line, 'error') !== false || stripos($line, 'exception') !== false || 
                    stripos($line, 'fatal') !== false) {
                    $isError = true;
                    $level = 'error';
                }
            }
            
            // Check if timestamp is within range
            if ($timestamp >= $startTime && $timestamp <= $endTime) {
                $totalEntries++;
                
                // Find the appropriate time bucket
                foreach (array_keys($trends) as $bucket) {
                    $nextBucket = $bucket;
                    switch ($groupBy) {
                        case 'minute':
                            $nextBucket += 60;
                            break;
                        case 'day':
                            $nextBucket += 86400;
                            break;
                        case 'hour':
                        default:
                            $nextBucket += 3600;
                            break;
                    }
                    
                    if ($timestamp >= $bucket && $timestamp < $nextBucket) {
                        $trends[$bucket]++;
                        break;
                    }
                }
                
                // Count by level
                if (!isset($levels[$level])) {
                    $levels[$level] = 0;
                }
                $levels[$level]++;
                
                // Track last entry
                if ($lastEntry === null || $timestamp > strtotime($lastEntry)) {
                    $lastEntry = date('Y-m-d H:i:s', $timestamp);
                }
                
                // Count errors
                if ($isError) {
                    $errorCount++;
                    
                    // Track top errors
                    $shortMessage = substr($message, 0, 200);
                    $hash = md5($shortMessage);
                    
                    if (!isset($topErrors[$hash])) {
                        $topErrors[$hash] = [
                            'message' => $shortMessage,
                            'count' => 0,
                            'timestamp' => date('Y-m-d H:i:s', $timestamp)
                        ];
                    }
                    
                    $topErrors[$hash]['count']++;
                    if (strtotime($topErrors[$hash]['timestamp']) < $timestamp) {
                        $topErrors[$hash]['timestamp'] = date('Y-m-d H:i:s', $timestamp);
                    }
                }
            }
        }
        
        fclose($handle);
        
        // Sort top errors by count
        uasort($topErrors, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        // Get only top 10
        $topErrors = array_values(array_slice($topErrors, 0, 10));
        
        return [
            'trends' => $trends,
            'levels' => $levels,
            'topErrors' => $topErrors,
            'lastEntry' => $lastEntry,
            'totalEntries' => $totalEntries,
            'errorRate' => $totalEntries > 0 ? round(($errorCount / $totalEntries) * 100, 1) : 0
        ];
    }
    
    /**
     * Get filtered log entries
     * @param string $filename The log file name
     * @param string $ext The log file extension
     * @param array $levels Log levels to include
     * @param int|null $startTime Start timestamp
     * @param int|null $endTime End timestamp
     * @param string $query Search query
     * @param int $limit Maximum number of results
     * @return array Filtered log entries
     */
    public static function getFilteredLogEntries(
        string $filename,
        string $ext = 'log',
        array $levels = [],
        ?int $startTime = null,
        ?int $endTime = null,
        string $query = '',
        int $limit = 100
    ): array
    {
        $filepath = self::getLogFilePath($filename, $ext);
        
        if (!file_exists($filepath)) {
            return [];
        }
        
        $entries = [];
        $counter = 0;
        
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return [];
        }
        
        while (($line = fgets($handle)) !== false && count($entries) < $limit) {
            $timestamp = time(); // Default to current time
            $level = 'info'; // Default level
            $message = '';
            $context = [];
            $include = true;
            
            // Check if line is JSON formatted
            if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
                try {
                    $data = json_decode($line, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // Extract timestamp
                        $timestampStr = $data['timestamp'] ?? $data['datetime'] ?? '';
                        if ($timestampStr) {
                            $parsedTime = strtotime($timestampStr);
                            if ($parsedTime !== false) {
                                $timestamp = $parsedTime;
                            }
                        }
                        
                        // Extract level
                        $level = strtolower($data['level'] ?? 'info');
                        
                        // Extract message
                        $message = $data['message'] ?? '';
                        
                        // Extract context
                        $context = $data['context'] ?? [];
                    } else {
                        // Not valid JSON, treat as plain text
                        $message = $line;
                    }
                } catch (\Exception $e) {
                    // Error parsing JSON, treat as plain text
                    $message = $line;
                }
            } 
            // Try to extract timestamp from standard log format [date/time]
            elseif (preg_match('/^\[([\d\/]+ [\d:]+)\](.*)$/', $line, $matches)) {
                $timestampStr = $matches[1];
                $parsedTime = strtotime($timestampStr);
                if ($parsedTime !== false) {
                    $timestamp = $parsedTime;
                }
                
                $message = $matches[2];
                
                // Try to guess level from message
                if (stripos($message, 'error') !== false || stripos($message, 'exception') !== false) {
                    $level = 'error';
                } elseif (stripos($message, 'warning') !== false) {
                    $level = 'warning';
                } elseif (stripos($message, 'notice') !== false) {
                    $level = 'notice';
                } elseif (stripos($message, 'info') !== false) {
                    $level = 'info';
                } elseif (stripos($message, 'debug') !== false) {
                    $level = 'debug';
                }
            } else {
                // Unrecognized format, use as is
                $message = $line;
            }
            
            // Apply filters
            
            // Filter by time range
            if ($startTime !== null && $timestamp < $startTime) {
                $include = false;
            }
            if ($endTime !== null && $timestamp > $endTime) {
                $include = false;
            }
            
            // Filter by levels
            if (!empty($levels) && !in_array($level, $levels)) {
                $include = false;
            }
            
            // Filter by search query
            if (!empty($query)) {
                $found = false;
                
                // Search in message
                if (stripos($message, $query) !== false) {
                    $found = true;
                }
                
                // Search in context (if JSON)
                if (!$found && !empty($context)) {
                    $contextJson = json_encode($context);
                    if (stripos($contextJson, $query) !== false) {
                        $found = true;
                    }
                }
                
                if (!$found) {
                    $include = false;
                }
            }
            
            // Add entry if it passes all filters
            if ($include) {
                $entries[] = [
                    'id' => $counter++,
                    'timestamp' => date('Y-m-d H:i:s', $timestamp),
                    'level' => $level,
                    'message' => $message,
                    'context' => $context
                ];
            }
        }
        
        fclose($handle);
        
        return $entries;
    }
}