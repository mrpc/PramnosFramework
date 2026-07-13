<?php

namespace Pramnos\Html\Logs;

/**
 * LogViewer View Handler
 * Provides methods to render the log viewer interface
 * 
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
        <!-- begin:: Log viewer (self-contained: scoped CSS + vanilla JS, no
             Bootstrap/jQuery/FontAwesome dependency, so it renders identically
             under every UI theme — tailwind / bootstrap / plain-css). -->
        <div class="pf-logviewer">
            <style>
                .pf-logviewer { display:flex; flex-direction:column; min-height:70vh; font-size:0.9rem; color:#1f2937; }
                .pf-logviewer *, .pf-logviewer *::before, .pf-logviewer *::after { box-sizing:border-box; }
                .pf-lv-panel { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 14px; margin-bottom:12px; }
                .pf-lv-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; }
                .pf-lv-row + .pf-lv-row { margin-top:12px; }
                .pf-lv-field { display:flex; flex-direction:column; gap:4px; }
                .pf-lv-field.grow { flex:1 1 220px; min-width:180px; }
                .pf-lv-field label { font-size:0.75rem; font-weight:600; color:#6b7280; }
                .pf-lv-control { height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; background:#fff; color:#1f2937; font-size:0.875rem; outline:none; }
                .pf-lv-control:focus { border-color:#2563eb; box-shadow:0 0 0 2px rgba(37,99,235,.2); }
                select.pf-lv-control { min-width:120px; }
                .pf-lv-inputgroup { display:flex; }
                .pf-lv-inputgroup .pf-lv-control { flex:1 1 auto; border-top-right-radius:0; border-bottom-right-radius:0; }
                .pf-lv-inputgroup .pf-lv-btn { border-top-left-radius:0; border-bottom-left-radius:0; border-left:0; }
                .pf-lv-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; height:36px; padding:0 12px; border:1px solid #d1d5db; border-radius:6px; background:#fff; color:#374151; font-size:0.875rem; cursor:pointer; white-space:nowrap; transition:background-color .15s,color .15s; }
                .pf-lv-btn:hover { background:#f3f4f6; }
                .pf-lv-btn:disabled { opacity:.45; cursor:not-allowed; background:#f9fafb; }
                .pf-lv-btn-primary { background:#2563eb; border-color:#2563eb; color:#fff; }
                .pf-lv-btn-primary:hover { background:#1d4ed8; }
                .pf-lv-btn-group { display:flex; }
                .pf-lv-btn-group > .pf-lv-btn, .pf-lv-btn-group > .pf-lv-inputgroup > .pf-lv-control, .pf-lv-btn-group > .pf-lv-dropdown > .pf-lv-btn { border-radius:0; margin-left:-1px; }
                .pf-lv-btn-group > :first-child, .pf-lv-btn-group > :first-child .pf-lv-btn { border-top-left-radius:6px; border-bottom-left-radius:6px; margin-left:0; }
                .pf-lv-btn-group > :last-child, .pf-lv-btn-group > :last-child .pf-lv-btn { border-top-right-radius:6px; border-bottom-right-radius:6px; }
                .pf-lv-page { display:flex; align-items:center; }
                .pf-lv-page .pf-lv-control { width:60px; text-align:center; border-radius:0; }
                .pf-lv-page .pf-lv-total { display:inline-flex; align-items:center; height:36px; padding:0 10px; border:1px solid #d1d5db; border-left:0; background:#f3f4f6; color:#6b7280; font-size:0.8rem; }
                .pf-lv-spacer { margin-left:auto; }
                .pf-lv-actions { display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap; }
                .pf-lv-dropdown { position:relative; display:inline-block; }
                .pf-lv-menu { display:none; position:absolute; right:0; top:calc(100% + 4px); min-width:200px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 6px 20px rgba(0,0,0,.12); padding:6px 0; z-index:1000; }
                .pf-lv-dropdown.open .pf-lv-menu { display:block; }
                .pf-lv-menu-item { display:block; padding:8px 14px; color:#374151; text-decoration:none; font-size:0.875rem; cursor:pointer; }
                .pf-lv-menu-item:hover { background:#f3f4f6; }
                .pf-lv-divider { height:1px; background:#e5e7eb; margin:6px 0; }
                #logFrame { transition:opacity .2s; opacity:0.7; width:100%; border:1px solid #e5e7eb; border-radius:8px; flex:1 1 auto; min-height:420px; background:#f8f9fa; overflow:auto; }
                .pf-lv-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1050; align-items:flex-start; justify-content:center; padding:40px 16px; }
                .pf-lv-modal.open { display:flex; }
                .pf-lv-modal-dialog { background:#fff; border-radius:10px; width:100%; max-width:460px; box-shadow:0 10px 40px rgba(0,0,0,.25); overflow:hidden; }
                .pf-lv-modal-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #e5e7eb; }
                .pf-lv-modal-head h5 { margin:0; font-size:1rem; font-weight:600; }
                .pf-lv-modal-close { border:0; background:none; font-size:1.4rem; line-height:1; cursor:pointer; color:#6b7280; }
                .pf-lv-modal-body { padding:18px; }
                .pf-lv-modal-foot { display:flex; justify-content:flex-end; gap:8px; padding:14px 18px; border-top:1px solid #e5e7eb; }
                .pf-lv-modal-body .pf-lv-field { margin-bottom:14px; }
                .pf-lv-check { display:flex; align-items:center; gap:8px; font-size:0.875rem; margin-bottom:6px; }
            </style>

            <form id="logSettings" class="pf-lv-panel">
                <div class="pf-lv-row">
                    <div class="pf-lv-field grow">
                        <label for="file">Log file:</label>
                        <div class="pf-lv-inputgroup">
                            <select id="file" name="file" class="pf-lv-control">
                                <?php foreach ($whitelist as $file): ?>
                                    <option value="<?php echo htmlspecialchars($file); ?>" <?php echo $file === $currentFile ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($file); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="manualRefresh" class="pf-lv-btn" title="Refresh">&#8635;</button>
                        </div>
                    </div>

                    <div class="pf-lv-field">
                        <label for="autoUpdate">Auto update:</label>
                        <select id="autoUpdate" name="autoUpdate" class="pf-lv-control">
                            <option value="never" selected>never</option>
                            <option value="3">3 seconds</option>
                            <option value="5">5 seconds</option>
                            <option value="10">10 seconds</option>
                            <option value="20">20 seconds</option>
                            <option value="30">30 seconds</option>
                            <option value="60">1 Minute</option>
                        </select>
                    </div>

                    <div class="pf-lv-field">
                        <label for="logLevel">Log level:</label>
                        <select id="logLevel" name="logLevel" class="pf-lv-control">
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

                    <div class="pf-lv-field">
                        <label for="maxLines">Lines per page:</label>
                        <input type="number" id="maxLines" name="maxLines" step="1" value="20" min="1" max="999999999" class="pf-lv-control" style="width:110px">
                    </div>

                    <div class="pf-lv-field grow">
                        <label for="search">Search:</label>
                        <div class="pf-lv-inputgroup">
                            <input type="text" id="search" name="search" class="pf-lv-control" placeholder="Enter search text...">
                            <button type="button" id="clearSearch" class="pf-lv-btn" title="Clear search">&#10005;</button>
                        </div>
                    </div>
                </div>

                <div class="pf-lv-row">
                    <div class="pf-lv-btn-group">
                        <button type="button" id="firstPage" class="pf-lv-btn" title="First page">&laquo;</button>
                        <button type="button" id="prevPage" class="pf-lv-btn" title="Previous page">&lsaquo;</button>
                        <div class="pf-lv-page">
                            <input type="number" id="page" name="page" value="1" min="1" class="pf-lv-control" title="Current page">
                            <span class="pf-lv-total" id="totalPagesLabel">/ 1</span>
                        </div>
                        <button type="button" id="nextPage" class="pf-lv-btn" title="Next page">&rsaquo;</button>
                        <button type="button" id="lastPage" class="pf-lv-btn" title="Last page">&raquo;</button>
                    </div>

                    <div class="pf-lv-actions pf-lv-spacer">
                        <button type="button" id="toggleOrder" class="pf-lv-btn" title="Toggle order">
                            <input type="hidden" id="reverse" name="reverse" value="1">
                            <span class="order-icon">&#8595;</span>
                            <span class="order-text">Newest first</span>
                        </button>
                        <div class="pf-lv-dropdown">
                            <button class="pf-lv-btn" type="button" id="downloadDropdown" aria-haspopup="true" aria-expanded="false">
                                &#11015; Export
                            </button>
                            <div class="pf-lv-menu" aria-labelledby="downloadDropdown">
                                <a class="pf-lv-menu-item" href="#" data-export-format="csv" data-file="<?php echo htmlspecialchars($currentFile); ?>">Export as CSV</a>
                                <a class="pf-lv-menu-item" href="#" data-export-format="json" data-file="<?php echo htmlspecialchars($currentFile); ?>">Export as JSON</a>
                                <a class="pf-lv-menu-item" href="#" data-export-format="raw" data-file="<?php echo htmlspecialchars($currentFile); ?>">Download Raw Log</a>
                                <div class="pf-lv-divider"></div>
                                <a class="pf-lv-menu-item" href="#" id="dateRangeExport">Export Date Range</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <iframe id="logFrame" src="<?php echo $logUrl; ?>/raw/file/<?php echo htmlspecialchars($currentFile); ?>/maxLines/20/reverse/1/page/1"></iframe>

            <!-- Date Range Export Modal -->
            <div class="pf-lv-modal" id="dateRangeModal" role="dialog" aria-labelledby="dateRangeModalLabel" aria-hidden="true">
                <div class="pf-lv-modal-dialog" role="document">
                    <div class="pf-lv-modal-head">
                        <h5 id="dateRangeModalLabel">Export Log by Date Range</h5>
                        <button type="button" class="pf-lv-modal-close" data-lv-dismiss aria-label="Close">&times;</button>
                    </div>
                    <div class="pf-lv-modal-body">
                        <form id="dateRangeForm" action="<?php echo $logUrl; ?>/export" method="post">
                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($currentFile); ?>">
                            <div class="pf-lv-field">
                                <label for="start_date">Start Date:</label>
                                <input type="date" id="start_date" name="start_date" class="pf-lv-control" required>
                            </div>
                            <div class="pf-lv-field">
                                <label for="end_date">End Date:</label>
                                <input type="date" id="end_date" name="end_date" class="pf-lv-control" required>
                            </div>
                            <div class="pf-lv-field">
                                <label>Export Format:</label>
                                <label class="pf-lv-check">
                                    <input type="radio" name="format" id="formatCsv" value="csv" checked> CSV (Excel compatible)
                                </label>
                                <label class="pf-lv-check">
                                    <input type="radio" name="format" id="formatJson" value="json"> JSON
                                </label>
                            </div>
                        </form>
                    </div>
                    <div class="pf-lv-modal-foot">
                        <button type="button" class="pf-lv-btn" data-lv-dismiss>Cancel</button>
                        <button type="button" class="pf-lv-btn pf-lv-btn-primary" id="exportDateRange">Export</button>
                    </div>
                </div>
            </div>

            <script>
                (function () {
                    let totalPages = 1;
                    let autoUpdateTimer = null;
                    const logBaseUrl = '<?php echo $logUrl; ?>';

                    function debounce(func, wait) {
                        let timeout;
                        return function executedFunction(...args) {
                            const later = () => { clearTimeout(timeout); func(...args); };
                            clearTimeout(timeout);
                            timeout = setTimeout(later, wait);
                        };
                    }

                    const debouncedSearch = debounce(() => {
                        document.getElementById('page').value = 1;
                        updateLogFrame();
                    }, 500);

                    function updateTotalPagesLabel() {
                        document.getElementById('totalPagesLabel').textContent = `/ ${totalPages}`;
                    }

                    function updateLogFrame() {
                        const file = document.getElementById('file').value;
                        const maxLines = document.getElementById('maxLines').value;
                        const reverse = document.getElementById('reverse').value;
                        const search = encodeURIComponent(document.getElementById('search').value.trim().replace(/ /g, '{space}'));
                        const page = document.getElementById('page').value;
                        const logLevel = document.getElementById('logLevel').value;
                        const iframe = document.getElementById('logFrame');

                        let url = `${logBaseUrl}/raw/file/${file}/maxLines/${maxLines}/reverse/${reverse}/page/${page}`;
                        if (search) { url += `/search/${search}`; }
                        if (logLevel !== 'all') { url += `/level/${logLevel}`; }

                        iframe.src = url;
                        updatePaginationState();
                    }

                    function updatePaginationState() {
                        const currentPage = parseInt(document.getElementById('page').value);
                        document.getElementById('firstPage').disabled = currentPage <= 1;
                        document.getElementById('prevPage').disabled = currentPage <= 1;
                        document.getElementById('nextPage').disabled = currentPage >= totalPages;
                        document.getElementById('lastPage').disabled = currentPage >= totalPages;
                    }

                    function setPage(newPage) {
                        const pageInput = document.getElementById('page');
                        pageInput.value = Math.max(1, Math.min(newPage, totalPages));
                        updateLogFrame();
                    }

                    function setupAutoUpdate() {
                        const autoUpdate = document.getElementById('autoUpdate').value;
                        if (autoUpdateTimer) { clearInterval(autoUpdateTimer); autoUpdateTimer = null; }
                        if (autoUpdate !== 'never') { autoUpdateTimer = setInterval(updateLogFrame, autoUpdate * 1000); }
                    }

                    // Pagination buttons
                    document.getElementById('firstPage').addEventListener('click', () => setPage(1));
                    document.getElementById('prevPage').addEventListener('click', () => setPage(parseInt(document.getElementById('page').value) - 1));
                    document.getElementById('nextPage').addEventListener('click', () => setPage(parseInt(document.getElementById('page').value) + 1));
                    document.getElementById('lastPage').addEventListener('click', () => setPage(totalPages));

                    // Clear search
                    document.getElementById('clearSearch').addEventListener('click', function () {
                        document.getElementById('search').value = '';
                        document.getElementById('page').value = 1;
                        updateLogFrame();
                    });

                    // Toggle sort order
                    document.getElementById('toggleOrder').addEventListener('click', function () {
                        const reverseInput = document.getElementById('reverse');
                        const icon = this.querySelector('.order-icon');
                        const orderText = this.querySelector('.order-text');
                        if (reverseInput.value === '1') {
                            reverseInput.value = '0';
                            icon.textContent = '↑';
                            orderText.textContent = 'Oldest first';
                        } else {
                            reverseInput.value = '1';
                            icon.textContent = '↓';
                            orderText.textContent = 'Newest first';
                        }
                        document.getElementById('page').value = 1;
                        updateLogFrame();
                    });

                    // Export dropdown (vanilla, no Bootstrap JS)
                    const dropdown = document.getElementById('downloadDropdown').parentElement;
                    document.getElementById('downloadDropdown').addEventListener('click', function (e) {
                        e.stopPropagation();
                        dropdown.classList.toggle('open');
                    });
                    document.addEventListener('click', function (e) {
                        if (!dropdown.contains(e.target)) { dropdown.classList.remove('open'); }
                    });

                    // Date range modal (vanilla, no jQuery)
                    const modal = document.getElementById('dateRangeModal');
                    function closeModal() { modal.classList.remove('open'); }
                    document.getElementById('dateRangeExport').addEventListener('click', function (e) {
                        e.preventDefault();
                        dropdown.classList.remove('open');
                        modal.classList.add('open');
                    });
                    modal.querySelectorAll('[data-lv-dismiss]').forEach(el => el.addEventListener('click', closeModal));
                    modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
                    document.getElementById('exportDateRange').addEventListener('click', function () {
                        document.getElementById('dateRangeForm').submit();
                    });

                    // Form change events
                    document.getElementById('logSettings').addEventListener('change', function (e) {
                        if (e.target.id !== 'page') { document.getElementById('page').value = 1; }
                        if (e.target.id === 'file') {
                            const url = new URL(window.location.href);
                            url.searchParams.set('file', e.target.value);
                            window.history.replaceState({}, '', url.toString());
                        }
                        updateLogFrame();
                        if (e.target.id === 'autoUpdate') { setupAutoUpdate(); }
                    });

                    // Manual refresh
                    document.getElementById('manualRefresh').addEventListener('click', updateLogFrame);

                    // Keyboard navigation
                    document.addEventListener('keydown', function (e) {
                        if (e.target.tagName === 'INPUT' && e.target.type === 'text') {
                            if (e.key === 'Enter') { e.preventDefault(); updateLogFrame(); }
                            return;
                        }
                        if (e.target.tagName === 'INPUT' && e.target.type === 'number') {
                            if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); updateLogFrame(); }
                            return;
                        }
                        if (document.activeElement.tagName !== 'INPUT') {
                            switch (e.key) {
                                case 'ArrowLeft':
                                    if (!document.getElementById('prevPage').disabled) { setPage(parseInt(document.getElementById('page').value) - 1); }
                                    break;
                                case 'ArrowRight':
                                    if (!document.getElementById('nextPage').disabled) { setPage(parseInt(document.getElementById('page').value) + 1); }
                                    break;
                                case 'Home':
                                    if (!document.getElementById('firstPage').disabled) { setPage(1); }
                                    break;
                                case 'End':
                                    if (!document.getElementById('lastPage').disabled) { setPage(totalPages); }
                                    break;
                            }
                        }
                    });

                    // Messages from iframe → total pages
                    window.addEventListener('message', function (event) {
                        if (event.data && event.data.totalPages) {
                            totalPages = event.data.totalPages;
                            updateTotalPagesLabel();
                            updatePaginationState();
                        }
                    });

                    document.addEventListener('DOMContentLoaded', function () {
                        const urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.has('search')) {
                            document.getElementById('search').value = decodeURIComponent(urlParams.get('search'));
                        }
                        const today = new Date();
                        const thirtyDaysAgo = new Date();
                        thirtyDaysAgo.setDate(today.getDate() - 30);
                        document.getElementById('start_date').valueAsDate = thirtyDaysAgo;
                        document.getElementById('end_date').valueAsDate = today;

                        const searchInput = document.getElementById('search');
                        searchInput.addEventListener('input', debouncedSearch);
                        searchInput.addEventListener('keydown', function (e) {
                            if (e.key === 'Enter') { e.preventDefault(); updateLogFrame(); }
                        });

                        if (urlParams.has('file')) {
                            const fileSelect = document.getElementById('file');
                            const fileValue = urlParams.get('file');
                            for (let i = 0; i < fileSelect.options.length; i++) {
                                if (fileSelect.options[i].value === fileValue) {
                                    fileSelect.value = fileValue;
                                    updateLogFrame();
                                    break;
                                }
                            }
                        }
                    });

                    // Page input validation
                    document.getElementById('page').addEventListener('blur', function () {
                        const currentValue = parseInt(this.value);
                        if (isNaN(currentValue) || currentValue < 1) { this.value = 1; }
                        else if (currentValue > totalPages) { this.value = totalPages; }
                        updateLogFrame();
                    });

                    // Initial setup
                    updatePaginationState();
                    setupAutoUpdate();

                    const iframe = document.getElementById('logFrame');
                    iframe.onload = function () { iframe.style.opacity = 1; };
                    iframe.onerror = function () { console.error('Failed to load log content'); };

                    // Export via hidden iframe to force download
                    document.querySelectorAll('[data-export-format]').forEach(link => {
                        link.addEventListener('click', function (e) {
                            e.preventDefault();
                            const format = this.getAttribute('data-export-format');
                            const file = document.getElementById('file').value;
                            const downloadFrame = document.createElement('iframe');
                            downloadFrame.style.display = 'none';
                            downloadFrame.src = `${logBaseUrl}/export?file=${encodeURIComponent(file)}&format=${format}`;
                            document.body.appendChild(downloadFrame);
                            setTimeout(() => { document.body.removeChild(downloadFrame); }, 2000);
                        });
                    });
                })();
            </script>
        </div>
<?php
        return ob_get_clean();
    }
}
