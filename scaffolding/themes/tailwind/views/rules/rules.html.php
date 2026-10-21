<?php
/**
 * The rules (Tailwind theme): each with its status, its trigger, and how it is doing.
 *
 * Variables:
 *   $this->rules — Rules::all(), each with trigger (in words), entered (seven days), running, goals
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$badges = ['draft' => 'badge-ghost', 'shadow' => 'badge-info', 'active' => 'badge-success', 'paused' => 'badge-warning'];
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'rules'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h2><?php echo $e(t('Rules')); ?></h2>
        <a href="<?php echo $e(adminUrl('Rules/edit')); ?>" class="btn btn-primary btn-sm"><?php echo $e(t('+ New rule')); ?></a>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5 overflow-x-auto">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Status')); ?></th><th><?php echo $e(t('When')); ?></th><th class="text-right"><?php echo $e(t('Entered, 7 days')); ?></th><th class="text-right"><?php echo $e(t('Under way')); ?></th><th class="text-right"><?php echo $e(t('Goal reached')); ?></th></tr>
            </thead>
            <tbody>
            <?php foreach (($this->rules ?? []) as $rule): ?>
                <tr>
                    <td><a href="<?php echo $e(adminUrl('Rules/view/') . $rule['id']); ?>"><?php echo $e($rule['name']); ?></a> <span class="text-base-content/60">v<?php echo (int) $rule['version']; ?></span></td>
                    <td><span class="badge badge-sm <?php echo $badges[$rule['status']] ?? 'badge-ghost'; ?>"><?php echo $e(t($rule['status'])); ?></span></td>
                    <td><?php echo $e($rule['trigger']); ?></td>
                    <td class="text-right"><?php echo $e(number_format($rule['entered'])); ?></td>
                    <td class="text-right"><?php echo $e(number_format($rule['running'])); ?></td>
                    <td class="text-right"><?php echo $e(number_format($rule['goals'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->rules)): ?>
                <tr><td colspan="6" class="text-center text-base-content/60 py-6"><?php echo $e(t('No rules yet. A rule is when something happens, who it is for, what to do, and until when.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
