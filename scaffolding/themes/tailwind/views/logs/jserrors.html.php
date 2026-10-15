<?php
/**
 * Browser errors (Tailwind theme): what the application's pages hit in the browser, one row per
 * distinct error, most recently seen first.
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->logsRoute — the address the log screens answer under (`Logs`)
 *   $this->jsErrors    — array[] {idhash, error, message, kind, source, line, col, stack, page,
 *                                agent, release, seentimes, firstseen, lastseen}
 *   $this->total, $this->page, $this->pages — paging
 *   $this->notice    — string|null  why there is nothing to list
 */
$route  = $this->logsRoute ?? 'Logs';
$when   = static fn ($t): string => $t ? date('d/m/Y H:i', (int) $t) : '';
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="container mx-auto px-4 py-6">
    <h2 class="text-2xl font-semibold mb-4">Browser Errors</h2>
    <?php $this->insert('_toolbar'); ?>

    <?php if ($this->notice): ?>
        <div role="status" class="alert alert-info mb-4"><?php echo $e($this->notice); ?></div>
    <?php elseif (empty($this->jsErrors)): ?>
        <p class="text-base-content/70">No errors reported. Pages report them when their head has <code>&lt;meta name="pf-client-errors"&gt;</code>.</p>
    <?php else: ?>
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm text-base-content/70"><?php echo (int) $this->total; ?> distinct errors</span>
            <?php echo \Pramnos\Html\Icon::postControl(adminUrl($route . '/clearjserrors'), 'Clear all', ['class' => 'btn btn-error btn-sm', 'data-confirm' => 'Forget every browser error?']); ?>
        </div>
        <div class="overflow-x-auto card bg-base-100 border border-base-300">
            <table class="table table-sm">
                <thead><tr><th>Error</th><th class="text-right">Seen</th><th>First</th><th>Last</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($this->jsErrors as $row): ?>
                    <tr class="align-top">
                        <td class="max-w-xl">
                            <div class="font-mono text-sm break-words"><?php echo $e($row['message'] ?? '' ?: $row['error']); ?></div>
                            <div class="text-xs text-base-content/60 break-all">
                                <?php echo $e(trim(($row['source'] ?? '') . (isset($row['line']) && $row['line'] !== null ? ':' . $row['line'] : ''))); ?>
                                <?php if (!empty($row['page'])): ?> · <?php echo $e($row['page']); ?><?php endif; ?>
                                <?php if (!empty($row['kind'])): ?> · <?php echo $e($row['kind']); ?><?php endif; ?>
                            </div>
                            <?php if (!empty($row['stack'])): ?>
                                <details class="mt-1"><summary class="cursor-pointer text-xs">Stack</summary><pre class="text-xs whitespace-pre-wrap"><?php echo $e($row['stack']); ?></pre></details>
                            <?php endif; ?>
                            <div class="text-xs text-base-content/50 break-all"><?php echo $e($row['agent']); ?></div>
                        </td>
                        <td class="text-right font-mono"><?php echo (int) $row['seentimes']; ?></td>
                        <td class="whitespace-nowrap text-sm"><?php echo $e($when($row['firstseen'])); ?></td>
                        <td class="whitespace-nowrap text-sm"><?php echo $e($when($row['lastseen'])); ?></td>
                        <td><?php echo \Pramnos\Html\Icon::postControl(adminUrl($route . '/deletejserror/' . rawurlencode((string) $row['idhash'])), 'Delete', ['class' => 'btn btn-ghost btn-xs text-error']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($this->pages > 1): ?>
            <div class="join mt-4">
                <?php for ($p = 1; $p <= $this->pages; $p++): ?>
                    <a class="join-item btn btn-sm<?php echo $p === (int) $this->page ? ' btn-active' : ''; ?>" href="<?php echo $e(adminUrl($route . '/jserrors') . '?page=' . $p); ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
