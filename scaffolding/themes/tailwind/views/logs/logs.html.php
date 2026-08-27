<?php
/**
 * Log viewer — file listing (Tailwind theme). Default `display` action view.
 *
 * Variables:
 *   $this->toolbar    — array[] toolbar links (rendered by _toolbar partial)
 *   $this->clearList  — string[] files "Clear Logs" wipes
 *   $this->viewerHtml — pre-rendered, self-contained log-viewer iframe HTML
 *                       (LogViewer::renderViewer(); already CSP-safe — echoed raw)
 */
?>
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-base-content mb-4">Log Files</h2>
    <?php $this->insert('_toolbar'); ?>
    <div class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
        <?php echo $this->viewerHtml ?? ''; ?>
    </div>
</div>
