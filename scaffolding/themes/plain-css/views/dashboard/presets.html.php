<?php
/**
 * Every saved dashboard preset (plain-css theme), for an administrator: which board, who saved it,
 * who sees it, whose default it is — and the forms to change the default or delete it.
 *
 * Variables: $this->presets (rows of pramnos.dashboard_presets), $this->owners (userid => username),
 * $this->roles (usertype => label), $this->csrfField, $this->urls ['delete', 'default'].
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$shown = ['private' => $t('Only me'), 'role' => $t('From a user type up'), 'organization' => $t('An organisation'), 'everyone' => $t('Everyone')];
$field = (string) ($this->csrfField ?? '');
?>
<div class="container">
    <h2><?php echo $e($t('Dashboard presets')); ?></h2>
    <?php if (empty($this->presets)): ?>
    <p class="pf-muted"><?php echo $e($t('No presets yet.')); ?></p>
    <?php else: ?>
    <table class="table">
        <thead><tr>
            <th><?php echo $e($t('Board')); ?></th><th><?php echo $e($t('Name')); ?></th><th><?php echo $e($t('Saved by')); ?></th>
            <th><?php echo $e($t('Who sees it')); ?></th><th><?php echo $e($t('Default for a user type')); ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($this->presets as $row): ?>
        <tr>
            <td><?php echo $e($row['board']); ?></td>
            <td><?php echo $e($row['name']); ?></td>
            <td><?php echo $e($this->owners[(int) $row['ownerid']] ?? ('#' . (int) $row['ownerid'])); ?></td>
            <td><?php echo $e($shown[$row['visibility']] ?? $row['visibility']); ?><?php echo ($row['audience'] ?? null) !== null ? ' (' . $e($row['audience']) . ')' : ''; ?></td>
            <td>
                <form method="post" action="<?php echo $e($this->urls['default'] . (str_contains($this->urls['default'], '?') ? '&' : '?') . 'board=' . rawurlencode((string) $row['board'])); ?>" style="display:inline-flex;gap:.25rem"><?php echo $field; ?>
                    <input type="hidden" name="preset" value="<?php echo (int) $row['presetid']; ?>"><input type="hidden" name="back" value="list">
                    <select name="role" class="form-control" style="width:auto" aria-label="<?php echo $e($t('Default for a user type')); ?>">
                        <option value=""><?php echo $e($t('No user type')); ?></option>
                        <?php foreach ((array) $this->roles as $level => $label): ?>
                        <option value="<?php echo (int) $level; ?>"<?php echo (string) ($row['default_for_role'] ?? '') === (string) $level ? ' selected' : ''; ?>><?php echo $e($t((string) $label)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><?php echo $e($t('Set as default')); ?></button>
                </form>
            </td>
            <td>
                <form method="post" action="<?php echo $e($this->urls['delete']); ?>" style="display:inline"><?php echo $field; ?>
                    <input type="hidden" name="preset" value="<?php echo (int) $row['presetid']; ?>"><input type="hidden" name="back" value="list">
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="<?php echo $e($t('Delete this preset?')); ?>"><?php echo $e($t('Delete')); ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
