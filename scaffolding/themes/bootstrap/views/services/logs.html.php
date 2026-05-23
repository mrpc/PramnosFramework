<?php
/**
 * Service log tail (Bootstrap theme).
 *
 * Variables:
 *   $this->service — service entry array
 *   $this->lines   — string[] log lines
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?php echo sURL; ?>Services" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Logs — <?php echo htmlspecialchars($this->service['daemon'] ?? ''); ?></h2>
    </div>
    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <small class="text-muted">Worker: <?php echo htmlspecialchars($this->service['workerId'] ?? ''); ?></small>
            <small class="text-muted">Last 200 lines</small>
        </div>
        <div class="card-body p-0">
            <pre class="mb-0 p-3" style="background:#1e1e1e;color:#d4d4d4;font-size:0.8rem;max-height:600px;overflow-y:auto"><?php
                foreach (($this->lines ?? []) as $line) {
                    echo htmlspecialchars($line) . "\n";
                }
                if (empty($this->lines)) {
                    echo 'No log output found.';
                }
            ?></pre>
        </div>
    </div>
</div>
