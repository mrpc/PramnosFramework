<?php
/**
 * Log viewer — file listing (plain-CSS theme). Default `display` action view.
 *
 * Variables:
 *   $this->toolbar    — array[] toolbar links (rendered by _toolbar partial)
 *   $this->clearList  — string[] files "Clear Logs" wipes
 *   $this->viewerHtml — pre-rendered, self-contained log-viewer iframe HTML
 *                       (LogViewer::renderViewer(); already CSP-safe — echoed raw)
 */
?>
<div class="page-section">
    <h2 style="margin:0 0 16px">Log Files</h2>
    <?php $this->insert('_toolbar'); ?>
    <div class="card" style="overflow:hidden">
        <?php echo $this->viewerHtml ?? ''; ?>
    </div>
</div>
