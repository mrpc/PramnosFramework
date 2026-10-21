<?php
/**
 * The rules: each with its status, its trigger, and how it is doing over the week. The same markup
 * in every theme.
 *
 * Variables:
 *   $this->rules — Rules::all(), each with trigger (in words), entered (seven days), running, goals
 */
use Pramnos\Html\Icon;

$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$tones = ['draft' => 'pf-tone-muted', 'shadow' => 'pf-tone-info', 'active' => 'pf-tone-ok', 'paused' => 'pf-tone-warn'];
?>
<div class="pf-ui">
    <?php $this->activeNav = 'rules'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e(t('Rules')); ?></h1>
        </div>
        <div class="pf-page-actions">
            <a href="<?php echo $e(adminUrl('Rules/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New rule')); ?></a>
        </div>
    </header>
    <?php if (!empty($this->rules)): ?>
        <div class="pf-toolbar">
            <span></span>
            <label class="pf-search">
                <?php echo Icon::svg('search', ''); ?>
                <input type="search" class="pf-input" data-pf-filter-rows="#pf-rules-table" placeholder="<?php echo $e(t('Search by name')); ?>" aria-label="<?php echo $e(t('Search by name')); ?>">
            </label>
        </div>
    <?php endif; ?>
    <div class="pf-card pf-card-flush">
        <?php if (empty($this->rules)): ?>
            <div class="pf-empty">
                <span class="pf-empty-icon"><?php echo Icon::svg('rule', ''); ?></span>
                <p class="pf-empty-title"><?php echo $e(t('No rules yet')); ?></p>
                <p class="pf-empty-text"><?php echo $e(t('A rule is when something happens, who it is for, what to do, and until when: a welcome series, a reminder, a follow-up.')); ?></p>
                <a href="<?php echo $e(adminUrl('Rules/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New rule')); ?></a>
            </div>
        <?php else: ?>
            <table class="pf-table" id="pf-rules-table">
                <thead>
                    <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Rule status')); ?></th><th><?php echo $e(t('When')); ?></th><th class="pf-num"><?php echo $e(t('Entered, 7 days')); ?></th><th class="pf-num"><?php echo $e(t('Under way')); ?></th><th class="pf-num"><?php echo $e(t('Goal reached')); ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($this->rules as $rule): ?>
                    <tr data-pf-row-text="<?php echo $e(mb_strtolower($rule['name'])); ?>">
                        <td>
                            <a class="pf-row-link" href="<?php echo $e(adminUrl('Rules/view/') . $rule['id']); ?>"><?php echo $e($rule['name']); ?></a>
                            <span class="pf-row-desc"><?php echo $e(t('version %s', (string) $rule['version'])); ?></span>
                        </td>
                        <td><span class="pf-badge pf-status <?php echo $tones[$rule['status']] ?? 'pf-tone-muted'; ?>"><?php echo $e(t($rule['status'])); ?></span></td>
                        <td><?php echo $e($rule['trigger']); ?></td>
                        <td class="pf-num"><?php echo $e(number_format($rule['entered'])); ?></td>
                        <td class="pf-num"><?php echo $e(number_format($rule['running'])); ?></td>
                        <td class="pf-num"><?php echo $e(number_format($rule['goals'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
