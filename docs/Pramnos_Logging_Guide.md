---
use_cases:
  - Writing a log entry, structured or plain
  - Choosing a PSR-3 log level
  - Reading, rotating or migrating log files
  - Using the log viewer or the log console commands
  - Getting the log dashboard's numbers from code, a command or an MCP tool
  - Finding which command or request ran out of memory or died of a fatal error
  - Logging an exception without writing the arguments its calls carried
  - Finding out which JavaScript errors visitors hit in the browser
  - Measuring how fast pages are for real visitors (Core Web Vitals)
---

# Pramnos Framework - Logging System Guide

The Pramnos Framework includes a comprehensive logging system with structured logging support, log analytics, file management, and advanced viewing capabilities. This guide covers all aspects of the logging system from basic usage to advanced features.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Basic Logging Usage](#basic-logging-usage)
3. [Structured Logging](#structured-logging)
4. [Log Levels and PSR-3 Compliance](#log-levels-and-psr-3-compliance)
5. [Log File Management](#log-file-management)
6. [Stack traces are written without arguments](#stack-traces-are-written-without-arguments)
7. [When the process dies: `fatal.log`](#when-the-process-dies-fatallog)
8. [Log Viewer and Analytics](#log-viewer-and-analytics)
9. [Log Migration and Format Conversion](#log-migration-and-format-conversion)
10. [Console Commands](#console-commands)
11. [Web Interface](#web-interface)
12. [Errors in the browser](#errors-in-the-browser)
13. [How fast pages are for real visitors](#how-fast-pages-are-for-real-visitors)
14. [Performance and Best Practices](#performance-and-best-practices)
15. [Configuration](#configuration)
16. [Advanced Features](#advanced-features)

## Architecture Overview

The Pramnos logging system consists of several key components:

```
src/Pramnos/Logs/
├── Logger.php           # Core logging functionality
├── LogManager.php       # Log file management and analytics
├── LogViewer.php        # Log reading and viewing
├── LogMigrator.php      # Format migration tools
├── LogAnalytics.php     # The dashboard's figures, for code, commands and MCP tools
├── FatalErrorReporter.php # fatal.log on a process that dies
├── Trace.php            # Stack traces without arguments
└── PsrLogger.php        # PSR-3 adapter (Logger::channel())

src/Pramnos/Application/Controllers/LogController.php  # Web interface (/admin/logs)
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

### The three query logs `DEVELOPMENT` turns on

Separate from `Logger`, and automatic: when `DEVELOPMENT` is true, `connect()` opens three files
under `LOG_PATH/logs/`. Nothing to call — but worth knowing they exist, because the second one
answers a question that is hard to answer any other way.

| File | What is in it |
|---|---|
| `databaseQueries.log` | Every statement of the request, numbered, with its time. Each request is preceded by a rule carrying the date, the query count and the URL. |
| `duplicateQueries.log` | Only statements the request issued **more than once**, under one header per request. |
| the slow-query log | Statements over `$db->longQueryTime` seconds, each with the threshold it crossed. Written only if there was at least one. |

`duplicateQueries.log` is the one to reach for when a page is slow and no individual query looks
slow enough to explain it. That shape — one request asking the same question in a loop — is
invisible in a list of timings, because every individual answer is fast. It is the whole reason the
file is separate from the query log rather than a column in it.

Three bounds, because unbounded logs hit the machine least able to spare it — a developer's,
running a whole suite in one process:

- the accumulated query text is written out once it passes 256 KB, rather than only in the
  destructor — a process killed by the memory limit never reaches its destructor, so the log would
  be empty on exactly the run somebody needed it for;
- the duplicate detector remembers 5,000 statement fingerprints and then forgets the oldest half.
  What that loses is a statement seen once at the start of a very long process and again at the end,
  which is two requests rather than one request asking twice;
- a log over 512 KB is rotated to `.log.old`, one generation only.

None of it runs in production: `startLogs()` is called from `connect()` only under `DEVELOPMENT`,
and `stopLogs()` on a request with no logs open does nothing.

### The entry's `type`, and the JSON sniff

`log()` takes a `$context` array, and `type` is the field a log viewer filters on:

```php
Logger::log('Could not reach the push service', 'push', context: ['type' => 'error']);
```

When you do **not** set it, the message is sniffed: if it parses as JSON, `type` becomes `json`.
When you **do** set it, the sniff is skipped and your value survives.

The second half matters because a great deal parses as JSON — a bare number, `true`, `null`, any
quoted string. Setting `type` yourself is what keeps an error whose message happens to be numeric
filed as an error:

```php
Logger::log('42', 'push', context: ['type' => 'error']);   // filed as error, not json
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

There is no minimum-level filter: every call is written. The level is stored on the entry
(`"level": "error"`), and the readers — `LogViewer::setLogLevel()`, `LogAnalytics::entries()`,
`LogManager::getFilteredLogEntries()` — filter on it when the log is read. To keep debug output
out of production, guard the call yourself (`if (DEVELOPMENT) { Logger::debug(…); }`).

## Log File Management

### Output Mode (file / stream / both)

By default the Logger writes to files under `LOG_PATH` — which the
[Log Viewer](#log-viewer-and-analytics) reads. Dockerised / 12-factor apps often
want logs on **STDERR** instead (so `docker logs` surfaces them). Rather than
force one, the output mode is selectable:

```php
use Pramnos\Logs\Logger;

Logger::setOutputMode(Logger::OUTPUT_FILE);   // default — files only (LogViewer works)
Logger::setOutputMode(Logger::OUTPUT_STREAM); // STDERR only (12-factor containers)
Logger::setOutputMode(Logger::OUTPUT_BOTH);   // files + STDERR (keep the viewer AND docker logs)
```

When not set explicitly, the mode is resolved from the `PRAMNOS_LOG_MODE`
environment variable, then a `LOG_MODE` constant, then defaults to `file`:

```bash
PRAMNOS_LOG_MODE=both   # e.g. in a container env — files for the viewer, STDERR for docker logs
```

The stream target is STDERR by default; `Logger::setStreamTarget($resource)`
overrides it (used in tests). All existing logging calls are unaffected — only
where the entry is written changes.

### File Rotation

**Rotation is automatic, and nothing has to be scheduled for it to happen.** Before a line
is appended, `Logger::log()` checks the file's size and rotates it if it is over the cap —
10 MiB by default, keeping 5 backups (`app.log.1` … `app.log.5`, oldest dropped).

```bash
PRAMNOS_LOG_MAX_SIZE=10485760   # bytes; 0 disables rotation entirely
PRAMNOS_LOG_MAX_BACKUPS=5
```

`LOG_MAX_SIZE` and `LOG_MAX_BACKUPS` constants work too, and
`Logger::setMaxSize()` / `Logger::setMaxBackups()` override both for the current process.
**Set the size to `0` if something else rotates these files** — an external `logrotate`
and this one both renaming the file the application is appending to is worse than neither.

Rotating on write rather than from a scheduled sweep is deliberate. A task in
`FrameworkSchedule` is the tidier design and only works where something actually runs
`schedule:run`; a framework default has to hold where nothing was set up. The cost in the
common case is one `stat` and a return.

The rotated file gets a first line saying what happened and which backup holds what came
before it.

For an explicit rotation — a maintenance script, or a cap that differs per file:

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

// Every *.log file, with size and modification time
// (a list of ['name', 'size', 'size_formatted', 'modified', 'modified_formatted'])
$files = LogManager::getLogFiles(false, true);

foreach ($files as $info) {
    echo "File: {$info['name']}, Size: {$info['size_formatted']}\n";
}

// Archive log files older than 30 days into archives/logs_<Y-m-d_His>.zip, then delete them
$result = LogManager::archiveOldLogs(30);
echo "Archived {$result['archived']} files\n";

// Empty one log file (Logger, not LogManager)
Logger::clearLog('debug');               // debug.log
Logger::clearLog('application', 'log');  // application.log

// Empty several, or every *.log file
LogManager::clearAllLogs(['debug.log', 'application.log']);
```

### Log File Statistics

```php
// Get detailed statistics for a log file
$stats = LogManager::getLogFileStats('application', 'log');

echo "File size: {$stats['size_formatted']}\n";
echo "Line count: {$stats['lines']}\n";   // exact up to 1,000 lines, extrapolated beyond
echo "JSON percentage: {$stats['json_percentage']}%\n";
echo "Modified: {$stats['modified_formatted']}\n";

// Level distribution
foreach ($stats['level_distribution'] as $level => $count) {
    echo "Level {$level}: {$count} entries\n";
}
```

## Stack traces are written without arguments

Every trace the framework records goes through `Pramnos\Logs\Trace::of($throwable)`. That
covers the log line for an exception, an API error, the JSON and HTML error pages in debug,
and the changelog's captured origin. It keeps the layout of `getTraceAsString()`, which is
file, line, `Class->method()`, but writes **no arguments**.

The reason is that a call's arguments are whatever passed through it. With PHP's default
`zend.exception_ignore_args = Off`, `getTraceAsString()` writes up to fifteen characters of
every string argument. Symfony Mailer authenticates with `executeCommand(base64(password))`,
so a rejected SMTP password reached the log, and a failed query can carry the values it was
binding. Use `Trace::of()` in your own code wherever a trace is written down:

```php
Logger::error($e->getMessage() . "\n" . \Pramnos\Logs\Trace::of($e));
```

## When the process dies: `fatal.log`

A fatal error stops PHP before any of your code can log it. PHP's own line, in its error log,
gives a file and a line, and for "Allowed memory size … exhausted" that is only wherever the
last allocation happened to fall. It does not say which command or request loaded the data.

So every application writes `fatal.log` beside its other logs when a fatal error ends the
process. `Pramnos\Logs\FatalErrorReporter` is registered by `Application`'s constructor,
once per process, for web requests, console commands, cron jobs and daemons alike. There is
nothing to configure. Each entry is a `critical` record with:

| Field | What it holds |
| --- | --- |
| `entry` | The command line (`cli: pramnos maintenance --zone=7`) or the request's method and path (`GET /reports/run`) |
| `where` | The file and line PHP stopped at |
| `request_id` | The request id, to find the same request in the other logs |
| `peak_memory_mb`, `memory_limit` | How much it used, against how much it was allowed |
| `elapsed_seconds` | How long it had been running |
| `pid` | The process |

A backtrace is not among them, because by the time a shutdown function runs the stack is gone.
The entry point is usually the answer anyway: knowing that a command ran for forty minutes and
used 3 GB tells you where to look.

**What it leaves out on purpose.** Option values whose name contains `pass`, `secret`, `token`
or `key` are written as `***`, and a URL's query string is dropped. Either can carry a
credential, and this file is read by whoever investigates.

**It still works when memory has run out.** It holds back 256 KB when it registers, releases it
at shutdown, and raises the limit a little before writing, so the report does not die the
same way the process did.

Only fatal error types are reported: `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`
and `E_USER_ERROR`. Warnings and exceptions have their own path.

## Log Viewer and Analytics

### The dashboard's own figures — `LogAnalytics`

Everything `/admin/logs` draws is one call, and it lives outside the controller so the same
numbers are reachable from code, a command or an MCP tool — not only by a human with a browser
and an administrator's session:

```php
use Pramnos\Logs\LogAnalytics;

$summary = LogAnalytics::summary('24h');          // or '1h', '6h', '7d', '30d'
$summary = LogAnalytics::summary('7d', ['error.log']);
```

```php
[
    'timespan'  => '24h',
    'from'      => 1756300000,
    'to'        => 1756386400,
    'group'     => 'hour',
    'trends'    => ['08:00' => 4, '09:00' => 0, /* … */],  // keyed by axis label
    'levels'    => ['info' => 21, 'error' => 3],
    'topErrors' => [
        ['message' => '…', 'count' => 2, 'file' => 'error.log', 'last_seen' => '…'],
    ],
    'files'     => [
        'error.log' => ['last_entry' => '…', 'error_rate' => 12.5, 'total_entries' => 24],
    ],
    'truncated' => false,
]
```

And the entries themselves, newest first across every file:

```php
// Defaults to emergency/alert/critical/error — what somebody means by "the error log"
$entries = LogAnalytics::entries();
$entries = LogAnalytics::entries(['warning'], ['error.log'], '1h', 20, 'timeout');
```

Five things worth knowing before you use either:

- **The date on a line is read day-first.** The framework's own `Logger` writes
  `28/08/2026 13:39:37`, and `strtotime()` reads a slash-separated date as American
  month-first — month 28 does not exist, so it returns `false`. `LogManager::parseTimestamp()`
  is the one parser both readers use: the framework's formats first, then ISO and everything
  else PHP knows. A line whose date cannot
  be read returns `null` and is in **no** time window, because an undatable entry counted
  inside whichever window was asked for is how a whole file ends up in the last hour's
  figures.

- **`topErrors` is keyed by the message**, so one failure appearing in three files is a single
  row carrying the total. That is the number somebody acts on; three rows each look survivable.
- **`truncated` is true** when a file was too large to scan in full and only its tail was read.
  A summary of the last 25 MB of a multi-gigabyte log otherwise reads as a complete picture.
- **An unknown timespan is `24h`**, and the returned `timespan` says so. The value usually
  arrives from a query string.
- **`GitDeploy` and `GitWebhookDebug` are skipped** — they are shell output, not structured
  entries, and counting levels in them produces a number that looks like a measurement.

The same service backs the `log-analytics` and `log-errors` MCP tools; see the
[MCP guide](Pramnos_MCP_Guide.md). One implementation on purpose — two copies of an aggregation
drift, and the day they disagree each caller looks right on its own.

### What a log viewer will open, and what it refuses

`setFile()` answers two separate questions, and both are refusals with the same exception:

1. **Is this a name?** A value containing a separator or `..` is refused **unconditionally** —
   before the whitelist, and even when a caller passes `$checkWhitelist = false`, because that
   argument asks to skip a *list* rather than to be handed a path. `getLogFilePath()` concatenates
   the name onto the log directory, so a path escapes it.
2. **Is it a log?** The configured whitelist when there is one, and otherwise **the log
   directory's own contents** plus the two names that map outside it (`GitDeploy`,
   `GitWebhookDebug`).

```php
new LogViewer(['pramnosframework.log'])->setFile('pramnosframework.log');   // fine
new LogViewer()->setFile('pramnosframework.log');                          // fine: it is there
new LogViewer()->setFile('../../../../etc/passwd');                        // InvalidArgumentException
```

**Names carry their extension.** `LogController`'s whitelist is
`['pramnosframework.log', 'php_error.log']`, and `getLogFilePath()` maps a name to the directory
unchanged — so `'pramnosframework'` is a different, non-existent file.

**A whitelisted log that has never been written is not an error.** `processFile()` throws for a
file that does not exist, which is the normal state of a log nothing has logged to yet, so a
reader should answer an empty page rather than propagate it — `ApiAdmin::logs()` does.

**An empty whitelist is not "everything is allowed".** With no list, the viewer falls back to the
log directory's own contents rather than skipping the check. That is what keeps a grant of *may
read the log file* from becoming a grant of *may read any file this process can* — a viewer
constructed with no arguments, as `ApiAdmin::logs()` does, still refuses
`?file=../../../../etc/passwd`.

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

## Console Commands

### Converting logs to the structured format: `logs:convert`

```bash
# Convert one file (a .bak copy is written beside it by default)
php vendor/bin/pramnos logs:convert /path/to/file.log

# Convert every .log file in a directory
php vendor/bin/pramnos logs:convert /path/to/logs --all

# Without the .bak copy
php vendor/bin/pramnos logs:convert /path/to/file.log --no-backup
```

`logs:convert` is the only log command. Statistics, rotation, archiving, clearing and export
are reached through `LogManager` / `Logger` from code (above) or through the web interface
(below). Rotation needs no command at all: it happens on write.

## Web Interface

### Accessing the Log Viewer

`LogController` serves the log screens inside the administration area —
`AdminArea::url('logs')`, which is `/admin/logs` with the default `admin.prefix`. Its actions:

```
/admin/logs                # display: the viewer for the selected file
/admin/logs/viewer         # the viewer itself
/admin/logs/raw            # raw file contents
/admin/logs/stats          # log file statistics
/admin/logs/dashboard      # analytics dashboard
/admin/logs/filter         # filtered entries
/admin/logs/search         # search across files
/admin/logs/export         # download (?file=…&format=csv|json|raw)
/admin/logs/rotate         # rotate log files
/admin/logs/archive        # archive old logs
/admin/logs/clear          # clear logs
```

### Under another address

The screens answer under the controller's own class name. The scaffolded
`class Logs extends LogController` answers at `/admin/Logs`. An application that
already has a `Logs` controller of its own mounts the viewer beside it under another name:

```php
// src/Admin/Controllers/Logfiles.php — every link, redirect and breadcrumb is /admin/Logfiles/…
class Logfiles extends \Pramnos\Application\Controllers\LogController
{
}
```

Set `protected ?string $logsRoute = 'Logs';` on a subclass that is served at an address
other than its class name. The views receive the address as `$this->logsRoute`.

Under any address but `Logs`, the bundled `logs` views are searched before the
application's, because the application's `src/Admin/Views/logs` then belongs to its own
`Logs` controller. Under `Logs`, a copy written with `project:publish-views` wins, as any
view does.

### Log Viewer Features

- **Search and Filter**: Case-insensitive text search, and `key:value` matches on a structured entry's fields
- **Level Filtering**: Filter by log levels
- **Pagination**: Navigate through large log files
- **Export Options**: Download as CSV, JSON, or raw format
- **Date Range Export**: Export logs within specific time ranges
- **Context Display**: Expandable JSON context for structured logs

### Dashboard Analytics

The dashboard provides:

- **Trend Charts**: entries per minute, hour or day over the chosen timespan
- **Level Distribution**: how many entries each level has
- **Error Tracking**: top errors with occurrence counts, merged across files
- **Per-file Status**: last entry, error rate and entry count for each log file

These are the figures `LogAnalytics::summary()` returns (see above).

## Errors in the browser

Errors the application's pages hit in the browser are kept one row per distinct error. A row
holds:

- a count;
- when the error was first and last seen;
- the latest page, browser and signed-in user;
- the stack.

The same message from the same script and line is the same error in any browser and on any page,
so an error every visitor hits is one row with a large count.

| What reports | How |
|---|---|
| Every server-rendered page | `pf-utils.js`, when the head names the address: `<meta name="pf-client-errors" content="<?php echo sURL; ?>ClientErrors">` (add `data-release="…"` to record the build) |
| A page's own `catch` | `window.pfReportError(error)` |
| The service worker | Its own errors, to `sitePath('ClientErrors')`, as kind `worker` |

The client sends uncaught errors and unhandled promise rejections with `sendBeacon()`. Each
distinct error goes once per page, and at most ten per page. A cross-origin `Script error.`
carries nothing and is skipped.

The address is `Pramnos\Application\Controllers\ClientErrorsController`, put on `/ClientErrors`
by a `src/Controllers/ClientErrors.php` that extends it. It is open to visitors who are not
signed in, because an error hit before signing in is worth knowing about. It is protected in
three ways instead:

- only the site's own origin (`Origin`, or else `Referer`);
- a 16 KB body;
- 30 reports a minute per address, where APCu is available.

It answers `204`. Bodies are JSON:

```json
{"kind": "error", "message": "x is undefined", "source": "/assets/js/app.js", "line": 12,
 "column": 3, "stack": "…", "page": "https://example.com/orders", "release": "v1.4.0"}
```

The older shape, `{"error": "…", "agent": "…"}`, is accepted too, as a form or as JSON.
`Logs\ClientErrors` stores it under `md5(error . agent)`, the hash applications computed before
this existed.

**Reading them.** The log screens have a **Browser Errors** page (`Logs/jserrors`), with delete
and clear. In code:

```php
$errors = new \Pramnos\Logs\ClientErrors();
$errors->list(page: 1);   // ['items' => newest first, 'total' => …]
$errors->delete($hash);
$errors->clear();
```

**The table** is `javascripterrors`, from a framework migration:

- **No such table:** the migration creates it.
- **The application already has one** (`idhash`, `firstseen`, `lastseen`, `seentimes`, `error`,
  `agent`): the migration adds only the new columns (`kind`, `message`, `source`, `line`, `col`,
  `stack`, `page`, `release`, `userid`). The rows stay, and an admin screen written against the
  old columns keeps working.

**In an existing project:**

1. Run `migrate`.
2. Add the wrapper `src/Controllers/ClientErrors.php`, which `init` writes for a new project.
3. Add the meta tag to the theme's `head.php`.
4. Run `project:resync --js --all` for the new `pf-utils.js`.
5. Copy the error listeners from the scaffolded `sw.js` into your own service worker, if you
   have one.

## How fast pages are for real visitors

Core Web Vitals as visitors' browsers measure them: TTFB, FCP, LCP, INP and CLS. They come from
real users, not from a lab run. `pf-vitals.js` measures a sampled share of page views with the
`web-vitals` library (its attribution build) and sends them with `sendBeacon` once the page is
hidden. The framework controller `ClientVitals` receives them at `/ClientVitals`, with no
controller of the application's own needed. It applies the same checks as `ClientErrors`:
same-origin POST, small body, a few per minute.

```php
// app/app.php — off until a sample is set
'vitals' => [
    'sample'    => 0.1,                                   // a tenth of page views
    'groups'    => ['jobs' => '#^/(el|en)/jobs#', 'home' => '#^/(el|en)?/?$#'],
    'keep_days' => 30,                                    // older rows are pruned
],
```

The scaffolded footers print `\Pramnos\Logs\ClientVitals::tag()`. It writes the
`<meta name="pf-vitals">`, the library and the script, and prints nothing while the sample is 0.
In an existing project:
1. Run `migrate` (`client_vitals`).
2. Run `libraries:sync web-vitals` and `project:resync --js --all`.
3. Print the tag before `</body>`.

Each metric is kept with what it depends on, so the numbers can be read apart:

| Column | What it is |
|---|---|
| `page_group` | The first `vitals.groups` pattern the path matched, or `other` |
| `device` | `mobile` or `desktop`, by the viewport |
| `navigation` | How the page was reached: `navigate`, `reload`, `back-forward-cache` (a bfcache restore), `prerender`, `restore` |
| `delivery` | The navigation's `deliveryType`: `cache`, `navigational-prefetch`, or empty for the network |
| `worker`, `worker_ms` | Whether a service worker controls the page, could, or cannot; and `fetchStart − workerStart`, its start time |
| `source` | The worker's own `Server-Timing: sw;desc=…`, where it sends one |
| `logged_in` | Decided on the server, from the session |

The dashboard's `vitals` card shows the 75th percentile per page group and device. That is the
figure Google's thresholds are about, and each value is coloured good, needs improvement or poor.
The card is not on a new dashboard. It is offered only where vitals are measured, to whoever may
open the logs. In code:

```php
(new \Pramnos\Logs\ClientVitals())->summary(days: 7);   // [group][device][metric] => p75, count, rating
```

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
// 1. Conditional debug logging — every call is written, so guard the expensive ones
if (DEVELOPMENT) {
    Logger::debug('Expensive debug info', [
        'data' => $expensiveToSerializeData
    ]);
}

// 2. Compute values before logging them. Context is passed to json_encode() as it is:
//    a closure is not called, and is written as {}.
Logger::info('Operation result', [
    'result' => $expensiveOperation->getResult(),
]);

// 3. An explicit rotation with a different cap (automatic rotation already runs on write)
Logger::truncateLogFile('application', 'log', 50 * 1024 * 1024); // 50MB max

// 4. Archive old logs regularly
LogManager::archiveOldLogs(30); // Archive logs older than 30 days
```

### Memory Management

```php
// LogViewer reads large files in 8 MB chunks on its own; bound what it returns per page
$viewer = new LogViewer();
$viewer->setFile('large-log.log')
       ->setParameters(true, 1, 100);   // newest first, page 1, 100 lines

// Process a file line by line without loading it
LogManager::processLogFileWithCallback('application', 'log', function ($line) {
    echo $line . "\n";
});
```

## Configuration

### Log Directory Structure

Logs are written to `LOG_PATH/logs/` (`Logger::logDirectory()`; the system temp directory when
`LOG_PATH` is not defined). Each `Logger` call names its file — `pramnosframework.log` by default:

```
LOG_PATH/logs/
├── pramnosframework.log   # Logger's default file
├── php_error.log          # PHP's error_log, set by Application
├── fatal.log              # FatalErrorReporter
├── <name>.log             # Logger::log($message, '<name>')
├── <name>.log.1 … .5      # rotated backups
└── archives/              # LogManager::archiveOldLogs()
    └── logs_2026-01-01_120000.zip
```

### Environment-Specific Configuration

There is no logging config file. Three settings exist, each read from an environment variable
first, then a constant, then a default:

| Environment variable | Constant | Default | What it sets |
|---|---|---|---|
| `PRAMNOS_LOG_MODE` | `LOG_MODE` | `file` | `file`, `stream` (STDERR) or `both` |
| `PRAMNOS_LOG_MAX_SIZE` | `LOG_MAX_SIZE` | `10485760` | Rotation size in bytes; `0` disables rotation |
| `PRAMNOS_LOG_MAX_BACKUPS` | `LOG_MAX_BACKUPS` | `5` | Rotated backups kept |

`Logger::setOutputMode()`, `setMaxSize()` and `setMaxBackups()` override them for the current
process. Levels are not filtered at write time; see
[Log Levels and PSR-3 Compliance](#log-levels-and-psr-3-compliance).

## Advanced Features

### Custom Log Handlers

`Logger`'s methods are static and typed, so an override must repeat the parent's signature
exactly — a missing parameter or return type is a fatal error when the class loads.

```php
use Pramnos\Logs\Logger;

class AlertingLogger extends Logger
{
    public static function critical(
        string $message,
        array $context = [],
        string $file = 'pramnosframework'
    ): void {
        parent::critical($message, $context, $file);

        // Hand the alert to whatever the application uses to notify people —
        // a queued job, a notification, a chat webhook.
        MyAlerts::send('Critical: ' . $message, $context);
    }
}

AlertingLogger::critical('Database connection lost');
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
// Encrypt sensitive log data. info()/error()/… call static::log(), so they reach the override.
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
    
    public static function log(
        string $message,
        string $file = 'pramnosframework',
        string $ext = 'log',
        bool $startoffile = false,
        array $context = []
    ): void {
        $context = self::encryptContext($context);
        parent::log($message, $file, $ext, $startoffile, $context);
    }
}
```

## The visit log — how much of what a visitor does gets written down

Separate from the log files above, the framework records one row per request in
`tokenactions`: the URL, the method, the parameters, the response status and how
long it took. It is an audit trail — who called what, when, and what came back.

For an application that needs one, that is exactly right. For one that does not,
it is a table growing by one row per request for ever, holding a copy of every
request body that was ever posted. A page that loads and then makes ten API
calls writes eleven rows.

### Choosing

```php
// app/settings/settings.php, or the settings table
'visit_log' => 'all',          // every request — the default
'visit_log' => 'navigations',  // only pages a visitor actually opened
'visit_log' => 'pages',        // every web request, including a page's own XHR
'visit_log' => 'api',          // only the API
'visit_log' => 'none',         // nothing
```

`true` and `false` work too, meaning `all` and `none`.

| Mode | A page load | The datatable call it makes | An API call |
|---|---|---|---|
| `all` | ✅ | ✅ | ✅ |
| `pages` | ✅ | ✅ | — |
| `navigations` | ✅ | — | — |
| `api` | — | — | ✅ |
| `none` | — | — | — |

### Which is which

`pages` and `api` split on **who handled the request** — the web front
controller or the API subsystem. That is a fact about the request rather than a
guess, and it does not change.

`navigations` is narrower than `pages`: it drops the calls a page makes after it
has rendered, detected from `Sec-Fetch-Dest` with `X-Requested-With` and
`Accept` as fallbacks. On a datatable-heavy admin panel that is most of the
traffic, and none of it is a visitor going anywhere.

### Two deliberate choices

**The default is `all`**, so an installation that never sets it keeps a complete
audit log. A default that quietly changed the contents of an audit log would be worse than no
setting.

**An unrecognised value means `all`, not `none`.** A typo in a settings table
must not switch off an audit log — that failure is discovered by somebody going
to look for the one request that is missing. The obvious spellings (`off`, `no`,
`web`, `page`, `navigation`, and any capitalisation) are accepted for the same
reason.

### What it does not change

Nothing here affects authentication, rate limiting, or the token's own
`lastused`. A request that is not written down is still authenticated, counted
and served identically; only the audit row is skipped.

---

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
    'total_log_size' => array_sum(array_column(LogManager::getLogFiles(false, true), 'size'))
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
