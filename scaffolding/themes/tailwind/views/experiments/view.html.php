<?php
/**
 * An A/B test's results (Tailwind theme): per variant, the people, how many reached the goal, the
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
    . '<button type="submit" class="btn btn-sm ' . $class . '">' . $e($label) . '</button></form>';
$percent = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 1) . '%';
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'experiments_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex flex-wrap justify-between items-center gap-2 mb-2">
        <h2><?php echo $e($test['name']); ?> <code class="text-sm"><?php echo $e($test['slug']); ?></code></h2>
        <div class="flex flex-wrap gap-2">
            <?php if ($test['status'] === 'draft'): ?>
                <a href="<?php echo $e(adminUrl('Experiments/edit/') . $id); ?>" class="btn btn-sm"><?php echo $e(t('Edit')); ?></a>
                <?php echo $post('start', t('Start'), 'btn-success'); ?>
            <?php elseif ($test['status'] === 'running'): ?>
                <?php echo $post('stop', t('Stop without a winner'), 'btn-warning btn-outline'); ?>
            <?php endif; ?>
        </div>
    </div>
    <p class="mb-4 text-base-content/70" data-field="about">
        <?php echo $e($test['winner'] !== null ? t('Decided: everybody gets %s.', $test['winner']) : t($test['status'])); ?> ·
        <?php echo $e($this->segment === null ? t('Everybody, visitors too') : t('People in %s', $this->segment)); ?> ·
        <?php echo $e($test['goalEvent'] === null ? t('No goal') : t('Goal: %s within %s days', \Pramnos\Audience\PersonEvents::label($test['goalEvent']), (string) $test['goalDays'])); ?>
    </p>
    <?php if (!$this->results['counted'] && $test['goalEvent'] !== null): ?>
        <p data-field="uncounted"><?php echo $e(t('The goal is not counted here: each occurrence is read from the change log — enable the changelog feature.')); ?></p>
    <?php endif; ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5 overflow-x-auto">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                <tr><th><?php echo $e(t('Variant')); ?></th><th class="text-right"><?php echo $e(t('People')); ?></th><th class="text-right"><?php echo $e(t('Reached the goal')); ?></th><th class="text-right"><?php echo $e(t('Rate')); ?></th><th class="text-right"><?php echo $e(t('How sure')); ?></th><th class="text-right"><?php echo $e(t('Still needed')); ?></th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($this->results['variants'] as $i => $v): ?>
                <tr data-variant="<?php echo $e($v['key']); ?>">
                    <td><strong><?php echo $e($v['key']); ?></strong> · <?php echo $e($v['label']); ?> <span class="text-base-content/60"><?php echo (int) $v['share']; ?>%</span></td>
                    <td class="text-right"><?php echo $e(number_format($v['people'])); ?></td>
                    <td class="text-right"><?php echo $e(number_format($v['converted'])); ?></td>
                    <td class="text-right"><?php echo $e($percent($v['rate'])); ?></td>
                    <td class="text-right"><?php echo $i === 0 ? $e(t('the baseline')) : $e($percent($v['confidence'])); ?></td>
                    <td class="text-right"><?php echo $v['needed'] === null ? '—' : $e(number_format($v['needed'])); ?></td>
                    <td class="text-right"><?php echo $test['winner'] === null && $test['status'] !== 'draft' ? $post('decide', t('Make it the winner'), 'btn-outline', ['variant' => $v['key']]) : ($test['winner'] === $v['key'] ? '✓' : ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-base-content/60 mt-2"><?php echo $e(t('How sure is the chance the difference from the first variant is not luck; at 95% or more it can be trusted. Still needed is how many more people each variant needs for the difference seen to be told. %s visitors have not signed up and are not counted.', number_format($this->results['visitors']))); ?></p>
    </div></div>
    <div class="card bg-base-100 border border-base-300 shadow-xs"><div class="p-5">
        <h3 class="font-semibold mb-2"><?php echo $e(t('In the code')); ?></h3>
        <pre class="text-sm overflow-x-auto"><code>if (\Pramnos\Audience\Experiments::variant('<?php echo $e($test['slug']); ?>') === '<?php echo $e($test['variants'][1]['key'] ?? 'B'); ?>') { … }</code></pre>
    </div></div>
</div>
