<?php
/**
 * A rule: its status as a coloured pill and the states it can be put in as one control, its steps
 * with how many went through each, the tools to try it, its versions and its log. The same markup
 * in every theme.
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
use Pramnos\Html\Icon;

$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rule   = $this->rule;
$id     = (int) $rule['id'];
$labels = \Pramnos\Html\RuleCanvas::stepLabels($rule['definition']['steps'] ?? []);
$tones  = ['draft' => 'pf-tone-muted', 'shadow' => 'pf-tone-info', 'active' => 'pf-tone-ok', 'paused' => 'pf-tone-warn'];
$state  = static fn (string $to, string $label, string $icon) => '<form method="post" action="' . $e(adminUrl('Rules/status/') . $id) . '">' . \Pramnos\Http\Session::getInstance()->getTokenField()
    . '<input type="hidden" name="status" value="' . $e($to) . '"><button type="submit" aria-pressed="' . ($rule['status'] === $to ? 'true' : 'false') . '"'
    . ($rule['status'] === $to ? ' disabled' : '') . '>' . Icon::svg($icon, '') . $e($label) . '</button></form>';
$outcomeTones = ['entered' => 'pf-tone-accent', 'sent' => 'pf-tone-ok', 'done' => 'pf-tone-ok', 'goal' => 'pf-tone-ok', 'error' => 'pf-tone-bad',
    'skipped' => 'pf-tone-warn', 'waiting' => 'pf-tone-warn', 'shadow' => 'pf-tone-info', 'branch' => 'pf-tone-info'];
?>
<div class="pf-ui">
    <?php $this->activeNav = 'rules_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e($rule['name']); ?></h1>
            <p class="pf-page-sub">
                <span class="pf-badge pf-status <?php echo $tones[$rule['status']] ?? 'pf-tone-muted'; ?>" data-field="status"><?php echo $e(t($rule['status'])); ?></span>
                <span class="pf-muted"><?php echo $e(t('version %s', (string) $rule['version'])); ?></span>
            </p>
        </div>
        <div class="pf-page-actions" data-field="actions">
            <?php if ($rule['status'] === 'draft' || $rule['status'] === 'paused'): ?>
                <form method="post" action="<?php echo $e(adminUrl('Rules/delete/') . $id); ?>" class="pf-inline-form" data-confirm="<?php echo $e(t('Delete this rule, its versions and its log?')); ?>"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger"><?php echo $e(t('Delete')); ?></button></form>
            <?php endif; ?>
            <span class="pf-segmented" role="group" aria-label="<?php echo $e(t('Rule status')); ?>">
                <?php echo $state('shadow', t('Run in shadow'), 'shadow'); ?>
                <?php echo $state('active', t('Switch on'), 'play'); ?>
                <?php echo $state('paused', t('Pause'), 'pause'); ?>
            </span>
            <a href="<?php echo $e(adminUrl('Rules/edit/') . $id); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('edit', ''); ?><?php echo $e(t('Edit')); ?></a>
        </div>
    </header>

    <div class="pf-split">
        <div class="pf-card"><div class="pf-card-body pf-card-flush"><?php echo $this->canvas; ?></div></div>
        <div class="pf-stack">
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title"><?php echo $e(t('Why would this person come in?')); ?></h2>
                <form method="get" action="<?php echo $e(adminUrl('Rules/view/') . $id); ?>" class="pf-rule-sentence">
                    <input type="number" min="1" name="person" class="pf-input pf-input-id" placeholder="<?php echo $e(t('Account id')); ?>" value="<?php echo $this->person > 0 ? (int) $this->person : ''; ?>" aria-label="<?php echo $e(t('Account id')); ?>">
                    <button type="submit" class="pf-btn"><?php echo $e(t('Explain')); ?></button>
                </form>
                <?php if ($this->why !== null): ?>
                    <p class="pf-why-verdict" data-field="enters"><span class="pf-badge <?php echo $this->why['enters'] ? 'pf-tone-ok' : 'pf-tone-bad'; ?>"><?php echo $e($this->why['enters'] ? t('Would come in.') : t('Would not come in: %s.', implode(', ', array_map('t', $this->why['reasons'])))); ?></span></p>
                    <ul class="pf-why">
                    <?php foreach ($this->why['who']['conditions'] as $c): if (isset($c['conditions'])) { continue; } $said = \Pramnos\Audience\Explained::condition($c); ?>
                        <li><span class="pf-why-mark <?php echo $c['ok'] ? 'pf-up' : 'pf-down'; ?>"><?php echo $c['ok'] ? '✓' : '✗'; ?></span><span><?php echo $e($said['what'] . ' ' . $said['how'] . ($said['value'] !== null ? ' ' . $said['value'] : '')); ?> <span class="pf-muted">· <?php echo $e(t('has: %s', $said['actual'])); ?></span></span></li>
                    <?php endforeach; ?>
                    <?php foreach ($this->why['steps'] as $step => $outlook): if (isset($outlook['consent'])): ?>
                        <li><span class="pf-why-mark <?php echo $outlook['consent'] ? 'pf-up' : 'pf-down'; ?>"><?php echo $outlook['consent'] ? '✓' : '✗'; ?></span><span><?php echo $e(t('%s: in the consent list', $step)); ?></span></li>
                    <?php endif; endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div></div>
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title"><?php echo $e(t('Had it been on')); ?></h2>
                <span class="pf-segmented">
                    <a href="<?php echo $e(adminUrl('Rules/view/') . $id . '?simulate=7'); ?>"<?php echo ($this->simulated['days'] ?? 0) === 7 ? ' aria-current="true"' : ''; ?>><?php echo $e(t('Last 7 days')); ?></a>
                    <a href="<?php echo $e(adminUrl('Rules/view/') . $id . '?simulate=30'); ?>"<?php echo ($this->simulated['days'] ?? 0) === 30 ? ' aria-current="true"' : ''; ?>><?php echo $e(t('Last 30 days')); ?></a>
                </span>
                <?php if ($this->simulated !== null): ?>
                    <p class="pf-mt" data-field="simulated"><span class="pf-stat-value"><?php echo $e(number_format($this->simulated['entered'])); ?></span> <span class="pf-muted"><?php echo $e(t('would have come in, in %s days', (string) $this->simulated['days'])); ?></span></p>
                    <?php if ($this->simulated['messages'] !== []): ?><p><?php echo $e(implode(' · ', array_map(static fn ($c, $n): string => $c . ' ' . number_format($n), array_keys($this->simulated['messages']), $this->simulated['messages']))); ?></p><?php endif; ?>
                    <p class="pf-hint"><?php echo $e(t('Counted from %s, with who as it is now.', t($this->simulated['basis']))); ?></p>
                <?php endif; ?>
            </div></div>
            <?php if ($this->actionSteps !== []): ?>
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title"><?php echo $e(t('Send a test to me')); ?></h2>
                <form method="post" action="<?php echo $e(adminUrl('Rules/testsend/') . $id); ?>" class="pf-rule-sentence"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <select name="step" class="pf-select" aria-label="<?php echo $e(t('Step')); ?>"><?php foreach ($this->actionSteps as $step): ?><option value="<?php echo $e($step); ?>"><?php echo $e($labels[$step] ?? $step); ?></option><?php endforeach; ?></select>
                    <input type="number" min="1" name="person" required class="pf-input pf-input-id" placeholder="<?php echo $e(t('with the details of')); ?>" aria-label="<?php echo $e(t('Account id')); ?>">
                    <button type="submit" class="pf-btn"><?php echo Icon::svg('send', ''); ?><?php echo $e(t('Send')); ?></button>
                </form>
            </div></div>
            <?php endif; ?>
            <div class="pf-card"><div class="pf-card-body">
                <h2 class="pf-section-title"><?php echo $e(t('Versions')); ?></h2>
                <ul class="pf-why">
                <?php foreach ($this->versions as $i => $v): $older = $this->versions[$i + 1]['version'] ?? null; ?>
                    <li><span class="pf-badge <?php echo (int) $v['version'] === (int) $rule['version'] ? 'pf-tone-accent' : 'pf-tone-muted'; ?>">v<?php echo (int) $v['version']; ?></span>
                        <span class="pf-muted"><?php echo $e(date('Y-m-d H:i', $v['at'])); ?></span>
                        <?php if ($older !== null): ?><a href="<?php echo $e(adminUrl('Rules/view/') . $id . '?diff=' . $older . ',' . $v['version']); ?>"><?php echo $e(t('compare with v%s', (string) $older)); ?></a><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
                <?php if ($this->diff !== null): ?>
                    <p class="pf-mt" data-field="diff"><?php echo $e(t('v%s → v%s: %s now instead of %s; +%s in, −%s out.', (string) $this->diff['from'], (string) $this->diff['to'], number_format($this->diff['after']), number_format($this->diff['before']), number_format($this->diff['in']), number_format($this->diff['out']))); ?></p>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="pf-card pf-mt">
        <div class="pf-card-head"><h2 class="pf-section-title"><?php echo $e(t('What it did')); ?></h2></div>
        <div class="pf-card-flush">
            <?php if (empty($this->log)): ?>
                <div class="pf-empty"><p class="pf-empty-text"><?php echo $e(t('Nothing yet.')); ?></p></div>
            <?php else: ?>
            <table class="pf-table">
                <thead><tr><th><?php echo $e(t('When')); ?></th><th><?php echo $e(t('Person')); ?></th><th><?php echo $e(t('Step')); ?></th><th><?php echo $e(t('Outcome')); ?></th><th><?php echo $e(t('Detail')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($this->log as $row): ?>
                    <tr>
                        <td class="pf-muted"><?php echo $e(date('Y-m-d H:i', (int) $row['at'])); ?></td>
                        <td><a href="<?php echo $e(\Pramnos\Html\AudienceBuilder::personUrl((int) $row['personid'])); ?>">#<?php echo (int) $row['personid']; ?></a></td>
                        <td><?php echo $e($labels[(string) ($row['step'] ?? '')] ?? (string) ($row['step'] ?? '')); ?></td>
                        <td><span class="pf-badge <?php echo $outcomeTones[(string) $row['outcome']] ?? 'pf-tone-muted'; ?>"><?php echo $e(t((string) $row['outcome'])); ?></span></td>
                        <td class="pf-muted"><?php echo $e(mb_strimwidth((string) ($row['detail'] ?? ''), 0, 140, '…')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
