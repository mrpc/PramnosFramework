<?php
/**
 * Service log tail (plain-CSS theme).
 *
 * Variables:
 *   $this->service — service entry array
 *   $this->lines   — string[] log lines
 */
?>
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="<?php echo sURL; ?>Services" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 >Logs — <?php echo htmlspecialchars($this->service['daemon'] ?? ''); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;justify-content:space-between">
            <small style="color:#888">Worker: <?php echo htmlspecialchars($this->service['workerId'] ?? ''); ?></small>
            <small style="color:#888">Last 200 lines</small>
        </div>
        <div class="card-body" style="padding:16px" style="padding:0">
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
