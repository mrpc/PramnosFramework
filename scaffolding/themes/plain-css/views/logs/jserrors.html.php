<?php
/**
 * Browser errors (plain-CSS theme): what the application's pages hit in the browser, one row per
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
<div class="container">
    <h2>Browser Errors</h2>
    <?php $this->insert('_toolbar'); ?>

    <?php if ($this->notice): ?>
        <div role="status" class="alert alert-info"><?php echo $e($this->notice); ?></div>
    <?php elseif (empty($this->jsErrors)): ?>
        <p class="pf-muted">No errors reported. Pages report them when their head has <code>&lt;meta name="pf-client-errors"&gt;</code>.</p>
    <?php else: ?>
        <div class="pf-row-split">
            <span class="pf-muted"><?php echo (int) $this->total; ?> distinct errors</span>
            <?php echo \Pramnos\Html\Icon::postControl(adminUrl($route . '/clearjserrors'), 'Clear all', ['class' => 'btn btn-danger btn-sm', 'data-confirm' => 'Forget every browser error?']); ?>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Error</th><th class="text-right">Seen</th><th>First</th><th>Last</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($this->jsErrors as $row): ?>
                    <tr>
                        <td>
                            <div><code><?php echo $e($row['message'] ?? '' ?: $row['error']); ?></code></div>
                            <div class="pf-muted">
                                <?php echo $e(trim(($row['source'] ?? '') . (isset($row['line']) && $row['line'] !== null ? ':' . $row['line'] : ''))); ?>
                                <?php if (!empty($row['page'])): ?> · <?php echo $e($row['page']); ?><?php endif; ?>
                                <?php if (!empty($row['kind'])): ?> · <?php echo $e($row['kind']); ?><?php endif; ?>
                            </div>
                            <?php if (!empty($row['stack'])): ?>
                                <details><summary>Stack</summary><pre><?php echo $e($row['stack']); ?></pre></details>
                            <?php endif; ?>
                            <div class="pf-muted"><?php echo $e($row['agent']); ?></div>
                        </td>
                        <td class="text-right"><?php echo (int) $row['seentimes']; ?></td>
                        <td><?php echo $e($when($row['firstseen'])); ?></td>
                        <td><?php echo $e($when($row['lastseen'])); ?></td>
                        <td><?php echo \Pramnos\Html\Icon::postControl(adminUrl($route . '/deletejserror/' . rawurlencode((string) $row['idhash'])), 'Delete', ['class' => 'btn btn-link btn-sm']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($this->pages > 1): ?>
            <nav class="pf-pagination">
                <?php for ($p = 1; $p <= $this->pages; $p++): ?>
                    <?php if ($p === (int) $this->page): ?><span aria-current="page"><?php echo $p; ?></span>
                    <?php else: ?><a href="<?php echo $e(adminUrl($route . '/jserrors') . '?page=' . $p); ?>"><?php echo $p; ?></a><?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
