<?php
/**
 * Service log tail (Tailwind theme).
 *
 * Variables:
 *   $this->service — service entry array
 *   $this->lines   — string[] log lines
 */
?>
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('Services'); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 >Logs — <?php echo htmlspecialchars($this->service['daemon'] ?? ''); ?></h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 flex justify-between text-sm font-medium">
            <small class="text-base-content/70">Worker: <?php echo htmlspecialchars($this->service['workerId'] ?? ''); ?></small>
            <small class="text-base-content/70">Last 200 lines</small>
        </div>
        <div >
            <?php /* A log pane is a terminal, and a terminal is dark in either theme —
         this is the one place a literal is the right answer. */ ?>
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
