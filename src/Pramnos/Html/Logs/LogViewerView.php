<?php

namespace Pramnos\Html\Logs;

/**
 * LogViewer View Handler
 * Provides methods to render the log viewer interface
 * 
 * @package     PramnosFramework
 * @subpackage  Html/Logs
 */
class LogViewerView
{
    /**
     * Controller instance
     * @var \Pramnos\Application\Controller
     */
    protected $controller;

    /**
     * Constructor
     * @param \Pramnos\Application\Controller $controller Controller instance
     */
    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Render the log viewer interface
     * 
     * @param string $currentFile Current selected log file
     * @param array $whitelist List of available log files
     * @return string HTML content
     */
    public function render(string $currentFile, array $whitelist): string
    {
        $html = $this->getHeader();
        $html .= $this->getBody($currentFile, $whitelist);
        return $html;
    }

    /**
     * Renders the page header including CSS and meta tags
     * 
     * @return string HTML header content
     */
    protected function getHeader(): string
    {
        return '';
    }

    /**
     * Renders the body content of the log viewer
     * 
     * @param string $currentFile Current selected log file
     * @param array $whitelist List of available log files
     * @return string HTML body content
     */
    protected function getBody(string $currentFile, array $whitelist): string
    {
        // Convert path variables for JavaScript
        $baseUrl = defined('sURL') ? sURL : (defined('URL') ? URL : '/');
        $logUrl = rtrim($baseUrl, '/') . '/logs';

        ob_start();
?>
        <!-- begin:: Content -->
        <div class="kt-container kt-container--fluid kt-grid__item kt-grid__item--fluid" style="height: 100vh;">
            <div class="card" style="height: 80%;">
                <div class="card-body" style="height: 100%; display: flex; flex-direction: column;">
                    

                    <form id="logSettings" class="mb-3">
                        <div class="form-row mb-3">
                            <div class="form-group col-md-3">
                                <label for="file">Log file:</label>
                                <div class="input-group">
                                    <select id="file" name="file" class="form-control">
                                        <?php foreach ($whitelist as $file): ?>
                                            <option value="<?php echo htmlspecialchars($file); ?>" <?php echo $file === $currentFile ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($file); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="input-group-append">
                                        <button type="button" id="manualRefresh" class="btn btn-outline-secondary" title="Refresh">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group col-md-2">
                                <label for="autoUpdate">Auto update:</label>
                                <select id="autoUpdate" name="autoUpdate" class="form-control">
                                    <option value="never" selected>never</option>
                                    <option value="3">3 seconds</option>
                                    <option value="5">5 seconds</option>
                                    <option value="10">10 seconds</option>
                                    <option value="20">20 seconds</option>
                                    <option value="30">30 seconds</option>
                                    <option value="60">1 Minute</option>
                                </select>
                            </div>

                            <div class="form-group col-md-2">
                                <label for="logLevel">Log level:</label>
                                <select id="logLevel" name="logLevel" class="form-control">
                                    <option value="all" selected>All Levels</option>
                                    <option value="emergency">Emergency</option>
                                    <option value="alert">Alert</option>
                                    <option value="critical">Critical</option>
                                    <option value="error">Error</option>
                                    <option value="warning">Warning</option>
                                    <option value="notice">Notice</option>
                                    <option value="info">Info</option>
                                    <option value="debug">Debug</option>
                                </select>
                            </div>

                            <div class="form-group col-md-2">
                                <label for="maxLines">Lines per page:</label>
                                <input type="number" id="maxLines" name="maxLines" step="1" value="20" min="1" max="999999999" class="form-control">
                            </div>

                            <div class="form-group col-md-3">
                                <label for="search">Search:</label>
                                <div class="input-group">
                                    <input type="text" id="search" name="search" class="form-control" placeholder="Enter search text...">
                                    <div class="input-group-append">
                                        <button type="button" id="clearSearch" class="btn btn-outline-secondary" title="Clear search">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row align-items-end">
                            <div class="btn-group">
                                <button type="button" id="firstPage" class="btn btn-outline-secondary" title="First page">
                                    <i class="fas fa-angle-double-left"></i>
                                </button>
                                <button type="button" id="prevPage" class="btn btn-outline-secondary" title="Previous page">
                                    <i class="fas fa-angle-left"></i>
                                </button>
                                <div class="input-group" style="width: 115px;">
                                    <input type="number" id="page" name="page" value="1" min="1" class="form-control" title="Current page">
                                    <div class="input-group-append">
                                        <span class="input-group-text" id="totalPagesLabel">/ 1</span>
                                    </div>
                                </div>
                                <button type="button" id="nextPage" class="btn btn-outline-secondary" title="Next page">
                                    <i class="fas fa-angle-right"></i>
                                </button>
                                <button type="button" id="lastPage" class="btn btn-outline-secondary" title="Last page">
                                    <i class="fas fa-angle-double-right"></i>
                                </button>
                            </div>
                            
                            <div class="ml-auto">
                                <div class="btn-group">
                                    <button type="button" id="toggleOrder" class="btn btn-outline-secondary" title="Toggle order">
                                        <input type="hidden" id="reverse" name="reverse" value="1">
                                        <i class="fas fa-sort-amount-down"></i>
                                        <span class="order-text">Newest first</span>
                                    </button>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="downloadDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <i class="fas fa-download"></i> Export
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="downloadDropdown">
                                            <a class="dropdown-item" href="#" data-export-format="csv" data-file="<?php echo htmlspecialchars($currentFile); ?>">
                                                <i class="fas fa-file-csv"></i> Export as CSV
                                            </a>
                                            <a class="dropdown-item" href="#" data-export-format="json" data-file="<?php echo htmlspecialchars($currentFile); ?>">
                                                <i class="fas fa-file-code"></i> Export as JSON
                                            </a>
                                            <a class="dropdown-item" href="#" data-export-format="raw" data-file="<?php echo htmlspecialchars($currentFile); ?>">
                                                <i class="fas fa-file-alt"></i> Download Raw Log
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item" href="#" id="dateRangeExport">
                                                <i class="fas fa-calendar-alt"></i> Export Date Range
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                    <iframe id="logFrame" src="<?php echo $logUrl; ?>/raw/file/<?php echo htmlspecialchars($currentFile); ?>/maxLines/20/reverse/1/page/1"
                        style="width: 100%; height: 100%; border: none; overflow-y: auto; overflow-x: hidden; flex-grow: 1; background: #f8f9fa;"></iframe>

                    <!-- Date Range Export Modal -->
                    <div class="modal fade" id="dateRangeModal" tabindex="-1" role="dialog" aria-labelledby="dateRangeModalLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="dateRangeModalLabel">Export Log by Date Range</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <form id="dateRangeForm" action="<?php echo $logUrl; ?>/export" method="post">
                                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($currentFile); ?>">
                                        <div class="form-group">
                                            <label for="start_date">Start Date:</label>
                                            <input type="date" id="start_date" name="start_date" class="form-control" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="end_date">End Date:</label>
                                            <input type="date" id="end_date" name="end_date" class="form-control" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Export Format:</label>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="format" id="formatCsv" value="csv" checked>
                                                <label class="form-check-label" for="formatCsv">
                                                    CSV (Excel compatible)
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="format" id="formatJson" value="json">
                                                <label class="form-check-label" for="formatJson">
                                                    JSON
                                                </label>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" id="exportDateRange">Export</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                        let totalPages = 1;
                        let autoUpdateTimer = null;
                        const logBaseUrl = '<?php echo $logUrl; ?>';

                        function debounce(func, wait) {
                            let timeout;
                            return function executedFunction(...args) {
                                const later = () => {
                                    clearTimeout(timeout);
                                    func(...args);
                                };
                                clearTimeout(timeout);
                                timeout = setTimeout(later, wait);
                            };
                        }

                        // Add the debounced search function
                        const debouncedSearch = debounce(() => {
                            document.getElementById('page').value = 1;
                            updateLogFrame();
                        }, 500); // 500ms delay

                        function updateTotalPagesLabel() {
                            document.getElementById('totalPagesLabel').textContent = `/ ${totalPages}`;
                        }

                        function updateLogFrame() {
                            const file = document.getElementById('file').value;
                            const autoUpdate = document.getElementById('autoUpdate').value;
                            const maxLines = document.getElementById('maxLines').value;
                            const reverse = document.getElementById('reverse').value;
                            const search = encodeURIComponent(document.getElementById('search').value.trim().replace(/ /g, '{space}'));
                            const page = document.getElementById('page').value;
                            const logLevel = document.getElementById('logLevel').value;
                            const iframe = document.getElementById('logFrame');

                            let url = `${logBaseUrl}/raw/file/${file}/maxLines/${maxLines}/reverse/${reverse}/page/${page}`;
                            
                            if (search) {
                                url += `/search/${search}`;
                            }
                            
                            if (logLevel !== 'all') {
                                url += `/level/${logLevel}`;
                            }
                            
                            iframe.src = url;

                            updatePaginationState();
                        }

                        function updatePaginationState() {
                            const currentPage = parseInt(document.getElementById('page').value);
                            const buttons = {
                                first: document.getElementById('firstPage'),
                                prev: document.getElementById('prevPage'),
                                next: document.getElementById('nextPage'),
                                last: document.getElementById('lastPage')
                            };

                            buttons.first.disabled = currentPage <= 1;
                            buttons.prev.disabled = currentPage <= 1;
                            buttons.next.disabled = currentPage >= totalPages;
                            buttons.last.disabled = currentPage >= totalPages;

                            // Update button styles based on state
                            Object.values(buttons).forEach(button => {
                                if (button.disabled) {
                                    button.classList.replace('btn-outline-secondary', 'btn-secondary');
                                } else {
                                    button.classList.replace('btn-secondary', 'btn-outline-secondary');
                                }
                            });
                        }

                        function setPage(newPage) {
                            const pageInput = document.getElementById('page');
                            pageInput.value = Math.max(1, Math.min(newPage, totalPages));
                            updateLogFrame();
                        }

                        function setupAutoUpdate() {
                            const autoUpdate = document.getElementById('autoUpdate').value;

                            if (autoUpdateTimer) {
                                clearInterval(autoUpdateTimer);
                                autoUpdateTimer = null;
                            }

                            if (autoUpdate !== 'never') {
                                autoUpdateTimer = setInterval(updateLogFrame, autoUpdate * 1000);
                            }
                        }

                        // Event listeners for pagination buttons
                        document.getElementById('firstPage').addEventListener('click', () => setPage(1));
                        document.getElementById('prevPage').addEventListener('click', () => setPage(parseInt(document.getElementById('page').value) - 1));
                        document.getElementById('nextPage').addEventListener('click', () => setPage(parseInt(document.getElementById('page').value) + 1));
                        document.getElementById('lastPage').addEventListener('click', () => setPage(totalPages));

                        // Clear search button
                        document.getElementById('clearSearch').addEventListener('click', function() {
                            document.getElementById('search').value = '';
                            document.getElementById('page').value = 1;
                            updateLogFrame();
                        });

                        // Toggle sort order
                        document.getElementById('toggleOrder').addEventListener('click', function() {
                            const reverseInput = document.getElementById('reverse');
                            const icon = this.querySelector('i');
                            const orderText = this.querySelector('.order-text');
                            
                            if (reverseInput.value === '1') {
                                reverseInput.value = '0';
                                icon.classList.replace('fa-sort-amount-down', 'fa-sort-amount-up');
                                orderText.textContent = 'Oldest first';
                            } else {
                                reverseInput.value = '1';
                                icon.classList.replace('fa-sort-amount-up', 'fa-sort-amount-down');
                                orderText.textContent = 'Newest first';
                            }
                            
                            document.getElementById('page').value = 1;
                            updateLogFrame();
                        });

                        // Date range export
                        document.getElementById('dateRangeExport').addEventListener('click', function(e) {
                            e.preventDefault();
                            $('#dateRangeModal').modal('show');
                        });
                        
                        document.getElementById('exportDateRange').addEventListener('click', function() {
                            document.getElementById('dateRangeForm').submit();
                        });

                        // Form change events
                        document.getElementById('logSettings').addEventListener('change', function(e) {
                            // Reset to page 1 if changing anything except the page number
                            if (e.target.id !== 'page') {
                                document.getElementById('page').value = 1;
                            }

                            // If file selector was changed, update the URL
                            if (e.target.id === 'file') {
                                // Get the current URL and update or add the file parameter
                                const url = new URL(window.location.href);
                                url.searchParams.set('file', e.target.value);
                                
                                // Update browser history without reloading the page
                                window.history.replaceState({}, '', url.toString());
                            }

                            updateLogFrame();

                            if (e.target.id === 'autoUpdate') {
                                setupAutoUpdate();
                            }
                        });

                        // Manual refresh button
                        document.getElementById('manualRefresh').addEventListener('click', updateLogFrame);

                        // Keyboard navigation
                        document.addEventListener('keydown', function(e) {
                            if (e.target.tagName === 'INPUT' && e.target.type === 'text') {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    updateLogFrame();
                                }
                                return;
                            }

                            if (e.target.tagName === 'INPUT' && e.target.type === 'number') {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    e.target.blur();
                                    updateLogFrame();
                                }
                                return;
                            }

                            // Add keyboard shortcuts for navigation
                            if (document.activeElement.tagName !== 'INPUT') {
                                switch (e.key) {
                                    case 'ArrowLeft':
                                        if (!document.getElementById('prevPage').disabled) {
                                            setPage(parseInt(document.getElementById('page').value) - 1);
                                        }
                                        break;
                                    case 'ArrowRight':
                                        if (!document.getElementById('nextPage').disabled) {
                                            setPage(parseInt(document.getElementById('page').value) + 1);
                                        }
                                        break;
                                    case 'Home':
                                        if (!document.getElementById('firstPage').disabled) {
                                            setPage(1);
                                        }
                                        break;
                                    case 'End':
                                        if (!document.getElementById('lastPage').disabled) {
                                            setPage(totalPages);
                                        }
                                        break;
                                }
                            }
                        });

                        // Listen for messages from the iframe to update total pages
                        window.addEventListener('message', function(event) {
                            if (event.data && event.data.totalPages) {
                                totalPages = event.data.totalPages;
                                updateTotalPagesLabel();
                                updatePaginationState();
                            }
                        });

                        // Add decode function for the initial load:
                        document.addEventListener('DOMContentLoaded', function() {
                            // Get the current URL search params
                            const urlParams = new URLSearchParams(window.location.search);
                            if (urlParams.has('search')) {
                                const searchField = document.getElementById('search');
                                searchField.value = decodeURIComponent(urlParams.get('search'));
                            }

                            // Set date inputs to current date range
                            const today = new Date();
                            const thirtyDaysAgo = new Date();
                            thirtyDaysAgo.setDate(today.getDate() - 30);
                            
                            document.getElementById('start_date').valueAsDate = thirtyDaysAgo;
                            document.getElementById('end_date').valueAsDate = today;

                            const searchInput = document.getElementById('search');
                            searchInput.addEventListener('input', debouncedSearch);

                            // Update the existing key event handler for search
                            searchInput.addEventListener('keydown', function(e) {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    updateLogFrame();
                                }
                            });

                            // If file param exists in URL, update the file selector and iframe
                            if (urlParams.has('file')) {
                                const fileSelect = document.getElementById('file');
                                const fileValue = urlParams.get('file');
                                
                                // Check if the file exists in the options and select it
                                for (let i = 0; i < fileSelect.options.length; i++) {
                                    if (fileSelect.options[i].value === fileValue) {
                                        fileSelect.value = fileValue;
                                        // Also update the iframe with the selected file
                                        updateLogFrame();
                                        break;
                                    }
                                }
                            }

                        });

                        // Handle page input validation
                        document.getElementById('page').addEventListener('blur', function() {
                            const currentValue = parseInt(this.value);
                            if (isNaN(currentValue) || currentValue < 1) {
                                this.value = 1;
                            } else if (currentValue > totalPages) {
                                this.value = totalPages;
                            }
                            updateLogFrame();
                        });

                        // Initial setup
                        updatePaginationState();
                        setupAutoUpdate();

                        // Add loading indicator to iframe
                        const iframe = document.getElementById('logFrame');
                        iframe.onload = function() {
                            iframe.style.opacity = 1;
                        }
                        iframe.onerror = function() {
                            console.error("Failed to load log content");
                        }
                        
                        // Export functionality using new window/tab to force download
                        document.querySelectorAll('[data-export-format]').forEach(link => {
                            link.addEventListener('click', function(e) {
                                e.preventDefault();
                                const format = this.getAttribute('data-export-format');
                                // Get the currently selected file, not the initial file
                                const file = document.getElementById('file').value;
                                
                                // Create hidden download iframe to avoid navigating the main page
                                const downloadFrame = document.createElement('iframe');
                                downloadFrame.style.display = 'none';
                                downloadFrame.src = `${logBaseUrl}/export?file=${encodeURIComponent(file)}&format=${format}`;
                                document.body.appendChild(downloadFrame);
                                
                                // Remove the frame after download starts
                                setTimeout(() => {
                                    document.body.removeChild(downloadFrame);
                                }, 2000);
                            });
                        });
                    </script>

                    <style>
                        .input-group-text {
                            font-size: 0.9rem;
                        }

                        .btn-group {
                            display: flex;
                        }

                        .btn-group .btn {
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }

                        .btn-group .btn:first-child {
                            border-top-right-radius: 0;
                            border-bottom-right-radius: 0;
                        }
                        
                        .order-text {
                            margin-left: 5px;
                            display: inline-block;
                        }
                        
                        #logFrame {
                            transition: opacity 0.2s;
                            opacity: 0.7;
                        }
                        
                        /* Make the modal backdrop darker */
                        .modal-backdrop {
                            background-color: rgba(0, 0, 0, 0.5);
                        }
                        
                        /* Improve form styling in modal */
                        .modal .form-group:last-child {
                            margin-bottom: 0;
                        }
                        
                        .modal .form-check {
                            padding-left: 1.5rem;
                        }
                    </style>
                </div>
            </div>
        </div>
<?php
        return ob_get_clean();
    }
}
