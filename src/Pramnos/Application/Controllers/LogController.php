<?php

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Http\Request;
use Pramnos\Framework\Factory;
use Pramnos\Logs\LogViewer;
use Pramnos\Logs\LogManager;
use Pramnos\Logs\Logger;
use Urbanwater\Utils\FileUtils;

/**
 * Base Logs Controller class for the framework
 * Applications should extend this and only override the whitelists and other project-specific settings
 * 
 * @package     PramnosFramework
 * @subpackage  Application
 */
class LogController extends Controller
{
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
        // Default auth actions
        $this->addAuthAction([
            'clear',
            'raw',
            'stats',
            'archive',
            'search',
            'rotate',
            'export'
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
     * Display a listing of the log files
     * @return string
     */
    public function display()
    {
        if ($this->application) {
            $this->application->addBreadcrumb(
                'Log Files',
                defined('sURL') ? sURL . 'Logs' : ''
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
        
        
        
        // Add additional action buttons for the enhanced features
        $actionButtons = $this->renderActionButtons();
        
        // Use the integrated view renderer with the action buttons
        return $actionButtons . $this->logViewer->renderViewer($file);
    }
    
    /**
     * Render action buttons for the enhanced log management features
     * @return string HTML content
     */
    protected function renderActionButtons()
    {
        ob_start();
        ?>
        <div class="kt-portlet">
            <div class="kt-portlet__body">
                <div class="row">
                    <div class="col-md-12">
                        <div class="btn-group mb-3">
                            <a href="<?php echo defined('sURL') ? sURL . 'Logs/stats' : '#'; ?>" class="btn btn-info">
                                <i class="fa fa-chart-bar"></i> Log Statistics
                            </a>
                            <a href="<?php echo defined('sURL') ? sURL . 'Logs/search' : '#'; ?>" class="btn btn-primary">
                                <i class="fa fa-search"></i> Search Across Logs
                            </a>
                            <a href="<?php echo defined('sURL') ? sURL . 'Logs/rotate' : '#'; ?>" class="btn btn-warning">
                                <i class="fa fa-sync"></i> Rotate Logs
                            </a>
                            <a href="<?php echo defined('sURL') ? sURL . 'Logs/archive' : '#'; ?>" class="btn btn-secondary">
                                <i class="fa fa-archive"></i> Archive Logs
                            </a>
                            <a href="<?php echo defined('sURL') ? sURL . 'Logs/clear' : '#'; ?>" class="btn btn-danger" 
                               onclick="return confirm('Are you sure you want to clear all logs in the clearList?');">
                                <i class="fa fa-trash"></i> Clear Logs
                            </a>
                        </div>
                    </div>
                </div>
                <?php if (!empty($this->clearList)): ?>
                <div class="row">
                    <div class="col-md-12">
                        <small class="text-muted">
                            <i class="fa fa-info-circle"></i> "Clear Logs" will clear: <?php echo implode(', ', $this->clearList); ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Clear log files specified in clearList
     * @return void
     */
    public function clear()
    {
        LogManager::clearAllLogs($this->clearList);
        $this->redirect(defined('sURL') ? sURL . 'logs' : '');
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
                defined('sURL') ? sURL . 'Logs' : ''
            );
            $this->application->addBreadcrumb(
                'Statistics',
                defined('sURL') ? sURL . 'Logs/stats' : ''
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

        // Add navigation buttons
        $actionButtons = $this->renderActionButtons();

        // Render stats view
        ob_start();
        ?>
        <?php echo $actionButtons; ?>
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid">
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">Log File Statistics</h3>
                    </div>
                </div>
                <div class="kt-portlet__body">
                    <?php if (empty($stats)): ?>
                        <div class="alert alert-info">No log files found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Size</th>
                                        <th>Lines</th>
                                        <th>Structured JSON</th>
                                        <th>Last Modified</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats as $stat): ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo defined('sURL') ? sURL . 'Logs/display/' . $stat['name'] : '#'; ?>">
                                                    <?php echo htmlspecialchars($stat['name']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($stat['size_formatted']); ?></td>
                                            <td><?php echo number_format($stat['lines']); ?></td>
                                            <td>
                                                <?php echo $stat['json_percentage']; ?>%
                                                <?php if (!empty($stat['level_distribution'])): ?>
                                                    <div class="small text-muted">
                                                        <?php 
                                                        $levels = [];
                                                        foreach ($stat['level_distribution'] as $level => $count) {
                                                            $levels[] = ucfirst($level) . ': ' . $count;
                                                        }
                                                        echo implode(', ', $levels);
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($stat['modified_formatted']); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/display/' . $stat['name'] : '#'; ?>" 
                                                    class="btn btn-info" title="View">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/clearFile/' . $stat['name'] : '#'; ?>" 
                                                    class="btn btn-danger" title="Clear"
                                                    onclick="return confirm('Are you sure you want to clear this log?');">
                                                        <i class="fa fa-trash"></i>
                                                    </a>
                                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/raw?file=' . $stat['name'] : '#'; ?>" 
                                                    class="btn btn-secondary" title="Raw View" target="_blank">
                                                        <i class="fa fa-file-code"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Stats Summary -->
                        <div class="row mt-4">
                            <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12">
                                <div class="kt-widget24">
                                    <div class="kt-widget24__details">
                                        <div class="kt-widget24__info">
                                            <h4 class="kt-widget24__title">Total Size</h4>
                                        </div>
                                        <span class="kt-widget24__stats">
                                            <?php 
                                            $totalSize = array_sum(array_column($stats, 'size'));
                                            echo \Pramnos\General\Helpers::formatBytes($totalSize);
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12">
                                <div class="kt-widget24">
                                    <div class="kt-widget24__details">
                                        <div class="kt-widget24__info">
                                            <h4 class="kt-widget24__title">Total Lines</h4>
                                        </div>
                                        <span class="kt-widget24__stats">
                                            <?php 
                                            $totalLines = array_sum(array_column($stats, 'lines'));
                                            echo number_format($totalLines);
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-lg=6 col-md-6 col-sm-12">
                                <div class="kt-widget24">
                                    <div class="kt-widget24__details">
                                        <div class="kt-widget24__info">
                                            <h4 class="kt-widget24__title">JSON Entries</h4>
                                        </div>
                                        <span class="kt-widget24__stats">
                                            <?php 
                                            $jsonPercent = 0;
                                            $totalFiles = count($stats);
                                            if ($totalFiles > 0) {
                                                $jsonPercent = array_sum(array_column($stats, 'json_percentage')) / $totalFiles;
                                            }
                                            echo round($jsonPercent, 1) . '%';
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-lg-6 col-md-6 col-sm-12">
                                <div class="kt-widget24">
                                    <div class="kt-widget24__details">
                                        <div class="kt-widget24__info">
                                            <h4 class="kt-widget24__title">Log Files</h4>
                                        </div>
                                        <span class="kt-widget24__stats">
                                            <?php echo count($stats); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
                defined('sURL') ? sURL . 'Logs' : ''
            );
            $this->application->addBreadcrumb(
                'Archive',
                defined('sURL') ? sURL . 'Logs/archive' : ''
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

        // Add navigation buttons
        $actionButtons = $this->renderActionButtons();

        // Render archive view
        ob_start();
        ?>
        <?php echo $actionButtons; ?>
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid">
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">Archive Log Files</h3>
                    </div>
                </div>
                <div class="kt-portlet__body">
                    <?php if ($result): ?>
                        <?php if ($result['archived'] > 0): ?>
                            <div class="alert alert-success">
                                Successfully archived <?php echo $result['archived']; ?> log files to <?php echo htmlspecialchars($result['archive_file']); ?>
                            </div>
                        <?php elseif (!empty($result['errors'])): ?>
                            <div class="alert alert-danger">
                                <strong>Error:</strong> <?php echo implode(', ', $result['errors']); ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                No log files older than <?php echo $days; ?> days were found.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <form method="post">
                        <div class="form-group">
                            <label for="days">Archive logs older than (days):</label>
                            <input type="number" id="days" name="days" class="form-control" value="<?php echo $days; ?>" min="1" max="365">
                        </div>
                        <input type="hidden" name="action" value="archive">
                        <button type="submit" class="btn btn-primary">Archive Log Files</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
                defined('sURL') ? sURL . 'Logs' : ''
            );
            $this->application->addBreadcrumb(
                'Search',
                defined('sURL') ? sURL . 'Logs/search' : ''
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

        // Add navigation buttons
        $actionButtons = $this->renderActionButtons();

        // Render search view
        ob_start();
        ?>
        <?php echo $actionButtons; ?>
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid">
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">Search Log Files</h3>
                    </div>
                </div>
                <div class="kt-portlet__body">
                    <form method="post">
                        <div class="form-group">
                            <label for="query">Search Text:</label>
                            <input type="text" id="query" name="query" class="form-control" value="<?php echo htmlspecialchars($searchText); ?>" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="context">Context Lines:</label>
                                <input type="number" id="context" name="context" class="form-control" value="<?php echo $contextLines; ?>" min="0" max="10">
                            </div>
                            <div class="form-group col-md-6">
                                <div class="kt-checkbox-inline" style="margin-top: 30px;">
                                    <label class="kt-checkbox">
                                        <input type="checkbox" name="case_sensitive" value="1" <?php echo $caseSensitive ? 'checked' : ''; ?>>
                                        Case Sensitive
                                        <span></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>

                    <?php if (isset($results)): ?>
                        <hr>
                        <h4>Search Results for "<?php echo htmlspecialchars($searchText); ?>"</h4>
                        
                        <?php if (empty($results)): ?>
                            <div class="alert alert-info">No results found.</div>
                        <?php else: ?>
                            <div class="accordion" id="searchResults">
                                <?php foreach ($results as $index => $fileResult): ?>
                                    <div class="card">
                                        <div class="card-header" id="heading<?php echo $index; ?>">
                                            <h5 class="mb-0">
                                                <button class="btn btn-link" type="button" data-toggle="collapse" 
                                                        data-target="#collapse<?php echo $index; ?>" aria-expanded="true" 
                                                        aria-controls="collapse<?php echo $index; ?>">
                                                    <?php echo htmlspecialchars($fileResult['file']); ?> 
                                                    <span class="badge badge-info"><?php echo $fileResult['count']; ?> matches</span>
                                                </button>
                                            </h5>
                                        </div>

                                        <div id="collapse<?php echo $index; ?>" class="collapse" 
                                            aria-labelledby="heading<?php echo $index; ?>" data-parent="#searchResults">
                                            <div class="card-body">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered">
                                                        <thead>
                                                            <tr>
                                                                <th width="80">Line</th>
                                                                <th>Content</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($fileResult['matches'] as $match): ?>
                                                                <?php foreach ($match['context'] as $lineNum => $lineData): ?>
                                                                    <tr class="<?php echo $lineData['match'] ? 'table-warning' : ''; ?>">
                                                                        <td class="text-right"><?php echo $lineNum; ?></td>
                                                                        <td>
                                                                            <?php if ($lineData['match']): ?>
                                                                                <?php 
                                                                                $highlightedText = preg_replace(
                                                                                    '/(' . preg_quote($searchText, '/') . ')/i',
                                                                                    '<mark>$1</mark>',
                                                                                    htmlspecialchars($lineData['text'])
                                                                                );
                                                                                echo $highlightedText;
                                                                                ?>
                                                                            <?php else: ?>
                                                                                <?php echo htmlspecialchars($lineData['text']); ?>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                                <tr>
                                                                    <td colspan="2" class="bg-light"></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
                defined('sURL') ? sURL . 'Logs' : ''
            );
            $this->application->addBreadcrumb(
                'Rotate',
                defined('sURL') ? sURL . 'Logs/rotate' : ''
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

        // Add navigation buttons
        $actionButtons = $this->renderActionButtons();

        // Render rotate view
        ob_start();
        ?>
        <?php echo $actionButtons; ?>
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid">
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">Rotate Log Files</h3>
                    </div>
                </div>
                <div class="kt-portlet__body">
                    <?php if (!empty($results)): ?>
                        <div class="alert alert-info">
                            <h5>Rotation Results:</h5>
                            <ul>
                                <?php foreach ($results as $file => $rotated): ?>
                                    <li>
                                        <?php echo htmlspecialchars($file); ?>: 
                                        <?php if ($rotated): ?>
                                            <span class="text-success">Rotated successfully</span>
                                        <?php else: ?>
                                            <span class="text-muted">No rotation needed (file size below threshold)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="max_size">Maximum Size (MB):</label>
                                <input type="number" id="max_size" name="max_size" class="form-control" value="<?php echo $maxSize; ?>" min="1" max="1000">
                                <small class="form-text text-muted">Log files larger than this will be rotated</small>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="max_backups">Maximum Backup Files:</label>
                                <input type="number" id="max_backups" name="max_backups" class="form-control" value="<?php echo $maxBackups; ?>" min="1" max="20">
                                <small class="form-text text-muted">Number of backup files to keep</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Select Log Files to Rotate:</label>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th width="50">
                                                <label class="kt-checkbox kt-checkbox--bold kt-checkbox--brand">
                                                    <input type="checkbox" id="selectAll">
                                                    <span></span>
                                                </label>
                                            </th>
                                            <th>File Name</th>
                                            <th>Size</th>
                                            <th>Last Modified</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats as $stat): ?>
                                            <tr>
                                                <td>
                                                    <label class="kt-checkbox kt-checkbox--bold kt-checkbox--brand">
                                                        <input type="checkbox" name="files[]" value="<?php echo htmlspecialchars($stat['name']); ?>" 
                                                            <?php echo in_array($stat['name'], $selectedFiles) ? 'checked' : ''; ?>>
                                                        <span></span>
                                                    </label>
                                                </td>
                                                <td><?php echo htmlspecialchars($stat['name']); ?></td>
                                                <td><?php echo htmlspecialchars($stat['size_formatted']); ?></td>
                                                <td><?php echo htmlspecialchars($stat['modified_formatted']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <input type="hidden" name="action" value="rotate">
                        <button type="submit" class="btn btn-primary">Rotate Selected Log Files</button>
                    </form>

                    <script>
                        document.getElementById('selectAll').addEventListener('change', function() {
                            var checkboxes = document.querySelectorAll('input[name="files[]"]');
                            for (var i = 0; i < checkboxes.length; i++) {
                                checkboxes[i].checked = this.checked;
                            }
                        });
                    </script>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Clear an individual log file
     * @param string $file The log file to clear
     * @return void
     */
    public function clearFile(string $file = '')
    {
        if (empty($file) || !in_array($file, $this->whitelist)) {
            $this->redirect(defined('sURL') ? sURL . 'logs' : '');
            return;
        }

        // Extract filename and extension
        $pathInfo = pathinfo($file);
        $filename = $pathInfo['filename'];
        $ext = $pathInfo['extension'] ?? 'log';

        // Clear the log file
        LogManager::clearLog($filename, $ext);
        
        // Redirect back to stats or logs
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, 'stats') !== false) {
            $this->redirect(defined('sURL') ? sURL . 'logs/stats' : '');
        } else {
            $this->redirect(defined('sURL') ? sURL . 'logs' : '');
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
                defined('sURL') ? sURL . 'Logs' : ''
            );
            $this->application->addBreadcrumb(
                'Export',
                defined('sURL') ? sURL . 'Logs/export' : ''
            );
            
            $doc = Factory::getDocument();
            if (isset($doc->themeObject)) {
                $doc->themeObject->activemenu = 'logs';
            }
            
            $doc->title = 'Export Log Files';
        }

        $result = null;
        
        // Render export form
        ob_start();
        ?>
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid">
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">Export Log Files</h3>
                    </div>
                </div>
                <div class="kt-portlet__body">
                    <?php if (isset($result['error'])): ?>
                        <div class="alert alert-danger">
                            <strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post">
                        <div class="form-group">
                            <label for="file">Select Log File:</label>
                            <select name="file" id="file" class="form-control">
                                <?php foreach ($this->whitelist as $log): ?>
                                    <option value="<?php echo htmlspecialchars($log); ?>"><?php echo htmlspecialchars($log); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Export Format:</label>
                            <div class="kt-radio-inline">
                                <label class="kt-radio">
                                    <input type="radio" name="format" value="csv" checked> CSV (Excel compatible)
                                    <span></span>
                                </label>
                                <label class="kt-radio">
                                    <input type="radio" name="format" value="json"> JSON
                                    <span></span>
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Export</button>
                    </form>
                    
                    <hr>
                    
                    <h4>Export Multiple Log Files</h4>
                    <form method="post">
                        <div class="form-group">
                            <label>Select Log Files:</label>
                            <div class="kt-checkbox-list">
                                <?php foreach ($this->whitelist as $log): ?>
                                    <label class="kt-checkbox">
                                        <input type="checkbox" name="multiple_files[]" value="<?php echo htmlspecialchars($log); ?>">
                                        <?php echo htmlspecialchars($log); ?>
                                        <span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <input type="hidden" name="format" value="zip">
                        <button type="submit" class="btn btn-primary">Export as ZIP Archive</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
            echo '<p><a href="' . (defined('sURL') ? sURL . 'logs/export' : '#') . '" class="btn btn-secondary">Go Back</a></p>';
            return;
        }
        
        // Set headers for download
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $name . '_' . $startDate . '_to_' . $endDate . '.csv"');
            
            // Open output stream
            $output = fopen('php://output', 'w');
            
            // Write CSV header
            fputcsv($output, ['Timestamp', 'Level', 'Message', 'Context']);
            
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
                            
                            fputcsv($output, [$timestamp, $level, $message, $context]);
                            return;
                        }
                    } catch (\Exception $e) {
                        // Not valid JSON, continue with raw line
                    }
                }
                
                // Handle plain text log lines
                fputcsv($output, ['', '', $line, '']);
            };
            
        } else { // JSON format
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $name . '_' . $startDate . '_to_' . $endDate . '.json"');
            
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
        exit;
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
            
            // Try to extract timestamp from JSON
            if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
                $data = json_decode($line, true);
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
            // Try to extract timestamp from standard log format [date/time]
            elseif (preg_match('/^\[([\d\/]+ [\d:]+)\]/', $line, $matches)) {
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
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Force flush all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
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
        exit;
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
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.json"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Force flush all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
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
        exit;
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
                echo '<p><a href="' . (defined('sURL') ? sURL . 'logs/export' : '#') . '" class="btn btn-secondary">Go Back</a></p>';
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
            echo '<p><a href="' . (defined('sURL') ? sURL . 'logs/export' : '#') . '" class="btn btn-secondary">Go Back</a></p>';
            return;
        }
        
        // Create temporary file
        $zipFile = tempnam(sys_get_temp_dir(), 'log_export_');
        $zip = new \ZipArchive();
        
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            Factory::getDocument();
            echo '<div class="alert alert-danger">Failed to create ZIP archive.</div>';
            echo '<p><a href="' . (defined('sURL') ? sURL . 'logs/export' : '#') . '" class="btn btn-secondary">Go Back</a></p>';
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
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="logs_export_' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($zipFile);
        unlink($zipFile);
        exit;
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
                defined('sURL') ? sURL . 'Logs' : ''
            );
            $this->application->addBreadcrumb(
                'Dashboard',
                defined('sURL') ? sURL . 'Logs/dashboard' : ''
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

        // Render dashboard view
        ob_start();
        ?>
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="kt-portlet">
                        <div class="kt-portlet__head">
                            <div class="kt-portlet__head-label">
                                <h3 class="kt-portlet__head-title">Logs Dashboard</h3>
                            </div>
                            <div class="kt-portlet__head-toolbar">
                                <div class="btn-group" role="group">
                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/dashboard?timespan=1h' : '#'; ?>" 
                                       class="btn btn-sm <?php echo $timespan === '1h' ? 'btn-primary' : 'btn-secondary'; ?>">Last Hour</a>
                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/dashboard?timespan=6h' : '#'; ?>" 
                                       class="btn btn-sm <?php echo $timespan === '6h' ? 'btn-primary' : 'btn-secondary'; ?>">6 Hours</a>
                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/dashboard?timespan=24h' : '#'; ?>" 
                                       class="btn btn-sm <?php echo $timespan === '24h' ? 'btn-primary' : 'btn-secondary'; ?>">24 Hours</a>
                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/dashboard?timespan=7d' : '#'; ?>" 
                                       class="btn btn-sm <?php echo $timespan === '7d' ? 'btn-primary' : 'btn-secondary'; ?>">7 Days</a>
                                    <a href="<?php echo defined('sURL') ? sURL . 'Logs/dashboard?timespan=30d' : '#'; ?>" 
                                       class="btn btn-sm <?php echo $timespan === '30d' ? 'btn-primary' : 'btn-secondary'; ?>">30 Days</a>
                                </div>
                            </div>
                        </div>
                        <div class="kt-portlet__body">
                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="kt-portlet kt-portlet--height-fluid">
                                        <div class="kt-portlet__head">
                                            <div class="kt-portlet__head-label">
                                                <h3 class="kt-portlet__head-title">Log Entry Trends</h3>
                                            </div>
                                        </div>
                                        <div class="kt-portlet__body">
                                            <div id="log_trends_chart" style="height: 300px;"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="kt-portlet kt-portlet--height-fluid">
                                        <div class="kt-portlet__head">
                                            <div class="kt-portlet__head-label">
                                                <h3 class="kt-portlet__head-title">Log Levels Distribution</h3>
                                            </div>
                                        </div>
                                        <div class="kt-portlet__body">
                                            <div id="log_levels_chart" style="height: 300px;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="kt-portlet kt-portlet--height-fluid">
                                        <div class="kt-portlet__head">
                                            <div class="kt-portlet__head-label">
                                                <h3 class="kt-portlet__head-title">Top Errors</h3>
                                            </div>
                                        </div>
                                        <div class="kt-portlet__body">
                                            <?php if (empty($topErrors)): ?>
                                                <div class="alert alert-info">No errors found in the selected time period.</div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-hover">
                                                        <thead>
                                                            <tr>
                                                                <th>Error Message</th>
                                                                <th width="100">Count</th>
                                                                <th width="150">Log File</th>
                                                                <th width="150">Last Seen</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($topErrors as $error): ?>
                                                                <tr>
                                                                    <td>
                                                                        <?php echo htmlspecialchars(substr($error['message'], 0, 200)); ?>
                                                                        <?php if (strlen($error['message']) > 200): ?>
                                                                            <span class="text-muted">...</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="text-center">
                                                                        <span class="badge badge-danger"><?php echo $error['count']; ?></span>
                                                                    </td>
                                                                    <td>
                                                                        <a href="<?php echo defined('sURL') ? sURL . 'Logs/display/' . $error['file'] : '#'; ?>">
                                                                            <?php echo htmlspecialchars($error['file']); ?>
                                                                        </a>
                                                                    </td>
                                                                    <td>
                                                                        <?php echo htmlspecialchars($error['last_seen']); ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="kt-portlet kt-portlet--height-fluid">
                                        <div class="kt-portlet__head">
                                            <div class="kt-portlet__head-label">
                                                <h3 class="kt-portlet__head-title">System Status</h3>
                                            </div>
                                        </div>
                                        <div class="kt-portlet__body">
                                            <div class="row">
                                                <?php foreach ($systemStatus as $file => $status): ?>
                                                    <div class="col-lg-4 col-md-6 col-sm-12">
                                                        <div class="kt-widget kt-widget--general-4">
                                                            <div class="kt-widget__head">
                                                                <div class="kt-widget__media">
                                                                    <span class="kt-userpic kt-userpic--md kt-userpic--circle kt-userpic--danger">
                                                                        <span><?php echo strtoupper(substr($file, 0, 1)); ?></span>
                                                                    </span>
                                                                </div>
                                                                <div class="kt-widget__content">
                                                                    <h3 class="kt-widget__title">
                                                                        <a href="<?php echo defined('sURL') ? sURL . 'Logs/display/' . $file : '#'; ?>">
                                                                            <?php echo htmlspecialchars($file); ?>
                                                                        </a>
                                                                    </h3>
                                                                    <div class="kt-widget__desc">
                                                                        <?php if (!empty($status['last_entry'])): ?>
                                                                            Last activity: <?php echo $status['last_entry']; ?>
                                                                        <?php else: ?>
                                                                            No recent activity
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="kt-widget__body">
                                                                <div class="kt-widget__stats">
                                                                    <div class="kt-widget__stat">
                                                                        <span class="kt-widget__stat-number"><?php echo number_format($status['total_entries']); ?></span>
                                                                        <span class="kt-widget__stat-label">Entries</span>
                                                                    </div>
                                                                    <div class="kt-widget__stat">
                                                                        <span class="kt-widget__stat-number"><?php echo $status['success_rate']; ?>%</span>
                                                                        <span class="kt-widget__stat-label">Success Rate</span>
                                                                    </div>
                                                                    <div class="kt-widget__stat">
                                                                        <span class="kt-widget__stat-number"><?php echo $status['error_rate']; ?>%</span>
                                                                        <span class="kt-widget__stat-label">Error Rate</span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js"></script>
        <script>
            // Log trends chart
            var trendCtx = document.getElementById('log_trends_chart').getContext('2d');
            var trendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($trendLabels); ?>,
                    datasets: [{
                        label: 'Log Entries',
                        data: <?php echo json_encode($trendValues); ?>,
                        backgroundColor: 'rgba(93, 120, 255, 0.1)',
                        borderColor: 'rgba(93, 120, 255, 1)',
                        borderWidth: 2,
                        pointBackgroundColor: 'rgba(93, 120, 255, 1)',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: 'top'
                    },
                    scales: {
                        xAxes: [{
                            gridLines: {
                                display: false,
                                drawBorder: false
                            }
                        }],
                        yAxes: [{
                            ticks: {
                                beginAtZero: true
                            },
                            gridLines: {
                                color: '#f2f3f8',
                                drawBorder: false
                            }
                        }]
                    }
                }
            });
            
            // Log levels chart
            var levelsCtx = document.getElementById('log_levels_chart').getContext('2d');
            var levelsChart = new Chart(levelsCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_map('ucfirst', $levelLabels)); ?>,
                    datasets: [{
                        data: <?php echo json_encode($levelValues); ?>,
                        backgroundColor: <?php echo json_encode($chartColors); ?>,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutoutPercentage: 60,
                    legend: {
                        position: 'bottom'
                    },
                    tooltips: {
                        callbacks: {
                            label: function(tooltipItem, data) {
                                var dataset = data.datasets[tooltipItem.datasetIndex];
                                var total = dataset.data.reduce(function(previousValue, currentValue) {
                                    return previousValue + currentValue;
                                });
                                var currentValue = dataset.data[tooltipItem.index];
                                var percentage = Math.floor(((currentValue/total) * 100)+0.5);
                                return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            });
        </script>
        <?php
        return ob_get_clean();
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
                defined('sURL') ? sURL . 'Logs' : ''
            );
            $this->application->addBreadcrumb(
                'Filter',
                defined('sURL') ? sURL . 'Logs/filter' : ''
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

        // Render filter view
        ob_start();
        ?>
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid">
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">Filter Log Files</h3>
                    </div>
                </div>
                <div class="kt-portlet__body">
                    <form method="post">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="file">Select Log File:</label>
                                <select name="file" id="file" class="form-control">
                                    <option value="">-- Select Log File --</option>
                                    <?php foreach ($this->whitelist as $log): ?>
                                        <option value="<?php echo htmlspecialchars($log); ?>" <?php echo $file === $log ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($log); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="limit">Maximum Results:</label>
                                <select name="limit" id="limit" class="form-control">
                                    <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100 entries</option>
                                    <option value="250" <?php echo $limit === 250 ? 'selected' : ''; ?>>250 entries</option>
                                    <option value="500" <?php echo $limit === 500 ? 'selected' : ''; ?>>500 entries</option>
                                    <option value="1000" <?php echo $limit === 1000 ? 'selected' : ''; ?>>1000 entries</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="start_date">Start Date:</label>
                                <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="end_date">End Date:</label>
                                <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Log Levels:</label>
                            <div class="kt-checkbox-inline">
                                <?php foreach ($availableLevels as $level => $label): ?>
                                    <label class="kt-checkbox">
                                        <input type="checkbox" name="levels[]" value="<?php echo $level; ?>" <?php echo in_array($level, $levels) ? 'checked' : ''; ?>>
                                        <?php echo $label; ?>
                                        <span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small class="form-text text-muted">Leave empty to include all levels</small>
                        </div>

                        <div class="form-group">
                            <label for="query">Search Query:</label>
                            <input type="text" id="query" name="query" class="form-control" value="<?php echo htmlspecialchars($query); ?>" 
                                   placeholder="Search in log messages">
                        </div>

                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </form>

                    <?php if ($hasResults): ?>
                        <hr>
                        
                        <h4>
                            Filter Results 
                            <small class="text-muted"><?php echo count($results); ?> entries found</small>
                        </h4>
                        
                        <?php if (empty($results)): ?>
                            <div class="alert alert-info">No log entries match the specified filters.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th width="180">Timestamp</th>
                                            <th width="100">Level</th>
                                            <th>Message</th>
                                            <th width="100">Context</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results as $entry): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($entry['timestamp'] ?? ''); ?></td>
                                                <td>
                                                    <?php 
                                                    $levelClass = '';
                                                    $level = strtolower($entry['level'] ?? '');
                                                    switch ($level) {
                                                        case 'emergency':
                                                        case 'alert':
                                                        case 'critical':
                                                        case 'error':
                                                            $levelClass = 'badge-danger';
                                                            break;
                                                        case 'warning':
                                                            $levelClass = 'badge-warning';
                                                            break;
                                                        case 'notice':
                                                        case 'info':
                                                            $levelClass = 'badge-info';
                                                            break;
                                                        case 'debug':
                                                            $levelClass = 'badge-secondary';
                                                            break;
                                                        default:
                                                            $levelClass = 'badge-secondary';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $levelClass; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($entry['level'] ?? 'info')); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($entry['message'] ?? ''); ?></td>
                                                <td>
                                                    <?php if (!empty($entry['context'])): ?>
                                                        <button type="button" class="btn btn-sm btn-secondary" 
                                                                data-toggle="modal" data-target="#contextModal<?php echo $entry['id']; ?>">
                                                            View
                                                        </button>
                                                        
                                                        <div class="modal fade" id="contextModal<?php echo $entry['id']; ?>" tabindex="-1" role="dialog" 
                                                             aria-labelledby="contextModalLabel<?php echo $entry['id']; ?>" aria-hidden="true">
                                                            <div class="modal-dialog modal-lg" role="document">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title" id="contextModalLabel<?php echo $entry['id']; ?>">
                                                                            Context Data
                                                                        </h5>
                                                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                            <span aria-hidden="true">&times;</span>
                                                                        </button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <pre class="bg-light p-3"><?php echo htmlspecialchars(json_encode($entry['context'], JSON_PRETTY_PRINT)); ?></pre>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
            header('Content-Type: text/plain');
            echo "Error: Log file not found.";
            exit;
        }
        
        // Force disable any output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for download
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');
        header('Pragma: public');
        
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
        
        exit;
    }
    
}