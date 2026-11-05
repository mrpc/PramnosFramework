<?php
/**
 * The presets and dashboards dialog of a board (plain-css theme), in tabs: the presets to apply
 * (and delete, or make a type's default), saving this board as one, a new dashboard of one's
 * own, one's own board's settings, and — for an administrator — copying it to somebody, picked by
 * name. Plain forms, each posting with the session's token and coming back to the page with this
 * dialog open on the tab used (`#pf-presets=save`).
 *
 * A theme overrides it by copying it. The script reads: data-pf-presets-tabs (data-active-class)
 * and data-pf-presets-tab / data-pf-presets-pane, data-pf-show-when="field=value" on a field
 * shown only for a choice, and data-pf-person-search (data-url) / data-pf-person-results /
 * data-pf-person-id on the picker.
 *
 * Variables: $this->presets, presetUrls, managesPresets, roles (usertype => label, from the
 * board's floor up), organizations (id => name; none, no such choice) and organizationLabel
 * (what the application calls one), peopleUrl, csrfField,
 * userBoard, boardUrls.
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$urls      = (array) ($this->presetUrls ?? []);
$boardUrls = (array) ($this->boardUrls ?? []);
$field     = (string) ($this->csrfField ?? '');
$types     = (array) ($this->roles ?? []);
$orgs      = (array) ($this->organizations ?? []);
// What the application calls one — an organisation, or its own word.
$orgWord   = $t((string) ($this->organizationLabel ?? 'Organisation'));
$own       = $this->userBoard ?? null;
$mine      = is_array($own) && !empty($own['mine']);
$admin     = !empty($this->managesPresets);
$shown = ['private' => $t('Only me'), 'role' => $t('From a user type up'), 'everyone' => $t('Everyone')];
if ($orgs !== []) {
    $shown = ['private' => $shown['private'], 'role' => $shown['role'], 'organization' => sprintf($t('Members of: %s'), $orgWord), 'everyone' => $shown['everyone']];
}
$typeLabel = static fn ($audience): string => ctype_digit((string) $audience) && isset($types[(int) $audience]) ? $t((string) $types[(int) $audience]) : (ctype_digit((string) $audience) && isset($orgs[(int) $audience]) ? (string) $orgs[(int) $audience] : (string) $audience);
$tabs = ['list' => $t('Presets'), 'save' => $t('Save as preset'), 'new' => $t('New dashboard')];
if ($mine) {
    $tabs['mine'] = $t('My dashboard');
}
if ($admin) {
    $tabs['copy'] = $t('Copy to a user');
}
/** The fields that say who sees something, for a form: the choice, then a type or an organisation. */
$whoSees = static function (array $now = []) use ($e, $t, $shown, $types, $orgs, $orgWord): string {
    $html = '<label class="small">' . $e($t('Who sees it')) . '<select name="visibility" class="form-control">';
    foreach ($shown as $value => $label) {
        $html .= '<option value="' . $e($value) . '"' . (($now['visibility'] ?? 'private') === $value ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label class="small" data-pf-show-when="visibility=role">' . $e($t('From this user type up')) . '<select name="audience_role" class="form-control">';
    foreach ($types as $level => $label) {
        $html .= '<option value="' . (int) $level . '"' . ((string) ($now['audience'] ?? '') === (string) $level ? ' selected' : '') . '>' . $e($t((string) $label)) . '</option>';
    }
    $html .= '</select></label>';
    if ($orgs !== []) {
        $html .= '<label class="small" data-pf-show-when="visibility=organization">' . $e($orgWord) . '<select name="audience_org" class="form-control">';
        foreach ($orgs as $id => $name) {
            $html .= '<option value="' . $e($id) . '"' . ((string) ($now['audience'] ?? '') === (string) $id ? ' selected' : '') . '>' . $e($name) . '</option>';
        }
        $html .= '</select></label>';
    }

    return $html;
};
?>
<dialog class="card pf-dashboard-presets-dialog" data-pf-dashboard-presets-dialog aria-labelledby="pf-dashboard-presets-title">
    <div class="pf-dashboard-presets">
        <h3 id="pf-dashboard-presets-title"><?php echo $e($t('Presets and dashboards')); ?></h3>
        <div class="pf-dashboard-tab-strip" role="tablist" data-pf-presets-tabs data-active-class="is-active">
            <?php $first = true; foreach ($tabs as $key => $label): ?>
            <button type="button" role="tab" class="pf-dashboard-tab<?php echo $first ? ' is-active' : ''; ?>" data-pf-presets-tab="<?php echo $e($key); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>"><?php echo $e($label); ?></button>
            <?php $first = false; endforeach; ?>
        </div>

        <div role="tabpanel" data-pf-presets-pane="list">
            <?php if (empty($this->presets)): ?>
            <p class="small pf-muted"><?php echo $e($t('No presets yet.')); ?></p>
            <?php else: ?>
            <ul class="pf-dashboard-presets-list">
                <?php foreach ($this->presets as $preset): ?>
                <li class="pf-ui-row" data-pf-dashboard-preset="<?php echo $e($preset['id']); ?>">
                    <div style="flex:1;min-width:10rem">
                        <strong><?php echo $e($preset['kind'] === 'app' ? $t((string) $preset['name']) : $preset['name']); ?></strong>
                        <div class="small pf-muted">
                            <?php echo $e($preset['kind'] === 'app' ? $t('From the application') : ($preset['mine'] ? $t('Yours') : $t('Shared with you'))); ?>
                            <?php if ($preset['kind'] === 'saved'): ?> · <?php echo $e($shown[$preset['visibility']] ?? $t((string) $preset['visibility'])); ?><?php echo $preset['audience'] !== null ? ' (' . $e($typeLabel($preset['audience'])) . ')' : ''; ?><?php endif; ?>
                            <?php if (!empty($preset['default_for_role'])): ?> · <span class="badge"><?php echo $e(sprintf($t('Default for %s'), $typeLabel($preset['default_for_role']))); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <form method="post" action="<?php echo $e($urls['apply'] ?? ''); ?>"><?php echo $field; ?>
                        <input type="hidden" name="_tab" value="list"><input type="hidden" name="preset" value="<?php echo $e($preset['id']); ?>">
                        <button type="submit" class="btn btn-primary btn-sm" data-confirm="<?php echo $e($t('Replace your dashboard with this preset?')); ?>"><?php echo $e($t('Apply')); ?></button>
                    </form>
                    <?php if ($preset['kind'] === 'saved' && ($preset['mine'] || $admin)): ?>
                    <form method="post" action="<?php echo $e($urls['delete'] ?? ''); ?>"><?php echo $field; ?>
                        <input type="hidden" name="_tab" value="list"><input type="hidden" name="preset" value="<?php echo $e($preset['id']); ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="<?php echo $e($t('Delete this preset?')); ?>"><?php echo $e($t('Delete')); ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($preset['kind'] === 'saved' && $admin): ?>
                    <form method="post" action="<?php echo $e($urls['default'] ?? ''); ?>" class="pf-dashboard-inline-form"><?php echo $field; ?>
                        <input type="hidden" name="_tab" value="list"><input type="hidden" name="preset" value="<?php echo $e($preset['id']); ?>">
                        <select name="role" class="form-control" aria-label="<?php echo $e($t('Default for a user type')); ?>" style="width:auto">
                            <option value=""><?php echo $e($t('No user type')); ?></option>
                            <?php foreach ($types as $level => $label): ?>
                            <option value="<?php echo (int) $level; ?>"<?php echo (string) ($preset['default_for_role'] ?? '') === (string) $level ? ' selected' : ''; ?>><?php echo $e($t((string) $label)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-secondary btn-sm"><?php echo $e($t('Set as default')); ?></button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div role="tabpanel" data-pf-presets-pane="save" hidden>
            <form method="post" action="<?php echo $e($urls['save'] ?? ''); ?>" class="pf-dashboard-form"><?php echo $field; ?>
                <input type="hidden" name="_tab" value="save">
                <label class="small"><?php echo $e($t('Name')); ?><input type="text" name="name" required maxlength="120" class="form-control"></label>
                <?php echo $whoSees(); ?>
                <div><button type="submit" class="btn btn-primary btn-sm"><?php echo $e($t('Save as preset')); ?></button></div>
            </form>
        </div>

        <div role="tabpanel" data-pf-presets-pane="new" hidden>
            <form method="post" action="<?php echo $e($boardUrls['new'] ?? ''); ?>" class="pf-dashboard-form"><?php echo $field; ?>
                <input type="hidden" name="_tab" value="new">
                <label class="small"><?php echo $e($t('Name')); ?><input type="text" name="name" required maxlength="120" class="form-control"></label>
                <label class="small"><?php echo $e($t('Start from')); ?>
                    <select name="preset" class="form-control">
                        <option value=""><?php echo $e($t('An empty dashboard')); ?></option>
                        <?php foreach ((array) ($this->presets ?? []) as $preset): ?>
                        <option value="<?php echo $e($preset['id']); ?>"><?php echo $e($preset['kind'] === 'app' ? $t((string) $preset['name']) : $preset['name']); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <div><button type="submit" class="btn btn-primary btn-sm"><?php echo $e($t('Make it')); ?></button></div>
            </form>
        </div>

        <?php if ($mine): ?>
        <div role="tabpanel" data-pf-presets-pane="mine" hidden>
            <form method="post" action="<?php echo $e($boardUrls['settings'] ?? ''); ?>" class="pf-dashboard-form"><?php echo $field; ?>
                <input type="hidden" name="_tab" value="mine">
                <label class="small"><?php echo $e($t('Name')); ?><input type="text" name="name" required maxlength="120" class="form-control" value="<?php echo $e($own['name']); ?>"></label>
                <?php echo $whoSees(['visibility' => $own['visibility'] ?? 'private', 'audience' => $own['audience'] ?? '']); ?>
                <div><button type="submit" class="btn btn-primary btn-sm"><?php echo $e($t('Save')); ?></button></div>
            </form>
            <form method="post" action="<?php echo $e($boardUrls['delete'] ?? ''); ?>" style="margin-top:1rem"><?php echo $field; ?>
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="<?php echo $e($t('Delete this dashboard, for everybody who has it?')); ?>"><?php echo $e($t('Delete this dashboard')); ?></button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($admin): ?>
        <div role="tabpanel" data-pf-presets-pane="copy" hidden>
            <form method="post" action="<?php echo $e($urls['copy'] ?? ''); ?>" class="pf-dashboard-form" data-pf-person-form><?php echo $field; ?>
                <input type="hidden" name="_tab" value="copy">
                <input type="hidden" name="userid" value="" data-pf-person-id>
                <label class="small"><?php echo $e($t('The user')); ?>
                    <input type="search" class="form-control" autocomplete="off" data-pf-person-search data-url="<?php echo $e($this->peopleUrl ?? ''); ?>" placeholder="<?php echo $e($t('Name, username or email')); ?>"></label>
                <ul class="pf-dashboard-people pf-dashboard-people" data-pf-person-results data-pick-class="pf-dashboard-person" hidden></ul>
                <p class="small pf-muted"><?php echo $e($t('Only active users who can open this dashboard are offered. Their dashboard is replaced with yours.')); ?></p>
                <div><button type="submit" class="btn btn-outline-secondary btn-sm" data-pf-person-submit disabled data-confirm="<?php echo $e($t('Replace their dashboard with yours?')); ?>"><?php echo $e($t('Copy')); ?></button></div>
            </form>
        </div>
        <?php endif; ?>

        <div class="pf-dashboard-presets-foot">
            <?php if ($admin && !empty($urls['list'])): ?><a class="btn btn-sm" href="<?php echo $e($urls['list']); ?>"><?php echo $e($t('All presets')); ?></a><?php endif; ?>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-pf-dashboard-presets-close><?php echo $e($t('Close')); ?></button>
        </div>
    </div>
</dialog>
