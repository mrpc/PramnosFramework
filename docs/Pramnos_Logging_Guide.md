# Pramnos Framework - Logging System Guide

The Pramnos Framework includes a comprehensive logging system with structured logging support, log analytics, file management, and advanced viewing capabilities. This guide covers all aspects of the logging system from basic usage to advanced features.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Basic Logging Usage](#basic-logging-usage)
3. [Structured Logging](#structured-logging)
4. [Log Levels and PSR-3 Compliance](#log-levels-and-psr-3-compliance)
5. [Log File Management](#log-file-management)
6. [Log Viewer and Analytics](#log-viewer-and-analytics)
7. [Log Migration and Format Conversion](#log-migration-and-format-conversion)
8. [Console Commands](#console-commands)
9. [Web Interface](#web-interface)
10. [Performance and Best Practices](#performance-and-best-practices)
11. [Configuration](#configuration)
12. [Advanced Features](#advanced-features)

## Architecture Overview

The Pramnos logging system consists of several key components:

```
src/Pramnos/Logs/
├── Logger.php           # Core logging functionality
├── LogManager.php       # Log file management and analytics
├── LogViewer.php        # Log reading and viewing
├── LogMigrator.php      # Format migration tools
└── Application/Controllers/LogController.php  # Web interface
```

### Key Features

- **PSR-3 Compatible**: Standard log levels and interfaces
- **Structured Logging**: JSON-based log entries with context
- **Real-time Viewing**: Live log monitoring with auto-refresh
- **Analytics Dashboard**: Trends, error tracking, and statistics
- **File Management**: Rotation, archiving, and cleanup
- **Export Capabilities**: CSV, JSON, and ZIP export formats
- **Search and Filtering**: Advanced log search with multiple criteria
- **Migration Tools**: Convert legacy log formats to structured format

## Basic Logging Usage

### Simple Logging

```php
use Pramnos\Logs\Logger;

// Basic log entry
Logger::log('User login successful', 'application');

// Log with custom file and extension
Logger::log('Database connection established', 'database', 'log');

// Log to beginning of file (for critical messages)
Logger::log('System startup initiated', 'system', 'log', true);
```

### Using Log Level Methods

```php
// Emergency: System is unusable
Logger::emergency('Database server is down');

// Alert: Action must be taken immediately
Logger::alert('Disk space critically low');

// Critical: Critical conditions
Logger::critical('Application component failed');

// Error: Runtime errors that do not require immediate action
Logger::error('Failed to send email notification');

// Warning: Exceptional occurrences that are not errors
Logger::warning('Deprecated function called');

// Notice: Normal but significant events
Logger::notice('User password changed');

// Info: Interesting events
Logger::info('User logged in successfully');

// Debug: Detailed debug information
Logger::debug('Processing user data', ['user_id' => 123]);
```

## Structured Logging

### Adding Context to Log Entries

```php
// Log with additional context
Logger::info('User action performed', [
    'user_id' => 123,
    'action' => 'profile_update',
    'ip_address' => '192.168.1.1',
    'user_agent' => 'Mozilla/5.0...',
    'timestamp' => time(),
    'session_id' => session_id()
]);

// Error logging with stack trace
try {
    // Some operation
} catch (Exception $e) {
    Logger::error('Operation failed', [
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'context' => [
            'user_id' => $user->id ?? null,
            'operation' => 'data_processing'
        ]
    ]);
}
```

### Performance Logging

```php
// Performance monitoring
$startTime = microtime(true);

// ... perform operation ...

$endTime = microtime(true);
$duration = $endTime - $startTime;

Logger::info('Operation completed', [
    'operation' => 'data_export',
    'duration_ms' => round($duration * 1000, 2),
    'memory_peak' => memory_get_peak_usage(true),
    'memory_current' => memory_get_usage(true),
    'records_processed' => $recordCount
]);
```

### Database Operation Logging

```php
// Database query logging
Logger::debug('Database query executed', [
    'query' => $sql,
    'params' => $parameters,
    'execution_time' => $queryTime,
    'affected_rows' => $affectedRows,
    'connection' => $dbConnection->getName()
]);
```

## Log Levels and PSR-3 Compliance

The logging system follows PSR-3 standards with eight severity levels:

| Level | Constant | Usage |
|-------|----------|--------|
| emergency | `Logger::LEVEL_EMERGENCY` | System is unusable |
| alert | `Logger::LEVEL_ALERT` | Action must be taken immediately |
| critical | `Logger::LEVEL_CRITICAL` | Critical conditions |
| error | `Logger::LEVEL_ERROR` | Runtime errors |
| warning | `Logger::LEVEL_WARNING` | Exceptional occurrences that are not errors |
| notice | `Logger::LEVEL_NOTICE` | Normal but significant events |
| info | `Logger::LEVEL_INFO` | Interesting events |
| debug | `Logger::LEVEL_DEBUG` | Detailed debug information |

### Level-Based Filtering

```php
// Only log errors and above in production
if (ENVIRONMENT === 'production') {
    Logger::setMinimumLevel(Logger::LEVEL_ERROR);
}

// Log everything in development
if (ENVIRONMENT === 'development') {
    Logger::setMinimumLevel(Logger::LEVEL_DEBUG);
}
```

## Log File Management

### File Rotation

```php
use Pramnos\Logs\Logger;

// Rotate log file when it exceeds 10MB, keep 5 backups
Logger::truncateLogFile('application', 'log', 10 * 1024 * 1024, true, 5);

// Simple truncation without backup
Logger::truncateLogFile('debug', 'log', 5 * 1024 * 1024, false);
```

### Automatic Log Management

```php
use Pramnos\Logs\LogManager;

// Get statistics for all log files
$stats = LogManager::getLogFiles(true, true);

foreach ($stats as $file => $info) {
    echo "File: {$file}, Size: {$info['size']}, Lines: {$info['lines']}\n";
}

// Archive old log files (older than 30 days)
$result = LogManager::archiveOldLogs(30);
echo "Archived {$result['archived']} files\n";

// Clear specific log files
LogManager::clearLog('debug'); // Clear debug.log
LogManager::clearLog('application', 'log'); // Clear application.log
```

### Log File Statistics

```php
// Get detailed statistics for a log file
$stats = LogManager::getLogFileStats('application', 'log');

echo "File size: {$stats['size_formatted']}\n";
echo "Line count: {$stats['lines']}\n";
echo "JSON percentage: {$stats['json_percentage']}%\n";
echo "Modified: {$stats['modified_formatted']}\n";

// Level distribution
foreach ($stats['level_distribution'] as $level => $count) {
    echo "Level {$level}: {$count} entries\n";
}
```

## Log Viewer and Analytics

### Real-time Log Viewing

```php
use Pramnos\Logs\LogViewer;

// Create log viewer instance
$viewer = new LogViewer();

// Configure viewing options
$viewer->setFile('application.log')
       ->setParameters(
           true,  // reverse order (newest first)
           1,     // page number
           50,    // max lines per page
           'error' // search term
       )
       ->setLogLevel('error'); // filter by level

// Get log content
$result = $viewer->getLogContent();

// Display results
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}

echo "Total lines: {$result['total']}\n";
echo "Matched lines: {$result['matched_total']}\n";
```

### Log Analytics

```php
use Pramnos\Logs\LogManager;

// Get analytics for the last 24 hours
$analytics = LogManager::getLogAnalytics(
    'application',           // filename
    'log',                  // extension
    time() - 86400,         // start time (24 hours ago)
    time(),                 // end time (now)
    'hour'                  // group by hour
);

// Display trends
echo "Log entry trends:\n";
foreach ($analytics['trends'] as $timestamp => $count) {
    $time = date('H:i', $timestamp);
    echo "{$time}: {$count} entries\n";
}

// Display level distribution
echo "\nLevel distribution:\n";
foreach ($analytics['levels'] as $level => $count) {
    echo "{$level}: {$count} entries\n";
}

// Display top errors
echo "\nTop errors:\n";
foreach ($analytics['topErrors'] as $error) {
    echo "Count: {$error['count']}, Message: {$error['message']}\n";
}
```

### Advanced Filtering

```php
// Filter logs by multiple criteria
$entries = LogManager::getFilteredLogEntries(
    'application',           // filename
    'log',                  // extension
    ['error', 'critical'],  // levels to include
    strtotime('-1 week'),   // start timestamp
    time(),                 // end timestamp
    'database',             // search query
    100                     // limit
);

foreach ($entries as $entry) {
    echo "[{$entry['timestamp']}] {$entry['level']}: {$entry['message']}\n";
    if (!empty($entry['context'])) {
        echo "Context: " . json_encode($entry['context']) . "\n";
    }
}
```

## Log Migration and Format Conversion

### Migrating Legacy Log Formats

```php
use Pramnos\Logs\LogMigrator;

// Create migrator with progress callback
$migrator = new LogMigrator(function($processed, $total) {
    $percent = round(($processed / $total) * 100);
    echo "Progress: {$percent}% ({$processed}/{$total})\n";
});

// Migrate a single file
$stats = $migrator->migrateFile('/path/to/old-format.log', true);

echo "Migration completed:\n";
echo "Total lines: {$stats['total_lines']}\n";
echo "Converted lines: {$stats['converted_lines']}\n";
echo "Errors: {$stats['errors']}\n";

// Migrate all files in directory
$files = glob('/path/to/logs/*.log');
foreach ($files as $file) {
    try {
        $stats = $migrator->migrateFile($file, true);
        echo "Migrated {$file}: {$stats['converted_lines']} lines\n";
    } catch (Exception $e) {
        echo "Failed to migrate {$file}: " . $e->getMessage() . "\n";
    }
}
```

### Bulk Processing

```php
// Process multiple files with error handling
$migrator = new LogMigrator();
$results = $migrator->migrateDirectory('/path/to/logs', [
    'backup' => true,
    'skip_errors' => false,
    'chunk_size' => 8 * 1024 * 1024 // 8MB chunks
]);

foreach ($results as $file => $result) {
    if ($result['success']) {
        echo "✓ {$file}: {$result['converted_lines']} lines converted\n";
    } else {
        echo "✗ {$file}: {$result['error']}\n";
    }
}
```

## Console Commands

### Migrate Logs Command

```bash
# Migrate a single log file
php bin/pramnos migrate:logs /path/to/file.log --backup

# Migrate all logs in directory
php bin/pramnos migrate:logs /path/to/logs --all --backup

# Migrate without backup
php bin/pramnos migrate:logs /path/to/file.log --no-backup

# Display help
php bin/pramnos migrate:logs --help
```

### Log Management Commands

```bash
# View log file statistics
php bin/pramnos logs:stats

# Rotate large log files
php bin/pramnos logs:rotate --max-size=10 --backups=5

# Archive old logs
php bin/pramnos logs:archive --days=30

# Clear debug logs
php bin/pramnos logs:clear debug

# Export logs to CSV
php bin/pramnos logs:export application.log --format=csv

# Monitor logs in real-time
php bin/pramnos logs:tail application.log --follow
```

## Web Interface

### Accessing the Log Viewer

The web interface is accessible through the LogController:

```
/logs                   # Main log listing
/logs/view/filename     # View specific log file
/logs/stats             # Log file statistics
/logs/dashboard         # Analytics dashboard
/logs/export            # Export logs
/logs/rotate            # Rotate log files
/logs/archive           # Archive old logs
/logs/search            # Advanced search
```

### Log Viewer Features

- **Real-time Updates**: Auto-refresh capabilities
- **Search and Filter**: Full-text search with regex support
- **Level Filtering**: Filter by log levels
- **Pagination**: Navigate through large log files
- **Export Options**: Download as CSV, JSON, or raw format
- **Date Range Export**: Export logs within specific time ranges
- **Context Display**: Expandable JSON context for structured logs

### Dashboard Analytics

The dashboard provides:

- **Trend Charts**: Visual representation of log entry patterns
- **Level Distribution**: Pie charts showing log level breakdown
- **Error Tracking**: Top errors with occurrence counts
- **System Status**: Health overview across all log files
- **Performance Metrics**: Response times and resource usage

## Performance and Best Practices

### Optimal Logging Practices

```php
// 1. Use appropriate log levels
Logger::debug('Detailed debugging info');      // Development only
Logger::info('Normal application flow');       // General information
Logger::warning('Unusual but handled event');  // Potential issues
Logger::error('Error that needs attention');   // Actual problems

// 2. Include relevant context
Logger::error('Database query failed', [
    'query' => $sql,
    'error' => $e->getMessage(),
    'user_id' => $currentUser->id,
    'execution_time' => $queryTime
]);

// 3. Avoid logging sensitive data
Logger::info('User login attempt', [
    'username' => $username,
    'ip' => $ipAddress,
    // DON'T LOG: 'password' => $password
    'success' => $loginSuccess
]);

// 4. Use structured logging for complex data
Logger::info('API request processed', [
    'endpoint' => $endpoint,
    'method' => $method,
    'status_code' => $response->getStatusCode(),
    'response_time' => $responseTime,
    'request_id' => $requestId
]);
```

### Performance Considerations

```php
// 1. Conditional debug logging
if (Logger::isDebugEnabled()) {
    Logger::debug('Expensive debug info', [
        'data' => $expensiveToSerializeData
    ]);
}

// 2. Lazy evaluation for expensive operations
Logger::info('Operation result', [
    'result' => function() use ($expensiveOperation) {
        return $expensiveOperation->getResult();
    }
]);

// 3. Log rotation to prevent large files
Logger::truncateLogFile('application', 'log', 50 * 1024 * 1024); // 50MB max

// 4. Archive old logs regularly
LogManager::archiveOldLogs(30); // Archive logs older than 30 days
```

### Memory Management

```php
// Use log viewer with chunked reading for large files
$viewer = new LogViewer();
$viewer->setFile('large-log.log')
       ->setChunkSize(8 * 1024 * 1024) // 8MB chunks
       ->setMaxLines(100); // Limit results

// Process logs in batches
LogManager::processLogFile('application', 'log', function($line) {
    // Process each line
    echo $line . "\n";
}, 1000); // Process 1000 lines at a time
```

## Configuration

### Log Directory Structure

```
app/logs/
├── application.log      # Main application logs
├── error.log           # Error-level logs only
├── debug.log           # Debug information
├── performance.log     # Performance metrics
├── security.log        # Security events
├── api.log             # API request logs
└── archives/           # Archived log files
    ├── logs_2024-01-01.zip
    └── logs_2024-01-02.zip
```

### Environment-Specific Configuration

```php
// config/logging.php
return [
    'default_level' => ENVIRONMENT === 'production' ? 'error' : 'debug',
    'max_file_size' => 50 * 1024 * 1024, // 50MB
    'max_backups' => 5,
    'archive_after_days' => 30,
    'structured_logging' => true,
    'include_context' => ENVIRONMENT !== 'production',
    'files' => [
        'application' => [
            'level' => 'info',
            'max_size' => 100 * 1024 * 1024
        ],
        'error' => [
            'level' => 'error',
            'max_size' => 50 * 1024 * 1024
        ],
        'debug' => [
            'level' => 'debug',
            'max_size' => 200 * 1024 * 1024,
            'enabled' => ENVIRONMENT !== 'production'
        ]
    ]
];
```

## Advanced Features

### Custom Log Handlers

```php
// Create custom log handler
class EmailAlertHandler extends Logger
{
    public static function critical($message, $context = [])
    {
        // Log normally
        parent::critical($message, $context);
        
        // Send email alert for critical errors
        $emailData = [
            'subject' => 'Critical Error Alert',
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'server' => $_SERVER['SERVER_NAME'] ?? 'Unknown'
        ];
        
        // Send email using framework's email system
        Email::send('admin@example.com', 'Critical Error Alert', $emailData);
    }
}

// Use custom handler
EmailAlertHandler::critical('Database connection lost');
```

### Log Aggregation

```php
// Aggregate logs from multiple sources
class LogAggregator
{
    public static function aggregateLogs($startDate, $endDate)
    {
        $aggregated = [];
        $logFiles = ['application', 'api', 'error', 'performance'];
        
        foreach ($logFiles as $logFile) {
            $entries = LogManager::getFilteredLogEntries(
                $logFile, 'log', [], 
                strtotime($startDate), 
                strtotime($endDate)
            );
            
            foreach ($entries as $entry) {
                $entry['source'] = $logFile;
                $aggregated[] = $entry;
            }
        }
        
        // Sort by timestamp
        usort($aggregated, function($a, $b) {
            return strtotime($a['timestamp']) - strtotime($b['timestamp']);
        });
        
        return $aggregated;
    }
}

// Use aggregator
$logs = LogAggregator::aggregateLogs('2024-01-01', '2024-01-31');
```

### Real-time Log Monitoring

```php
// WebSocket-based real-time log monitoring
class LogMonitor
{
    private $watchers = [];
    
    public function watchFile($filename, $callback)
    {
        $this->watchers[$filename] = [
            'callback' => $callback,
            'last_position' => filesize(Logger::getLogPath($filename, 'log'))
        ];
    }
    
    public function monitor()
    {
        while (true) {
            foreach ($this->watchers as $filename => $watcher) {
                $filepath = Logger::getLogPath($filename, 'log');
                $currentSize = filesize($filepath);
                
                if ($currentSize > $watcher['last_position']) {
                    $handle = fopen($filepath, 'r');
                    fseek($handle, $watcher['last_position']);
                    
                    while (($line = fgets($handle)) !== false) {
                        call_user_func($watcher['callback'], trim($line));
                    }
                    
                    $this->watchers[$filename]['last_position'] = $currentSize;
                    fclose($handle);
                }
            }
            
            usleep(100000); // 100ms delay
        }
    }
}

// Use monitor
$monitor = new LogMonitor();
$monitor->watchFile('application', function($line) {
    echo "New log entry: {$line}\n";
});
$monitor->monitor();
```

### Log Encryption

```php
// Encrypt sensitive log data
class EncryptedLogger extends Logger
{
    private static function encryptContext($context)
    {
        if (empty($context)) return $context;
        
        $sensitiveFields = ['password', 'token', 'api_key', 'credit_card'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($context[$field])) {
                $context[$field] = self::encrypt($context[$field]);
            }
        }
        
        return $context;
    }
    
    private static function encrypt($data)
    {
        $key = ENCRYPTION_KEY;
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public static function log($message, $file = 'application', $ext = 'log', $startoffile = false, $context = [])
    {
        $context = self::encryptContext($context);
        parent::log($message, $file, $ext, $startoffile, $context);
    }
}
```

## Troubleshooting

### Common Issues

1. **Large Log Files**: Use log rotation and archiving
2. **Memory Issues**: Process logs in chunks
3. **Permission Problems**: Ensure proper directory permissions
4. **Missing Context**: Verify structured logging is enabled
5. **Search Performance**: Use indexed search for large files

### Debugging Log Issues

```php
// Check log system health
$health = [
    'log_directory_exists' => is_dir(LOG_PATH . '/logs'),
    'log_directory_writable' => is_writable(LOG_PATH . '/logs'),
    'disk_space' => disk_free_space(LOG_PATH),
    'active_log_files' => count(LogManager::getLogFiles()),
    'total_log_size' => LogManager::getTotalLogSize()
];

foreach ($health as $check => $status) {
    echo "{$check}: " . ($status ? 'OK' : 'FAILED') . "\n";
}
```

---

## Related Documentation

- [Framework Guide](Pramnos_Framework_Guide.md) - Core framework concepts
- [Database API Guide](Pramnos_Database_API_Guide.md) - Database operations
- [Authentication Guide](Pramnos_Authentication_Guide.md) - User authentication
- [Console Commands Guide](Pramnos_Console_Guide.md) - CLI tools and commands
- [Cache System Guide](Pramnos_Cache_Guide.md) - Caching strategies

---

The Pramnos Framework logging system provides enterprise-grade logging capabilities with comprehensive tools for development, monitoring, and production environments. For additional support or advanced configurations, refer to the framework documentation or community resources.
