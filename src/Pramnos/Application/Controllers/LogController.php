<?php

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Http\Request;
use Pramnos\Framework\Factory;
use Pramnos\Logs\LogViewer;
use Pramnos\Logs\LogManager;
use Pramnos\Logs\Logger;

/**
 * Base Logs Controller class for the framework
 * Applications should extend this and only override the whitelists and other project-specific settings
 * 
 */
class LogController extends Controller
{
    /**
     * Terminate the execution of the request (useful for testing redirects and file downloads)
     */
    protected function terminate(): void
    {
        exit;
    }

    /**
     * Send header (wrapper for testing)
     */
    protected function sendHeader(string $header): void
    {
        if (php_sapi_name() !== 'cli') {
            header($header);
        }
    }

    /**
     * Clear output buffers
     */
    protected function clearOutputBuffers(): void
    {
        // Don't close buffers during PHPUnit tests as PHPUnit depends on ob
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            return;
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
    }

    /**
     * Whitelist of log files - by default is auto-populated from logs directory
     * Child classes can override this with a specific list if needed
     * @var array
     */
    protected $whitelist = [
        'pramnosframework.log',
        'php_error.log'
    ];
    
    /**
     * Blacklist of log files to exclude from auto-detection
     * Override in child class to exclude specific files
     * @var array
     */
    protected $blacklist = [];

    /**
     * List of log files to clear when calling the clear action - override in child class
     * @var array
     */
    protected $clearList = [
        'pramnosframework.log',
        'php_error.log',
        'php_dev_error.log'
    ];

    /**
     * Log viewer instance
     * @var LogViewer
     */
    protected $logViewer;

    /**
     * Constructor
     * @param \Pramnos\Application\Application|null $application
     */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Default auth actions — display is included because the viewer
        // renders an iframe pointing to `raw`, which is also auth-protected.
        $this->addAuthAction([
            'display',
            'clear',
            'raw',
            'stats',
            'archive',
            'search',
            'rotate',
            'export',
            'dashboard',
            'filter',
            'viewer'
        ]);

        parent::__construct($application);
        
        // Auto-populate the whitelist with any missing log files
        $this->autoPopulateWhitelist();
        
