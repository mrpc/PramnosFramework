<?php
/**
 * An A/B test's results (plain-CSS theme): per variant, the people, how many reached the goal, the
 * rate, how sure the difference from the first variant is, and how many more it needs; and the
 * buttons to start, stop and make a winner.
 *
 * Variables:
 *   $this->test    — Experiments::get()
 *   $this->results — Experiments::results()
 *   $this->segment — the name of the segment it is limited to, or null
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$test  = $this->test;
$id    = (int) $test['id'];
$post  = static fn (string $action, string $label, string $class, array $fields = []) => '<form method="post" action="' . $e(adminUrl('Experiments/' . $action . '/') . $id) . '">' . \Pramnos\Http\Session::getInstance()->getTokenField()
    . implode('', array_map(static fn ($k, $v): string => '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">', array_keys($fields), $fields))
    . '<button type="submit" class="btn ' . $class . '">' . $e($label) . '</button></form>';
$percent = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 1) . '%';
?>
<div class="page-section">
    <?php $this->activeNav = 'experiments_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">
        <h2><?php echo $e($test['name']); ?> <code><?php echo $e($test['slug']); ?></code></h2>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
            <?php if ($test['status'] === 'draft'): ?>
                <a href="<?php echo $e(adminUrl('Experiments/edit/') . $id); ?>" class="btn btn-secondary"><?php echo $e(t('Edit')); ?></a>
                <?php echo $post('start', t('Start'), 'btn-primary'); ?>
            <?php elseif ($test['status'] === 'running'): ?>
                <?php echo $post('stop', t('Stop without a winner'), 'btn-secondary'); ?>
            <?php endif; ?>
        </div>
    </div>
    <p style="margin-bottom:16px;opacity:.8" data-field="about">
        <?php echo $e($test['winner'] !== null ? t('Decided: everybody gets %s.', $test['winner']) : t($test['status'])); ?> ·
        <?php echo $e($this->segment === null ? t('Everybody, visitors too') : t('People in %s', $this->segment)); ?> ·
        <?php echo $e($test['goalEvent'] === null ? t('No goal') : t('Goal: %s within %s days', \Pramnos\Audience\PersonEvents::label($test['goalEvent']), (string) $test['goalDays'])); ?>
    </p>
    <?php if (!$this->results['counted'] && $test['goalEvent'] !== null): ?>
        <p data-field="uncounted"><?php echo $e(t('The goal is not counted here: each occurrence is read from the change log — enable the changelog feature.')); ?></p>
    <?php endif; ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px;overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr><th><?php echo $e(t('Variant')); ?></th><th style="text-align:right"><?php echo $e(t('People')); ?></th><th style="text-align:right"><?php echo $e(t('Reached the goal')); ?></th><th style="text-align:right"><?php echo $e(t('Rate')); ?></th><th style="text-align:right"><?php echo $e(t('How sure')); ?></th><th style="text-align:right"><?php echo $e(t('Still needed')); ?></th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($this->results['variants'] as $i => $v): ?>
                <tr data-variant="<?php echo $e($v['key']); ?>">
                    <td><strong><?php echo $e($v['key']); ?></strong> · <?php echo $e($v['label']); ?> <span style="opacity:.7"><?php echo (int) $v['share']; ?>%</span></td>
                    <td style="text-align:right"><?php echo $e(number_format($v['people'])); ?></td>
                    <td style="text-align:right"><?php echo $e(number_format($v['converted'])); ?></td>
                    <td style="text-align:right"><?php echo $e($percent($v['rate'])); ?></td>
                    <td style="text-align:right"><?php echo $i === 0 ? $e(t('the baseline')) : $e($percent($v['confidence'])); ?></td>
                    <td style="text-align:right"><?php echo $v['needed'] === null ? '—' : $e(number_format($v['needed'])); ?></td>
                    <td style="text-align:right"><?php echo $test['winner'] === null && $test['status'] !== 'draft' ? $post('decide', t('Make it the winner'), 'btn-secondary', ['variant' => $v['key']]) : ($test['winner'] === $v['key'] ? '✓' : ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:.8rem;opacity:.7;margin-top:8px"><?php echo $e(t('How sure is the chance the difference from the first variant is not luck; at 95% or more it can be trusted. Still needed is how many more people each variant needs for the difference seen to be told. %s visitors have not signed up and are not counted.', number_format($this->results['visitors']))); ?></p>
    </div></div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px"><div class="card-body" style="padding:16px">
        <h3 style="margin:0 0 8px"><?php echo $e(t('In the code')); ?></h3>
        <pre style="overflow-x:auto"><code>if (\Pramnos\Audience\Experiments::variant('<?php echo $e($test['slug']); ?>') === '<?php echo $e($test['variants'][1]['key'] ?? 'B'); ?>') { … }</code></pre>
    </div></div>
</div>
