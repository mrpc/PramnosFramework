<?php
/**
 * A rule (Bootstrap theme): its boxes with how many went through each, its status, the tools to try
 * it, its versions and its log.
 *
 * Variables:
 *   $this->rule        — Rules::get()
 *   $this->canvas      — RuleCanvas::render() with the counts
 *   $this->versions    — Rules::versions()
 *   $this->log         — the newest rule_log rows
 *   $this->person      — the account asked about, or 0
 *   $this->why         — Tester::why() for it, or null
 *   $this->simulated   — Tester::simulate() with its days, or null
 *   $this->diff        — Tester::diff() with from and to, or null
 *   $this->actionSteps — the ids of the action steps, for a test send
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rule   = $this->rule;
$id     = (int) $rule['id'];
$token  = \Pramnos\Http\Session::getInstance()->getTokenField();
$status = static fn (string $to, string $label, string $class) => '<form method="post" action="' . $e(adminUrl('Rules/status/') . $id) . '">' . $token
    . '<input type="hidden" name="status" value="' . $e($to) . '"><button type="submit" class="btn btn-sm ' . $class . '">' . $e($label) . '</button></form>';
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'rules_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2><?php echo $e($rule['name']); ?> <span class="badge text-bg-secondary"><?php echo $e(t($rule['status'])); ?></span> <span class="text-muted fs-6">v<?php echo (int) $rule['version']; ?></span></h2>
        <div class="d-flex flex-wrap gap-2" data-field="actions">
            <a href="<?php echo $e(adminUrl('Rules/edit/') . $id); ?>" class="btn btn-sm btn-outline-secondary"><?php echo $e(t('Edit')); ?></a>
            <?php if ($rule['status'] !== 'shadow'): echo $status('shadow', t('Run in shadow'), 'btn-outline-info'); endif; ?>
            <?php if ($rule['status'] !== 'active'): echo $status('active', t('Switch on'), 'btn-success'); endif; ?>
            <?php if ($rule['status'] === 'active' || $rule['status'] === 'shadow'): echo $status('paused', t('Pause'), 'btn-outline-warning'); endif; ?>
            <?php if ($rule['status'] === 'draft' || $rule['status'] === 'paused'): ?>
                <form method="post" action="<?php echo $e(adminUrl('Rules/delete/') . $id); ?>" data-confirm="<?php echo $e(t('Delete this rule, its versions and its log?')); ?>"><?php echo $token; ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo $e(t('Delete')); ?></button></form>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem">
        <div class="card" style="grid-column:1/-1"><div class="card-body table-responsive"><?php echo $this->canvas; ?></div></div>
        <div style="display:grid;gap:1rem;align-content:start">
            <div class="card"><div class="card-body">
                <h3 class="h6 mb-2"><?php echo $e(t('Why would this person come in?')); ?></h3>
                <form method="get" action="<?php echo $e(adminUrl('Rules/view/') . $id); ?>" class="d-flex flex-wrap gap-2 mb-2">
                    <input type="number" min="1" name="person" class="form-control form-control-sm" style="width:8rem" placeholder="<?php echo $e(t('Account id')); ?>" value="<?php echo $this->person > 0 ? (int) $this->person : ''; ?>" aria-label="<?php echo $e(t('Account id')); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $e(t('Explain')); ?></button>
                </form>
                <?php if ($this->why !== null): ?>
                    <p class="fw-semibold" data-field="enters"><?php echo $e($this->why['enters'] ? t('Would come in.') : t('Would not come in: %s.', implode(', ', array_map('t', $this->why['reasons'])))); ?></p>
                    <ul class="list-unstyled small d-grid gap-1 mt-2">
                    <?php foreach ($this->why['who']['conditions'] as $c): if (isset($c['conditions'])) { continue; } $said = \Pramnos\Audience\Explained::condition($c); ?>
                        <li><span class="<?php echo $c['ok'] ? 'text-success' : 'text-danger'; ?>"><?php echo $c['ok'] ? '✓' : '✗'; ?></span> <?php echo $e($said['what'] . ' ' . $said['how'] . ($said['value'] !== null ? ' ' . $said['value'] : '')); ?> <span class="text-muted"><?php echo $e(t('has: %s', $said['actual'])); ?></span></li>
                    <?php endforeach; ?>
                    <?php foreach ($this->why['steps'] as $step => $outlook): if (isset($outlook['consent'])): ?>
                        <li><span class="<?php echo $outlook['consent'] ? 'text-success' : 'text-danger'; ?>"><?php echo $outlook['consent'] ? '✓' : '✗'; ?></span> <?php echo $e(t('%s: in the consent list', $step)); ?></li>
                    <?php endif; endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div></div>
            <div class="card"><div class="card-body">
                <h3 class="h6 mb-2"><?php echo $e(t('Had it been on')); ?></h3>
                <div class="d-flex gap-2 mb-2">
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo $e(adminUrl('Rules/view/') . $id . '?simulate=7'); ?>"><?php echo $e(t('Last 7 days')); ?></a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo $e(adminUrl('Rules/view/') . $id . '?simulate=30'); ?>"><?php echo $e(t('Last 30 days')); ?></a>
                </div>
                <?php if ($this->simulated !== null): ?>
                    <p data-field="simulated"><strong><?php echo $e(number_format($this->simulated['entered'])); ?></strong> <?php echo $e(t('would have come in, in %s days', (string) $this->simulated['days'])); ?></p>
                    <p class="small"><?php echo $e(implode(' · ', array_map(static fn ($c, $n): string => $c . ' ' . number_format($n), array_keys($this->simulated['messages']), $this->simulated['messages']))); ?></p>
                    <p class="small text-muted"><?php echo $e(t('Counted from %s, with who as it is now.', t($this->simulated['basis']))); ?></p>
                <?php endif; ?>
            </div></div>
            <?php if ($this->actionSteps !== []): ?>
            <div class="card"><div class="card-body">
                <h3 class="h6 mb-2"><?php echo $e(t('Send a test to me')); ?></h3>
                <form method="post" action="<?php echo $e(adminUrl('Rules/testsend/') . $id); ?>" class="d-flex flex-wrap gap-2"><?php echo $token; ?>
                    <select name="step" class="form-select form-select-sm" style="width:auto" aria-label="<?php echo $e(t('Step')); ?>"><?php foreach ($this->actionSteps as $step): ?><option value="<?php echo $e($step); ?>"><?php echo $e($step); ?></option><?php endforeach; ?></select>
                    <input type="number" min="1" name="person" required class="form-control form-control-sm" style="width:8rem" placeholder="<?php echo $e(t('with the details of')); ?>" aria-label="<?php echo $e(t('Account id')); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $e(t('Send')); ?></button>
                </form>
            </div></div>
            <?php endif; ?>
            <div class="card"><div class="card-body">
                <h3 class="h6 mb-2"><?php echo $e(t('Versions')); ?></h3>
                <ul class="list-unstyled small d-grid gap-1">
                <?php foreach ($this->versions as $i => $v): $older = $this->versions[$i + 1]['version'] ?? null; ?>
                    <li>v<?php echo (int) $v['version']; ?> · <?php echo $e(date('Y-m-d H:i', $v['at'])); ?>
                        <?php if ($older !== null): ?><a href="<?php echo $e(adminUrl('Rules/view/') . $id . '?diff=' . $older . ',' . $v['version']); ?>"><?php echo $e(t('compare with v%s', (string) $older)); ?></a><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
                <?php if ($this->diff !== null): ?>
                    <p class="mt-2" data-field="diff"><?php echo $e(t('v%s → v%s: %s now instead of %s; +%s in, −%s out.', (string) $this->diff['from'], (string) $this->diff['to'], number_format($this->diff['after']), number_format($this->diff['before']), number_format($this->diff['in']), number_format($this->diff['out']))); ?></p>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="card mt-3"><div class="card-body table-responsive">
        <h3 class="h6 mb-2"><?php echo $e(t('What it did')); ?></h3>
        <table class="table table-sm">
            <thead class="table-light"><tr><th><?php echo $e(t('When')); ?></th><th><?php echo $e(t('Person')); ?></th><th><?php echo $e(t('Step')); ?></th><th><?php echo $e(t('Outcome')); ?></th><th><?php echo $e(t('Detail')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($this->log as $row): ?>
                <tr>
                    <td><?php echo $e(date('Y-m-d H:i', (int) $row['at'])); ?></td>
                    <td><a href="<?php echo $e(\Pramnos\Html\AudienceBuilder::personUrl((int) $row['personid'])); ?>">#<?php echo (int) $row['personid']; ?></a></td>
                    <td><?php echo $e((string) ($row['step'] ?? '')); ?></td>
                    <td><?php echo $e(t((string) $row['outcome'])); ?></td>
                    <td class="text-muted"><?php echo $e(mb_strimwidth((string) ($row['detail'] ?? ''), 0, 140, '…')); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->log)): ?><tr><td colspan="5" class="text-center text-muted py-4"><?php echo $e(t('Nothing yet.')); ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
