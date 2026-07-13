<?php
/**
 * Log viewer — file listing (Bootstrap theme). Default `display` action view.
 *
 * Variables:
 *   $this->toolbar    — array[] toolbar links (rendered by _toolbar partial)
 *   $this->clearList  — string[] files "Clear Logs" wipes
 *   $this->viewerHtml — pre-rendered, self-contained log-viewer iframe HTML
 *                       (LogViewer::renderViewer(); already CSP-safe — echoed raw)
 */
?>
<div class="container py-4">
    <h2 class="mb-4">Log Files</h2>
    <?php $this->insert('_toolbar'); ?>
    <div class="card shadow-sm overflow-hidden">
        <?php echo $this->viewerHtml ?? ''; ?>
    </div>
</div>
