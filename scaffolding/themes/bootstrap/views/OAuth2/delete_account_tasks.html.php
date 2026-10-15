<?php
/**
 * The scheduled AI tasks a leaving person has, organisation by organisation, and who takes them
 * over (Bootstrap theme) — inside the delete-account form, so the choice is sent with it.
 *
 * Variables: `$this->taskHandover` — from Routines::handoverChoices(): organization_id, name,
 * shared and personal (tasks: routine_id, title), successors (userid => name). Nothing is drawn
 * without any.
 *
 * Shared tasks — reports an organisation receives — are proposed for a successor; one's own go
 * with them only if ticked; with nobody to take them, they are deleted with the account.
 */
$c = ['box' => 'border rounded p-3 mb-4 small', 'h' => 'fw-semibold mb-2', 'muted' => 'text-body-secondary', 'select' => 'form-select form-select-sm mt-1', 'check' => 'form-check-input'];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$handover = is_array($this->taskHandover ?? null) ? $this->taskHandover : [];
?>
<?php foreach ($handover as $one):
    $key = $one['organization_id'] === null ? 'none' : (string) $one['organization_id']; ?>
<fieldset class="<?php echo $c['box']; ?>" data-pf-task-handover="<?php echo $e($key); ?>">
    <legend class="<?php echo $c['h']; ?>"><?php echo $e($one['organization_id'] === null ? $t('Your scheduled tasks') : sprintf($t('Your scheduled tasks in %s'), (string) ($one['name'] ?? ''))); ?></legend>
    <p class="<?php echo $c['muted']; ?>"><?php echo $e(implode(', ', array_map(static fn (array $task): string => (string) $task['title'], [...$one['shared'], ...$one['personal']]))); ?></p>
    <?php if ($one['successors'] === []): ?>
    <p><?php echo $e($t('Nobody else can take them over: they are deleted with your account.')); ?></p>
    <?php else: ?>
    <label><?php echo $e($t('Hand them to')); ?>
        <select class="<?php echo $c['select']; ?>" name="tasks[<?php echo $e($key); ?>][to]">
            <?php foreach ($one['successors'] as $userId => $name): ?><option value="<?php echo (int) $userId; ?>"><?php echo $e($name); ?></option><?php endforeach; ?>
            <option value=""<?php echo $one['shared'] === [] ? ' selected' : ''; ?>><?php echo $e($t('Nobody — delete them')); ?></option>
        </select>
    </label>
    <?php if ($one['personal'] !== []): ?>
    <label><input type="checkbox" class="<?php echo $c['check']; ?>" name="tasks[<?php echo $e($key); ?>][personal]" value="1"> <?php echo $e($t('My own tasks too, not only the ones shared')); ?></label>
    <?php endif; ?>
    <?php endif; ?>
</fieldset>
<?php endforeach; ?>
