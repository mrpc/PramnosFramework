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
        <a href="<?php echo sURL; ?>Services" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">&larr; Back</a>
        <h2 >Logs — <?php echo htmlspecialchars($this->service['daemon'] ?? ''); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex justify-between text-sm font-medium">
            <small class="text-gray-500">Worker: <?php echo htmlspecialchars($this->service['workerId'] ?? ''); ?></small>
            <small class="text-gray-500">Last 200 lines</small>
        </div>
        <div >
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
