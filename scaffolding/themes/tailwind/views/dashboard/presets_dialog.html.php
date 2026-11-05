<?php
/**
 * The presets of a board (tailwind theme): apply one, save the board as one and say who sees it,
 * delete your own; an administrator also makes one the default for a role and copies their board
 * to somebody. Plain forms, each posting with the session's token and coming back to the page.
 *
 * Variables (from admin_dashboard.html.php's view): $this->presets, $this->presetUrls,
 * $this->managesPresets, $this->roles, $this->csrfField.
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$urls  = (array) ($this->presetUrls ?? []);
$field = (string) ($this->csrfField ?? '');
$shown = [
    'private'      => $t('Only me'),
    'role'         => $t('From a role up'),
    'organization' => $t('An organisation'),
    'everyone'     => $t('Everyone'),
];
?>
<dialog class="modal" data-pf-dashboard-presets-dialog aria-labelledby="pf-dashboard-presets-title">
    <div style="padding:1rem;max-width:40rem">
        <h3 id="pf-dashboard-presets-title"><?php echo $e($t('Presets')); ?></h3>
        <?php if (empty($this->presets)): ?>
        <p class="text-sm opacity-70"><?php echo $e($t('No presets yet.')); ?></p>
        <?php else: ?>
        <ul style="list-style:none;padding:0;margin:.5rem 0;max-height:40vh;overflow:auto">
            <?php foreach ($this->presets as $preset): ?>
            <li class="flex flex-wrap items-center gap-2 py-2 border-b border-base-300" data-pf-dashboard-preset="<?php echo $e($preset['id']); ?>">
                <div style="flex:1;min-width:10rem">
                    <strong><?php echo $e($preset['kind'] === 'app' ? $t((string) $preset['name']) : $preset['name']); ?></strong>
                    <div class="text-sm opacity-70">
                        <?php echo $e($preset['kind'] === 'app' ? $t('From the application') : ($preset['mine'] ? $t('Yours') : $t('Shared with you'))); ?>
                        <?php if ($preset['kind'] === 'saved'): ?> · <?php echo $e($shown[$preset['visibility']] ?? $preset['visibility']); ?><?php echo $preset['audience'] !== null ? ' (' . $e($preset['audience']) . ')' : ''; ?><?php endif; ?>
                        <?php if (!empty($preset['default_for_role'])): ?> · <span class="badge badge-ghost"><?php echo $e(sprintf($t('Default for %s'), $preset['default_for_role'])); ?></span><?php endif; ?>
                    </div>
                </div>
                <form method="post" action="<?php echo $e($urls['apply'] ?? ''); ?>" style="display:inline"><?php echo $field; ?>
                    <input type="hidden" name="preset" value="<?php echo $e($preset['id']); ?>">
                    <button type="submit" class="btn btn-sm btn-primary" data-confirm="<?php echo $e($t('Replace your dashboard with this preset?')); ?>"><?php echo $e($t('Apply')); ?></button>
                </form>
                <?php if ($preset['kind'] === 'saved' && ($preset['mine'] || !empty($this->managesPresets))): ?>
                <form method="post" action="<?php echo $e($urls['delete'] ?? ''); ?>" style="display:inline"><?php echo $field; ?>
                    <input type="hidden" name="preset" value="<?php echo $e($preset['id']); ?>">
                    <button type="submit" class="btn btn-sm btn-error btn-outline" data-confirm="<?php echo $e($t('Delete this preset?')); ?>"><?php echo $e($t('Delete')); ?></button>
                </form>
                <?php endif; ?>
                <?php if ($preset['kind'] === 'saved' && !empty($this->managesPresets)): ?>
                <form method="post" action="<?php echo $e($urls['default'] ?? ''); ?>" style="display:inline-flex;gap:.25rem"><?php echo $field; ?>
                    <input type="hidden" name="preset" value="<?php echo $e($preset['id']); ?>">
                    <select name="role" class="select select-sm select-bordered" aria-label="<?php echo $e($t('Default for a role')); ?>" style="width:auto">
                        <option value=""><?php echo $e($t('No role')); ?></option>
                        <?php foreach ((array) ($this->roles ?? []) as $role): ?>
                        <option value="<?php echo $e($role); ?>"<?php echo ($preset['default_for_role'] ?? '') === $role ? ' selected' : ''; ?>><?php echo $e($role); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-ghost"><?php echo $e($t('Set as default')); ?></button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h4><?php echo $e($t('Save this dashboard as a preset')); ?></h4>
        <form method="post" action="<?php echo $e($urls['save'] ?? ''); ?>" style="display:grid;gap:.5rem"><?php echo $field; ?>
            <label class="label-text text-sm"><?php echo $e($t('Name')); ?>
                <input type="text" name="name" required maxlength="120" class="input input-sm input-bordered"></label>
            <label class="label-text text-sm"><?php echo $e($t('Who sees it')); ?>
                <select name="visibility" class="select select-sm select-bordered">
                    <?php foreach ($shown as $value => $label): ?>
                    <option value="<?php echo $e($value); ?>"><?php echo $e($label); ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="label-text text-sm"><?php echo $e($t('The role, for "From a role up"')); ?>
                <select name="audience_role" class="select select-sm select-bordered">
                    <?php foreach ((array) ($this->roles ?? []) as $role): ?>
                    <option value="<?php echo $e($role); ?>"><?php echo $e($role); ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="label-text text-sm"><?php echo $e($t('The organisation id, for "An organisation"')); ?>
                <input type="number" name="audience_org" min="1" class="input input-sm input-bordered"></label>
            <div><button type="submit" class="btn btn-sm btn-primary"><?php echo $e($t('Save as preset')); ?></button></div>
        </form>

        <?php if (!empty($this->managesPresets)): ?>
        <h4><?php echo $e($t('Copy my dashboard to somebody')); ?></h4>
        <form method="post" action="<?php echo $e($urls['copy'] ?? ''); ?>" style="display:flex;gap:.5rem;align-items:end"><?php echo $field; ?>
            <label class="label-text text-sm"><?php echo $e($t('Their user id')); ?>
                <input type="number" name="userid" min="2" required class="input input-sm input-bordered"></label>
            <button type="submit" class="btn btn-sm btn-ghost" data-confirm="<?php echo $e($t('Replace their dashboard with yours?')); ?>"><?php echo $e($t('Copy')); ?></button>
        </form>
        <?php endif; ?>

        <?php if (!empty($this->managesPresets) && !empty($urls['list'])): ?>
        <p><a class="btn btn-sm btn-link" href="<?php echo $e($urls['list']); ?>"><?php echo $e($t('All presets')); ?></a></p>
        <?php endif; ?>
        <p style="margin-top:1rem"><button type="button" class="btn btn-sm btn-ghost" data-pf-dashboard-presets-close><?php echo $e($t('Close')); ?></button></p>
    </div>
</dialog>