        // Initialize the LogViewer with the controller's whitelist
        $this->logViewer = new LogViewer($this->whitelist, $this);
    }

    /**
     * Auto-populate whitelist from log files in the logs directory
     * This adds any log files found in the directory that aren't already in the whitelist
     * and aren't in the blacklist
     */
    protected function autoPopulateWhitelist(): void
    {
        $logPath = LOG_PATH . DS . 'logs';
        
        if (!is_dir($logPath)) {
            // If log directory doesn't exist, ensure we at least have some defaults
            if (empty($this->whitelist)) {
                $this->whitelist = [
                    'pramnosframework.log',
                    'php_error.log',
                    'php_dev_error.log'
                ];
            }
            return;
        }
        
        // Get all .log files
        $files = glob($logPath . DS . '*.log');
        
        // Add special log files that don't have .log extension
        if (file_exists(ROOT . DS . 'www' . DS . 'api' . DS . 'GitDeploy')) {
            $files[] = ROOT . DS . 'www' . DS . 'api' . DS . 'GitDeploy';
        }
        if (file_exists(ROOT . DS . 'www' . DS . 'api' . DS . 'GitWebhookDebug')) {
            $files[] = ROOT . DS . 'www' . DS . 'api' . DS . 'GitWebhookDebug';
        }
        
        // Convert file paths to basenames
        $logFiles = [];
        foreach ($files as $file) {
            $logFiles[] = basename($file);
        }
        
        // Filter out blacklisted files
        $logFiles = array_diff($logFiles, $this->blacklist);
        
        // Merge existing whitelist with new files
        $this->whitelist = array_unique(array_merge($this->whitelist, $logFiles));
        
        // Sort whitelist alphabetically
        sort($this->whitelist);
    }

    /**
     * Default landing for /Logs — the analytics dashboard.
     *
     * The raw log viewer moved to viewer() (/Logs/viewer); the dashboard is now
     * the overview shown first, with the toolbar linking to everything else.
     *
     * @return string
     */
    public function display()
    {
        return $this->dashboard();
    }

    /**
     * The log viewer — file selector + live log iframe. Reachable at
     * /Logs/viewer (optionally /Logs/viewer/<file>).
     *
     * @return string
     */
    public function viewer()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs/viewer')
            );

            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }

            $doc->title = 'Log Files';
        }

        $file = Request::staticGetOption();
        if ($file == '' || !in_array($file, $this->whitelist)) {
            $file = 'php_error.log';
        }

        // Theme-aware rendering: gather data + hand off to the per-theme
        // `logs` view. The self-contained log-viewer iframe (produced by
        // LogViewer::renderViewer(), already CSP-safe) is passed through as
        // pre-rendered HTML and echoed verbatim by the view.
        $view = $this->getView('logs');
        $view->toolbar    = $this->getToolbarLinks();
        $view->clearList  = $this->clearList;
        $view->viewerHtml = $this->logViewer->renderViewer($file);

        return $view->display();
    }
    
    /**
     * Return the log-management toolbar links as theme-agnostic data.
     *
     * Each per-theme `logs` view renders these in its own idiom (Bootstrap
     * buttons, Tailwind utilities, plain-CSS buttons). The controller no
     * longer emits any HTML for the toolbar — that keeps the log viewer
     * consistent with the active UI theme (tailwind/bootstrap/plain-css).
     *
     * @return array<int, array{url:string,label:string,variant:string,icon:string,confirm?:string}>
     */
    protected function getToolbarLinks(): array
    {
        $base = adminUrl('Logs/');
        $root = adminUrl('Logs');

        return [
            ['url' => $root,            'label' => 'Dashboard',          'variant' => 'info',      'icon' => 'gauge'],
            ['url' => $base . 'viewer', 'label' => 'Log Files',          'variant' => 'secondary', 'icon' => 'file-lines'],
            ['url' => $base . 'stats',     'label' => 'Log Statistics',    'variant' => 'info',      'icon' => 'chart-bar'],
            ['url' => $base . 'search',    'label' => 'Search Across Logs', 'variant' => 'primary',   'icon' => 'search'],
            ['url' => $base . 'filter',    'label' => 'Filter Logs',        'variant' => 'primary',   'icon' => 'filter'],
            ['url' => $base . 'export',    'label' => 'Export Logs',        'variant' => 'secondary', 'icon' => 'download'],
            ['url' => $base . 'rotate',    'label' => 'Rotate Logs',        'variant' => 'warning',   'icon' => 'sync'],
            ['url' => $base . 'archive',   'label' => 'Archive Logs',       'variant' => 'secondary', 'icon' => 'archive'],
            [
                'url'     => $base . 'clear',
                'label'   => 'Clear Logs',
                'variant' => 'danger',
                'icon'    => 'trash',
                'confirm' => 'Are you sure you want to clear all logs in the clearList?',
            ],
        ];
    }

    /**
     * Clear log files specified in clearList
     * @return void
     */
    public function clear()
    {
        LogManager::clearAllLogs($this->clearList);
        $this->redirect(adminUrl('logs'));
    }

    /**
     * Raw display of log file content with search and pagination
     * @return string HTML content
     */
    public function raw()
    {
        Factory::getDocument('raw');
        
        $filename = Request::staticGet('file', '', 'get');
        if ($filename == '' || !in_array($filename, $this->whitelist)) {
            return $this->logViewer->renderError('Invalid or no log file specified');
        }

        $maxLines = Request::staticGet('maxLines', 20, 'get', 'int');  // Changed from 'post' to 'get'
        $reverse = (bool)Request::staticGet('reverse', 1, 'get', 'int');
        $page = max(1, Request::staticGet('page', 1, 'get', 'int'));
        $search = str_replace('{space}', ' ', trim(urldecode(Request::staticGet('search', '', 'get'))));
        $level = Request::staticGet('level', '', 'get');

        try {
            // Configure LogViewer with request parameters
            $this->logViewer->setFile($filename)
                           ->setParameters($reverse, $page, $maxLines, $search);
            
            // Set log level filter if specified
            if (!empty($level) && $level !== 'all') {
                $this->logViewer->setLogLevel($level);
            }
            
            // Process the log file
            $result = $this->logViewer->getLogContent();
            
            // Render HTML output
            return $this->logViewer->renderHtml($result);
        } catch (\Exception $e) {
            return $this->logViewer->renderError("Error reading log file: " . htmlspecialchars($e->getMessage()));
        }
    }

    /**
     * Show statistics for log files
     * @return string HTML content
     */
    public function stats()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs')
            );
            $this->application->addBreadcrumb(
                'Statistics',
                adminUrl('Logs/stats')
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Log Statistics';
        }

        // Get statistics for all whitelisted files
        $stats = [];
        foreach ($this->whitelist as $file) {
            // Handle special log files that don't have .log extension
            if (in_array($file, ['GitDeploy', 'GitWebhookDebug'])) {
                $filename = $file;
                $ext = '';
            } else {
                $pathInfo = pathinfo($file);
                $filename = $pathInfo['filename'];
                $ext = $pathInfo['extension'] ?? 'log';
            }
            
            $fileStats = LogManager::getLogFileStats($filename, $ext);
            if ($fileStats) {
                $stats[] = $fileStats;
            }
        }

        // Pre-compute summary aggregates so the view stays presentation-only.
        $totalSize  = array_sum(array_column($stats, 'size'));
        $totalLines = array_sum(array_column($stats, 'lines'));
        $totalFiles = count($stats);
        $jsonPercent = $totalFiles > 0
            ? round(array_sum(array_column($stats, 'json_percentage')) / $totalFiles, 1)
            : 0;

        $view = $this->getView('logs');
        $view->toolbar         = $this->getToolbarLinks();
        $view->clearList       = $this->clearList;
        $view->stats           = $stats;
        $view->totalSize       = $totalSize;
        $view->totalSizeHuman  = \Pramnos\General\Helpers::formatBytes($totalSize);
        $view->totalLines      = $totalLines;
        $view->totalFiles      = $totalFiles;
        $view->jsonPercent     = $jsonPercent;

        return $view->display('stats');
    }

    /**
     * Archive old log files
     * @return string HTML content
     */
    public function archive()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs')
            );
            $this->application->addBreadcrumb(
                'Archive',
                adminUrl('Logs/archive')
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Archive Log Files';
        }

        $days = (int)Request::staticGet('days', 30, 'post');
        $result = null;

        if (Request::staticGet('action', '', 'post') === 'archive') {
            $result = LogManager::archiveOldLogs($days);
        }

        $view = $this->getView('logs');
        $view->toolbar   = $this->getToolbarLinks();
        $view->clearList = $this->clearList;
        $view->days      = $days;
        $view->result    = $result;

        return $view->display('archive');
    }

    /**
     * Search across log files
     * @return string HTML content
     */
    public function search()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs')
            );
            $this->application->addBreadcrumb(
                'Search',
                adminUrl('Logs/search')
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Search Log Files';
        }

        $searchText = Request::staticGet('query', '', 'post');
        $caseSensitive = (bool)Request::staticGet('case_sensitive', 0, 'post');
        $contextLines = (int)Request::staticGet('context', 2, 'post');
        $results = null;

        if (!empty($searchText)) {
            $results = LogManager::searchInLogs($searchText, $this->whitelist, $contextLines, $caseSensitive);
        }

        $view = $this->getView('logs');
        $view->toolbar       = $this->getToolbarLinks();
        $view->clearList     = $this->clearList;
        $view->searchText    = $searchText;
        $view->caseSensitive = $caseSensitive;
        $view->contextLines  = $contextLines;
        $view->results       = $results;

        return $view->display('search');
    }

    /**
     * Rotate log files
     * @return string HTML content
     */
    public function rotate()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs')
            );
            $this->application->addBreadcrumb(
                'Rotate',
                adminUrl('Logs/rotate')
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Rotate Log Files';
        }

        $maxSize = (int)Request::staticGet('max_size', 10, 'post');
        $maxBackups = (int)Request::staticGet('max_backups', 5, 'post');
        $selectedFiles = Request::staticGet('files', [], 'post', 'array');
        $results = [];
        
        if (Request::staticGet('action', '', 'post') === 'rotate' && !empty($selectedFiles)) {
            foreach ($selectedFiles as $file) {
                if (in_array($file, $this->whitelist)) {
                    // Handle special log files
                    if (in_array($file, ['GitDeploy', 'GitWebhookDebug'])) {
                        $filename = $file;
                        $ext = '';
                    } else {
                        $pathInfo = pathinfo($file);
                        $filename = $pathInfo['filename'];
                        $ext = $pathInfo['extension'] ?? 'log';
                    }
                    
                    $rotated = Logger::truncateLogFile($filename, $ext, $maxSize * 1024 * 1024, true, $maxBackups);
                    $results[$file] = $rotated;
                }
            }
        }

        $stats = [];
        foreach ($this->whitelist as $file) {
            // Handle special log files
            if (in_array($file, ['GitDeploy', 'GitWebhookDebug'])) {
                $filename = $file;
                $ext = '';
            } else {
                $pathInfo = pathinfo($file);
                $filename = $pathInfo['filename'];
                $ext = $pathInfo['extension'] ?? 'log';
            }
            
            $fileStats = LogManager::getLogFileStats($filename, $ext);
            if ($fileStats) {
                $stats[] = $fileStats;
            }
        }

        $view = $this->getView('logs');
        $view->toolbar       = $this->getToolbarLinks();
        $view->clearList     = $this->clearList;
        $view->maxSize       = $maxSize;
        $view->maxBackups    = $maxBackups;
        $view->selectedFiles = $selectedFiles;
        $view->results       = $results;
        $view->stats         = $stats;

        return $view->display('rotate');
    }

    /**
     * Clear an individual log file
     *
     * The file comes from the URL segment. It used to be declared `string $file`
     * and taken as an argument, which `Controller::exec()` cannot supply — it
     * calls every action with the request's arguments **array**, so the
     * declaration made this a guaranteed `TypeError`. The link on the logs screen
     * fatalled on every click.
     *
     * @param mixed $file Unused; the file is read from the request
     * @return void
     */
    public function clearFile(mixed $file = null)
    {
        $file = (string) \Pramnos\Http\Request::staticGetOption();
        if ($file === '' || !in_array($file, $this->whitelist)) {
            $this->redirect(adminUrl('logs'));
            return;
        }

        // Extract filename and extension
        $pathInfo = pathinfo($file);
        $filename = $pathInfo['filename'];
        $ext = $pathInfo['extension'] ?? 'log';

        // Clear the log file
        Logger::clearLog($filename, $ext);
        
        // Redirect back to stats or logs
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, 'stats') !== false) {
            $this->redirect(adminUrl('logs/stats'));
        } else {
            $this->redirect(adminUrl('logs'));
        }
    }

    /**
     * Export log file to various formats (CSV, JSON, ZIP)
     * @return mixed Download response or HTML content
     */
    public function export()
    {
        // Handle GET request for direct downloads
        $format = strtolower(Request::staticGet('format', '', 'get'));
        $file = Request::staticGet('file', '', 'get');
        
        // If we have format and file in GET parameters, process the download directly
        if ($format && $file && in_array($file, $this->whitelist)) {
            switch ($format) {
                case 'csv':
                    return $this->exportCsv($file);
                case 'json':
                    return $this->exportJson($file);
                case 'raw':
                    return $this->exportRaw($file);
            }
        }
        
        // Handle date range exports from POST
        $startDate = Request::staticGet('start_date', '', 'post');
        $endDate = Request::staticGet('end_date', '', 'post');
        $format = strtolower(Request::staticGet('format', '', 'post'));
        $file = Request::staticGet('file', '', 'post');
        
        if ($startDate && $endDate && $format && $file && in_array($file, $this->whitelist)) {
            return $this->exportDateRange($file, $startDate, $endDate, $format);
        }
        
        // Handle multiple files export
        $multipleFiles = Request::staticGet('multiple_files', [], 'post', 'array');
        if (!empty($multipleFiles) && $format === 'zip') {
            return $this->exportZip($multipleFiles);
        }
        
        // If we're here, we need to show the export form
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs')
            );
            $this->application->addBreadcrumb(
                'Export',
                adminUrl('Logs/export')
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Export Log Files';
        }

        $view = $this->getView('logs');
        $view->toolbar   = $this->getToolbarLinks();
        $view->clearList = $this->clearList;
        $view->whitelist = $this->whitelist;
        $view->result    = null;

        return $view->display('export');
    }

    /**
     * Export log file between specified dates
     * @param string $filename The log file to export
     * @param string $startDate Start date in Y-m-d format
     * @param string $endDate End date in Y-m-d format
     * @param string $format Export format (csv or json)
     * @return void
     */
    protected function exportDateRange(string $filename, string $startDate, string $endDate, string $format = 'csv')
    {
        // Get path info
        if (in_array($filename, ['GitDeploy', 'GitWebhookDebug'])) {
            $name = $filename;
            $ext = '';
        } else {
            $pathInfo = pathinfo($filename);
            $name = $pathInfo['filename'];
            $ext = $pathInfo['extension'] ?? 'log';
        }
        
        // Convert dates to timestamps
        $startTimestamp = strtotime($startDate . ' 00:00:00');
        $endTimestamp = strtotime($endDate . ' 23:59:59');
        
        if (!$startTimestamp || !$endTimestamp) {
            Factory::getDocument();
            echo '<div class="alert alert-danger">Invalid date format.</div>';
            echo '<p><a href="' . (adminUrl('logs/export')) . '" class="btn btn-secondary">Go Back</a></p>';
            return;
        }
        
        // Set headers for download
        if ($format === 'csv') {
            $this->sendHeader('Content-Type: text/csv');
            $this->sendHeader('Content-Disposition: attachment; filename="' . $name . '_' . $startDate . '_to_' . $endDate . '.csv"');
            
            // Open output stream
            $output = fopen('php://output', 'w');
            
            // Write CSV header (explicit escape='' avoids PHP 8.4 deprecation)
            fputcsv($output, ['Timestamp', 'Level', 'Message', 'Context'], ',', '"', '');
            
            // Callback for processing each line
            $callback = function($line, $timestamp) use ($output, $startTimestamp, $endTimestamp) {
                if ($timestamp < $startTimestamp || $timestamp > $endTimestamp) {
                    return;
                }
                
                // Check if line is JSON formatted
                if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
                    try {
                        $data = json_decode($line, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $timestamp = $data['datetime'] ?? $data['timestamp'] ?? '';
                            $level = $data['level'] ?? '';
                            $message = $data['message'] ?? '';
                            $context = json_encode($data['context'] ?? []);
                            
                            fputcsv($output, [$timestamp, $level, $message, $context], ',', '"', '');
                            return;
                        }
                    } catch (\Exception $e) {
                        // Not valid JSON, continue with raw line
                    }
                }
                
                // Handle plain text log lines (explicit escape='' avoids PHP 8.4 deprecation)
                fputcsv($output, ['', '', $line, ''], ',', '"', '');
            };
            
        } else { // JSON format
            $this->sendHeader('Content-Type: application/json');
            $this->sendHeader('Content-Disposition: attachment; filename="' . $name . '_' . $startDate . '_to_' . $endDate . '.json"');
            
            $logs = [];
            
            // Callback for processing each line
            $callback = function($line, $timestamp) use (&$logs, $startTimestamp, $endTimestamp) {
                if ($timestamp < $startTimestamp || $timestamp > $endTimestamp) {
                    return;
                }
                
                // Check if line is JSON formatted
                if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
                    try {
                        $data = json_decode($line, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $logs[] = $data;
                            return;
                        }
                    } catch (\Exception $e) {
                        // Not valid JSON, continue with raw line
                    }
                }
                
                // Handle plain text log lines
                $logs[] = [
                    'timestamp' => date('Y-m-d H:i:s', $timestamp),
                    'level' => 'INFO',
                    'message' => $line,
                    'context' => []
                ];
            };
        }
        
        // Process the log file
        $this->processLogFileWithDateCheck($name, $ext, $callback);
        
        // Output JSON result if needed
        if ($format === 'json') {
            echo json_encode(['logs' => $logs], JSON_PRETTY_PRINT);
        }
        
        if ($format === 'csv') {
            fclose($output);
        }
        $this->terminate();
    }

    /**
     * Process a log file with timestamp checking
     * @param string $filename The log file name
     * @param string $ext The log file extension
     * @param callable $callback Callback function for each line
     * @return bool Success status
     */
    protected function processLogFileWithDateCheck(string $filename, string $ext, callable $callback): bool
    {
        $filepath = Logger::getLogPath($filename, $ext);
        
        if (!file_exists($filepath)) {
            return false;
        }
        
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return false;
        }
        
        while (($line = fgets($handle)) !== false) {
            $timestamp = time(); // Default to current time
            // fgets() keeps the trailing newline; trim it before inspecting the
            // line so JSON detection (which checks the last char is '}') and the
            // bracketed-timestamp regex are not defeated by the '\n'.
            $trimmed = trim($line);

            // Try to extract timestamp from JSON
            if (substr($trimmed, 0, 1) === '{' && substr($trimmed, -1) === '}') {
                $data = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $timestampStr = $data['timestamp'] ?? $data['datetime'] ?? '';
                    if ($timestampStr) {
                        // Try different date formats
                        $parsedTime = strtotime($timestampStr);
                        if ($parsedTime !== false) {
                            $timestamp = $parsedTime;
                        }
                    }
                }
            }
            // Try to extract timestamp from standard log format [date time],
            // accepting both slash (d/m/Y) and dash (Y-m-d / d-M-Y) date styles
            // that the framework's own loggers emit.
            elseif (preg_match('/^\[([\d\/\-A-Za-z]+ [\d:]+)/', $trimmed, $matches)) {
                $timestampStr = $matches[1];
                $parsedTime = strtotime($timestampStr);
                if ($parsedTime !== false) {
                    $timestamp = $parsedTime;
                }
            }

            // Call the callback with line and timestamp
            $callback($line, $timestamp);
        }
        
        fclose($handle);
        return true;
    }

    /**
     * Export log file as CSV
     * @param string $filename The log file to export
     * @return void
     */
    protected function exportCsv(string $filename)
    {
        // Get path info
        if (in_array($filename, ['GitDeploy', 'GitWebhookDebug'])) {
            $name = $filename;
            $ext = '';
        } else {
            $pathInfo = pathinfo($filename);
            $name = $pathInfo['filename'];
            $ext = $pathInfo['extension'] ?? 'log';
        }
        
        // Set headers for download
        $this->sendHeader('Content-Type: text/csv');
        $this->sendHeader('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
        $this->sendHeader('Pragma: no-cache');
        $this->sendHeader('Expires: 0');
        
        // Force flush all output buffers
        $this->clearOutputBuffers();
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Write CSV header
        fputcsv($output, ['Timestamp', 'Level', 'Message', 'Context'], ',', '"', '\\');
        
        // Read log file and write CSV rows
        $filepath = Logger::getLogPath($name, $ext);
        if (file_exists($filepath)) {
            $handle = fopen($filepath, 'r');
            if ($handle) {
                // Use a counter to prevent endless loops and enforce a reasonable limit
                $lineCount = 0;
                $maxLines = 50000; // Reasonable maximum for export
                
                while (($line = fgets($handle)) !== false && $lineCount < $maxLines) {
                    $lineCount++;
                    $line = trim($line);
                    
                    // Check if line is JSON formatted
                    if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
                        try {
                            $data = json_decode($line, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $timestamp = $data['datetime'] ?? $data['timestamp'] ?? '';
                                $level = $data['level'] ?? '';
                                $message = $data['message'] ?? '';
                                $context = json_encode($data['context'] ?? []);
                                
                                fputcsv($output, [$timestamp, $level, $message, $context], ',', '"', '\\');
                                continue;
                            }
                        } catch (\Exception $e) {
                            // Not valid JSON, continue with raw line
                        }
                    }
                    
                    // Try to extract timestamp from standard log format [date/time]
                    if (preg_match('/^\[([\d\/]+ [\d:]+)\](.*)$/', $line, $matches)) {
                        fputcsv($output, [$matches[1], '', $matches[2], ''], ',', '"', '\\');
                    } else {
                        // Handle plain text log lines
                        fputcsv($output, ['', '', $line, ''], ',', '"', '\\');
                    }
                }
                fclose($handle);
            }
        }
        
        fclose($output);
        $this->terminate();
    }

    /**
     * Export log file as JSON
     * @param string $filename The log file to export
     * @return void
     */
    protected function exportJson(string $filename)
    {
        // Get path info
        if (in_array($filename, ['GitDeploy', 'GitWebhookDebug'])) {
            $name = $filename;
            $ext = '';
        } else {
            $pathInfo = pathinfo($filename);
            $name = $pathInfo['filename'];
            $ext = $pathInfo['extension'] ?? 'log';
        }
        
        // Set headers for download
        $this->sendHeader('Content-Type: application/json');
        $this->sendHeader('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.json"');
        $this->sendHeader('Pragma: no-cache');
        $this->sendHeader('Expires: 0');
        
        // Force flush all output buffers
        $this->clearOutputBuffers();
        
        $logs = [];
        
        // Read log file and build JSON structure
        $filepath = Logger::getLogPath($name, $ext);
        if (file_exists($filepath)) {
            $handle = fopen($filepath, 'r');
            if ($handle) {
                // Use a counter to prevent endless loops and enforce a reasonable limit
                $lineCount = 0;
                $maxLines = 50000; // Reasonable maximum for export
                
                while (($line = fgets($handle)) !== false && $lineCount < $maxLines) {
                    $lineCount++;
                    $line = trim($line);
                    
                    // Check if line is JSON formatted
                    if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
                        try {
                            $data = json_decode($line, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $logs[] = $data;
                                continue;
                            }
                        } catch (\Exception $e) {
                            // Not valid JSON, continue with raw line
                        }
                    }
                    
                    // Try to extract timestamp from standard log format [date/time]
                    if (preg_match('/^\[([\d\/]+ [\d:]+)\](.*)$/', $line, $matches)) {
                        $logs[] = [
                            'timestamp' => $matches[1],
                            'message' => $matches[2],
                            'level' => 'INFO'
                        ];
                    } else {
                        // Handle plain text log lines
                        $logs[] = [
                            'timestamp' => date('Y-m-d H:i:s'),
                            'level' => 'INFO',
                            'message' => $line
                        ];
                    }
                }
                fclose($handle);
            }
        }
        
        echo json_encode(['logs' => $logs], JSON_PRETTY_PRINT);
        $this->terminate();
    }

    /**
     * Export multiple log files as ZIP archive
     * @param array $filenames The log files to export
     * @return void
     */
    protected function exportZip(array $filenames)
    {
        // Check if empty selection
        if (empty($filenames) || (count($filenames) === 1 && empty($filenames[0]))) {
            // Get from form array
            $filenames = Request::staticGet('multiple_files', [], 'post', 'array');
            if (empty($filenames)) {
                Factory::getDocument();
                echo '<div class="alert alert-danger">No log files selected for export.</div>';
                echo '<p><a href="' . (adminUrl('logs/export')) . '" class="btn btn-secondary">Go Back</a></p>';
                return;
            }
        }
        
        // Validate files
        $validFiles = [];
        foreach ($filenames as $file) {
            if (in_array($file, $this->whitelist)) {
                $validFiles[] = $file;
            }
        }
        
        if (empty($validFiles)) {
            Factory::getDocument();
            echo '<div class="alert alert-danger">No valid log files selected for export.</div>';
            echo '<p><a href="' . (adminUrl('logs/export')) . '" class="btn btn-secondary">Go Back</a></p>';
            return;
        }
        
        // Create temporary file path (delete it first so ZipArchive creates fresh)
        $zipFile = tempnam(sys_get_temp_dir(), 'log_export_');
        @unlink($zipFile); // Remove empty file; ZipArchive::CREATE needs non-existent path
        $zip = new \ZipArchive();

        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            Factory::getDocument();
            echo '<div class="alert alert-danger">Failed to create ZIP archive.</div>';
            echo '<p><a href="' . (adminUrl('logs/export')) . '" class="btn btn-secondary">Go Back</a></p>';
            return;
        }
        
        // Add files to ZIP
        foreach ($validFiles as $file) {
            if (in_array($file, ['GitDeploy', 'GitWebhookDebug'])) {
                $name = $file;
                $ext = '';
            } else {
                $pathInfo = pathinfo($file);
                $name = $pathInfo['filename'];
                $ext = $pathInfo['extension'] ?? 'log';
            }
            
            $logPath = LogManager::getLogFilePath($name, $ext);
            if (file_exists($logPath)) {
                $zip->addFile($logPath, $file);
            }
        }
        
        $zip->close();
        
        // Set headers for download
        $this->sendHeader('Content-Type: application/zip');
        $this->sendHeader('Content-Disposition: attachment; filename="logs_export_' . date('Y-m-d') . '.zip"');
        $this->sendHeader('Content-Length: ' . filesize($zipFile));
        $this->sendHeader('Pragma: no-cache');
        $this->sendHeader('Expires: 0');
        
        readfile($zipFile);
        unlink($zipFile);
        $this->terminate();
    }

    /**
     * Dashboard with log analytics
     * @return string HTML content
     */
    public function dashboard()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs')
            );
            $this->application->addBreadcrumb(
                'Dashboard',
                adminUrl('Logs/dashboard')
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Logs Dashboard';
        }

        // Get analytics data
        $timespan = Request::staticGet('timespan', '24h', 'get');
        
        // Determine time range based on selected timespan
        $endTime = time();
        switch ($timespan) {
            case '1h':
                $startTime = $endTime - 3600;
                $dateFormat = 'H:i';
                $groupBy = 'minute';
                break;
            case '6h':
                $startTime = $endTime - 21600;
                $dateFormat = 'H:i';
                $groupBy = 'minute';
                break;
            case '7d':
                $startTime = $endTime - 604800;
                $dateFormat = 'M d';
                $groupBy = 'day';
                break;
            case '30d':
                $startTime = $endTime - 2592000;
                $dateFormat = 'M d';
                $groupBy = 'day';
                break;
            case '24h':
            default:
                $startTime = $endTime - 86400;
                $dateFormat = 'H:i';
                $groupBy = 'hour';
                break;
        }

        // Initialize analytics data
        $logTrends = [];
        $logLevels = [];
        $topErrors = [];
        $systemStatus = [];
        $truncated = false; // set when any file was too large to scan in full

        // Collect analytics for each log file
        foreach ($this->whitelist as $file) {
            // Skip certain log files that might not have structured data
            if (in_array($file, ['GitDeploy', 'GitWebhookDebug'])) {
                continue;
            }
            
            // Get path info
            $pathInfo = pathinfo($file);
            $name = $pathInfo['filename'];
            $ext = $pathInfo['extension'] ?? 'log';
            
            // Get log analytics
            $analytics = LogManager::getLogAnalytics($name, $ext, $startTime, $endTime, $groupBy);
            
            if (!empty($analytics)) {
                if (!empty($analytics['truncated'])) {
                    $truncated = true;
                }
                // Store trends data
                foreach ($analytics['trends'] as $time => $count) {
                    if (!isset($logTrends[$time])) {
                        $logTrends[$time] = 0;
                    }
                    $logTrends[$time] += $count;
                }
                
                // Store log levels data
                foreach ($analytics['levels'] as $level => $count) {
                    if (!isset($logLevels[$level])) {
                        $logLevels[$level] = 0;
                    }
                    $logLevels[$level] += $count;
                }
                
                // Store top errors
                if (!empty($analytics['topErrors'])) {
                    foreach ($analytics['topErrors'] as $error) {
                        $key = md5($error['message']);
                        if (!isset($topErrors[$key])) {
                            $topErrors[$key] = [
                                'message' => $error['message'],
                                'count' => 0,
                                'file' => $file,
                                'last_seen' => $error['timestamp'] ?? ''
                            ];
                        }
                        $topErrors[$key]['count'] += $error['count'];
                    }
                }
                
                // Store system status
                $systemStatus[$file] = [
                    'last_entry' => $analytics['lastEntry'] ?? null,
                    'error_rate' => $analytics['errorRate'] ?? 0,
                    'success_rate' => 100 - ($analytics['errorRate'] ?? 0),
                    'total_entries' => $analytics['totalEntries'] ?? 0
                ];
            }
        }
        
        // Sort top errors by count (descending)
        uasort($topErrors, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        // Limit to top 10 errors
        $topErrors = array_slice($topErrors, 0, 10);
        
        // Format trend data for charts
        $trendLabels = [];
        $trendValues = [];
        ksort($logTrends);
        foreach ($logTrends as $time => $count) {
            $trendLabels[] = date($dateFormat, $time);
            $trendValues[] = $count;
        }
        
        // Prepare level data for pie chart
        $levelLabels = array_keys($logLevels);
        $levelValues = array_values($logLevels);
        $levelColors = [
            'emergency' => '#d92550',
            'alert' => '#fd397a',
            'critical' => '#fd397a',
            'error' => '#fd397a',
            'warning' => '#ffb822',
            'notice' => '#5d78ff',
            'info' => '#5d78ff',
            'debug' => '#74788d'
        ];
        $chartColors = [];
        foreach ($levelLabels as $level) {
            $level = strtolower($level);
            $chartColors[] = $levelColors[$level] ?? '#74788d';
        }

        // Enqueue the locally-bundled Chart.js (registered by the scaffolded
        // app's registerVendorLibraries()). Chart.js is a mandatory library, so
        // the handle is present; enqueuing by handle keeps it CSP-safe (served
        // from assets/vendor/, never an inline CDN <script>).
        $doc = Factory::getDocument();
        if (method_exists($doc, 'isScriptRegistered') && $doc->isScriptRegistered('chartjs')) {
            $doc->enqueueScript('chartjs');
        }

        $view = $this->getView('logs');
        $view->toolbar      = $this->getToolbarLinks();
        $view->clearList    = $this->clearList;
        $view->timespan     = $timespan;
        $view->trendLabels  = $trendLabels;
        $view->trendValues  = $trendValues;
        $view->levelLabels  = array_map('ucfirst', $levelLabels);
        $view->levelValues  = $levelValues;
        $view->chartColors  = $chartColors;
        $view->topErrors    = $topErrors;
        $view->systemStatus = $systemStatus;
        $view->truncated    = $truncated;

        return $view->display('dashboard');
    }

    /**
     * Filter logs by level, date range, and custom filters
     * @return string HTML content
     */
    public function filter()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                adminUrl('Logs')
            );
            $this->application->addBreadcrumb(
                'Filter',
                adminUrl('Logs/filter')
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Filter Log Files';
        }

        // Get filter parameters
        $file = Request::staticGet('file', '', 'post');
        $startDate = Request::staticGet('start_date', '', 'post');
        $endDate = Request::staticGet('end_date', '', 'post');
        $levels = Request::staticGet('levels', [], 'post', 'array');
        $query = Request::staticGet('query', '', 'post');
        $limit = Request::staticGet('limit', 100, 'post', 'int');
        
        $results = [];
        $hasResults = false;
        
        // Process filter request
        if ($file && in_array($file, $this->whitelist)) {
            // Get path info
            if (in_array($file, ['GitDeploy', 'GitWebhookDebug'])) {
                $name = $file;
                $ext = '';
            } else {
                $pathInfo = pathinfo($file);
                $name = $pathInfo['filename'];
                $ext = $pathInfo['extension'] ?? 'log';
            }
            
            // Convert dates to timestamps
            $startTimestamp = !empty($startDate) ? strtotime($startDate . ' 00:00:00') : null;
            $endTimestamp = !empty($endDate) ? strtotime($endDate . ' 23:59:59') : null;
            
            // Process log file with filters
            $results = LogManager::getFilteredLogEntries($name, $ext, $levels, $startTimestamp, $endTimestamp, $query, $limit);
            $hasResults = true;
        }

        // Get available log levels
        $availableLevels = [
            'emergency' => 'Emergency',
            'alert' => 'Alert',
            'critical' => 'Critical',
            'error' => 'Error',
            'warning' => 'Warning',
            'notice' => 'Notice',
            'info' => 'Info',
            'debug' => 'Debug'
        ];

        $view = $this->getView('logs');
        $view->toolbar         = $this->getToolbarLinks();
        $view->clearList       = $this->clearList;
        $view->whitelist       = $this->whitelist;
        $view->availableLevels = $availableLevels;
        $view->file            = $file;
        $view->startDate       = $startDate;
        $view->endDate         = $endDate;
        $view->levels          = $levels;
        $view->query           = $query;
        $view->limit           = $limit;
        $view->results         = $results;
        $view->hasResults      = $hasResults;

        return $view->display('filter');
    }

    /**
     * Export log file in raw format (as-is)
     * @param string $filename The log file to export
     * @return void
     */
    protected function exportRaw(string $filename)
    {
        // Get path info
        if (in_array($filename, ['GitDeploy', 'GitWebhookDebug'])) {
            $name = $filename;
            $ext = '';
        } else {
            $pathInfo = pathinfo($filename);
            $name = $pathInfo['filename'];
            $ext = $pathInfo['extension'] ?? 'log';
        }
        
        // Build correct filepath
        $filepath = Logger::getLogPath($name, $ext);
        
        if (!file_exists($filepath)) {
            $this->sendHeader('Content-Type: text/plain');
            echo "Error: Log file not found.";
            $this->terminate();
            return;
        }
        
        // Force disable any output buffering
        $this->clearOutputBuffers();
        
        // Set headers for download
        $this->sendHeader('Content-Description: File Transfer');
        $this->sendHeader('Content-Type: text/plain');
        $this->sendHeader('Content-Disposition: attachment; filename="' . $filename . '"');
        $this->sendHeader('Content-Length: ' . filesize($filepath));
        $this->sendHeader('Content-Transfer-Encoding: binary');
        $this->sendHeader('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        $this->sendHeader('Expires: 0');
        $this->sendHeader('Pragma: public');
        
        // Read file in chunks to handle large files
        $handle = fopen($filepath, 'rb');
        $chunkSize = 1024 * 1024; // 1MB chunks
        
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, $chunkSize);
                flush();
            }
            fclose($handle);
        }
        
        $this->terminate();
    }
    
}