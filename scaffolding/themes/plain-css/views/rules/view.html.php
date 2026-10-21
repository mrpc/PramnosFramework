<?php
/**
 * A rule (plain-CSS theme): its boxes with how many went through each, its status, the tools to try
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
    . '<input type="hidden" name="status" value="' . $e($to) . '"><button type="submit" class="btn ' . $class . '">' . $e($label) . '</button></form>';
?>
<div class="page-section">
    <?php $this->activeNav = 'rules_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px">
        <h2><?php echo $e($rule['name']); ?> <span class="badge"><?php echo $e(t($rule['status'])); ?></span> <span style="opacity:.7;font-size:1rem">v<?php echo (int) $rule['version']; ?></span></h2>
        <div style="display:flex;flex-wrap:wrap;gap:8px" data-field="actions">
            <a href="<?php echo $e(adminUrl('Rules/edit/') . $id); ?>" class="btn btn-secondary"><?php echo $e(t('Edit')); ?></a>
            <?php if ($rule['status'] !== 'shadow'): echo $status('shadow', t('Run in shadow'), 'btn-secondary'); endif; ?>
            <?php if ($rule['status'] !== 'active'): echo $status('active', t('Switch on'), 'btn-primary'); endif; ?>
            <?php if ($rule['status'] === 'active' || $rule['status'] === 'shadow'): echo $status('paused', t('Pause'), 'btn-secondary'); endif; ?>
            <?php if ($rule['status'] === 'draft' || $rule['status'] === 'paused'): ?>
                <form method="post" action="<?php echo $e(adminUrl('Rules/delete/') . $id); ?>" data-confirm="<?php echo $e(t('Delete this rule, its versions and its log?')); ?>"><?php echo $token; ?>
                    <button type="submit" class="btn btn-danger"><?php echo $e(t('Delete')); ?></button></form>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem">
        <div class="card" style="grid-column:1/-1"><div class="card-body" style="padding:16px;overflow-x:auto"><?php echo $this->canvas; ?></div></div>
        <div style="display:grid;gap:1rem;align-content:start">
            <div class="card" style="border:1px solid #ddd;border-radius:4px"><div class="card-body" style="padding:16px">
                <h3 style="margin:0 0 8px"><?php echo $e(t('Why would this person come in?')); ?></h3>
                <form method="get" action="<?php echo $e(adminUrl('Rules/view/') . $id); ?>" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                    <input type="number" min="1" name="person" style="width:8rem;padding:6px;border:1px solid #ccc;border-radius:4px" placeholder="<?php echo $e(t('Account id')); ?>" value="<?php echo $this->person > 0 ? (int) $this->person : ''; ?>" aria-label="<?php echo $e(t('Account id')); ?>">
                    <button type="submit" class="btn btn-secondary"><?php echo $e(t('Explain')); ?></button>
                </form>
                <?php if ($this->why !== null): ?>
                    <p style="font-weight:600" data-field="enters"><?php echo $e($this->why['enters'] ? t('Would come in.') : t('Would not come in: %s.', implode(', ', array_map('t', $this->why['reasons'])))); ?></p>
                    <ul style="list-style:none;padding:0;margin:8px 0 0;display:grid;gap:4px;font-size:.9rem">
                    <?php foreach ($this->why['who']['conditions'] as $c): if (isset($c['conditions'])) { continue; } $said = \Pramnos\Audience\Explained::condition($c); ?>
                        <li><span style="color:<?php echo $c['ok'] ? '#15803d' : '#b91c1c'; ?>"><?php echo $c['ok'] ? '✓' : '✗'; ?></span> <?php echo $e($said['what'] . ' ' . $said['how'] . ($said['value'] !== null ? ' ' . $said['value'] : '')); ?> <span style="opacity:.7"><?php echo $e(t('has: %s', $said['actual'])); ?></span></li>
                    <?php endforeach; ?>
                    <?php foreach ($this->why['steps'] as $step => $outlook): if (isset($outlook['consent'])): ?>
                        <li><span style="color:<?php echo $outlook['consent'] ? '#15803d' : '#b91c1c'; ?>"><?php echo $outlook['consent'] ? '✓' : '✗'; ?></span> <?php echo $e(t('%s: in the consent list', $step)); ?></li>
                    <?php endif; endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div></div>
            <div class="card" style="border:1px solid #ddd;border-radius:4px"><div class="card-body" style="padding:16px">
                <h3 style="margin:0 0 8px"><?php echo $e(t('Had it been on')); ?></h3>
                <div style="display:flex;gap:8px;margin-bottom:8px">
                    <a class="btn btn-secondary" href="<?php echo $e(adminUrl('Rules/view/') . $id . '?simulate=7'); ?>"><?php echo $e(t('Last 7 days')); ?></a>
                    <a class="btn btn-secondary" href="<?php echo $e(adminUrl('Rules/view/') . $id . '?simulate=30'); ?>"><?php echo $e(t('Last 30 days')); ?></a>
                </div>
                <?php if ($this->simulated !== null): ?>
                    <p data-field="simulated"><strong><?php echo $e(number_format($this->simulated['entered'])); ?></strong> <?php echo $e(t('would have come in, in %s days', (string) $this->simulated['days'])); ?></p>
                    <p style="font-size:.9rem"><?php echo $e(implode(' · ', array_map(static fn ($c, $n): string => $c . ' ' . number_format($n), array_keys($this->simulated['messages']), $this->simulated['messages']))); ?></p>
                    <p style="font-size:.8rem;opacity:.7"><?php echo $e(t('Counted from %s, with who as it is now.', t($this->simulated['basis']))); ?></p>
                <?php endif; ?>
            </div></div>
            <?php if ($this->actionSteps !== []): ?>
            <div class="card" style="border:1px solid #ddd;border-radius:4px"><div class="card-body" style="padding:16px">
                <h3 style="margin:0 0 8px"><?php echo $e(t('Send a test to me')); ?></h3>
                <form method="post" action="<?php echo $e(adminUrl('Rules/testsend/') . $id); ?>" style="display:flex;flex-wrap:wrap;gap:8px"><?php echo $token; ?>
                    <select name="step" style="padding:6px;border:1px solid #ccc;border-radius:4px" aria-label="<?php echo $e(t('Step')); ?>"><?php foreach ($this->actionSteps as $step): ?><option value="<?php echo $e($step); ?>"><?php echo $e($step); ?></option><?php endforeach; ?></select>
                    <input type="number" min="1" name="person" required style="width:8rem;padding:6px;border:1px solid #ccc;border-radius:4px" placeholder="<?php echo $e(t('with the details of')); ?>" aria-label="<?php echo $e(t('Account id')); ?>">
                    <button type="submit" class="btn btn-secondary"><?php echo $e(t('Send')); ?></button>
                </form>
            </div></div>
            <?php endif; ?>
            <div class="card" style="border:1px solid #ddd;border-radius:4px"><div class="card-body" style="padding:16px">
                <h3 style="margin:0 0 8px"><?php echo $e(t('Versions')); ?></h3>
                <ul style="list-style:none;padding:0;margin:0;display:grid;gap:4px;font-size:.9rem">
                <?php foreach ($this->versions as $i => $v): $older = $this->versions[$i + 1]['version'] ?? null; ?>
                    <li>v<?php echo (int) $v['version']; ?> · <?php echo $e(date('Y-m-d H:i', $v['at'])); ?>
                        <?php if ($older !== null): ?><a href="<?php echo $e(adminUrl('Rules/view/') . $id . '?diff=' . $older . ',' . $v['version']); ?>"><?php echo $e(t('compare with v%s', (string) $older)); ?></a><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
                <?php if ($this->diff !== null): ?>
                    <p style="margin-top:8px" data-field="diff"><?php echo $e(t('v%s → v%s: %s now instead of %s; +%s in, −%s out.', (string) $this->diff['from'], (string) $this->diff['to'], number_format($this->diff['after']), number_format($this->diff['before']), number_format($this->diff['in']), number_format($this->diff['out']))); ?></p>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-top:16px"><div class="card-body" style="padding:16px;overflow-x:auto">
        <h3 style="margin:0 0 8px"><?php echo $e(t('What it did')); ?></h3>
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead><tr><th><?php echo $e(t('When')); ?></th><th><?php echo $e(t('Person')); ?></th><th><?php echo $e(t('Step')); ?></th><th><?php echo $e(t('Outcome')); ?></th><th><?php echo $e(t('Detail')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($this->log as $row): ?>
                <tr>
                    <td><?php echo $e(date('Y-m-d H:i', (int) $row['at'])); ?></td>
                    <td><a href="<?php echo $e(\Pramnos\Html\AudienceBuilder::personUrl((int) $row['personid'])); ?>">#<?php echo (int) $row['personid']; ?></a></td>
                    <td><?php echo $e((string) ($row['step'] ?? '')); ?></td>
                    <td><?php echo $e(t((string) $row['outcome'])); ?></td>
                    <td style="opacity:.8"><?php echo $e(mb_strimwidth((string) ($row['detail'] ?? ''), 0, 140, '…')); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->log)): ?><tr><td colspan="5" style="text-align:center;opacity:.7;padding:24px"><?php echo $e(t('Nothing yet.')); ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
