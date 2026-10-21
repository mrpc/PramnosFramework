<?php
/**
 * The rules (plain-CSS theme): each with its status, its trigger, and how it is doing.
 *
 * Variables:
 *   $this->rules — Rules::all(), each with trigger (in words), entered (seven days), running, goals
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$badges = ['draft' => '', 'shadow' => '', 'active' => '', 'paused' => ''];
?>
<div class="page-section">
    <?php $this->activeNav = 'rules'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px">
        <h2><?php echo $e(t('Rules')); ?></h2>
        <a href="<?php echo $e(adminUrl('Rules/edit')); ?>" class="btn btn-primary"><?php echo $e(t('+ New rule')); ?></a>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px;overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Status')); ?></th><th><?php echo $e(t('When')); ?></th><th style="text-align:right"><?php echo $e(t('Entered, 7 days')); ?></th><th style="text-align:right"><?php echo $e(t('Under way')); ?></th><th style="text-align:right"><?php echo $e(t('Goal reached')); ?></th></tr>
            </thead>
            <tbody>
            <?php foreach (($this->rules ?? []) as $rule): ?>
                <tr>
                    <td><a href="<?php echo $e(adminUrl('Rules/view/') . $rule['id']); ?>"><?php echo $e($rule['name']); ?></a> <span style="opacity:.7">v<?php echo (int) $rule['version']; ?></span></td>
                    <td><span class="badge"><?php echo $e(t($rule['status'])); ?></span></td>
                    <td><?php echo $e($rule['trigger']); ?></td>
                    <td style="text-align:right"><?php echo $e(number_format($rule['entered'])); ?></td>
                    <td style="text-align:right"><?php echo $e(number_format($rule['running'])); ?></td>
                    <td style="text-align:right"><?php echo $e(number_format($rule['goals'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->rules)): ?>
                <tr><td colspan="6" style="text-align:center;opacity:.7;padding:24px"><?php echo $e(t('No rules yet. A rule is when something happens, who it is for, what to do, and until when.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
