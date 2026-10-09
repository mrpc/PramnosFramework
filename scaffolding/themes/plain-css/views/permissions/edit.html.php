<?php
/**
 * Permission create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->permission — permission row array (null when creating)
 *   $this->vocabulary — appid => the resources, actions and condition keys it declares
 */
$p = $this->permission ?? [];
$isNew = empty($p['permissionid']);
?>
<div class="page-section"max-width:640px">
    <h2 style="margin-bottom:16px"><?php echo $isNew ? 'New Permission' : 'Edit Permission'; ?></h2>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo adminUrl('Permissions/save'); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!$isNew): ?>
                    <input type="hidden" name="permissionid" value="<?php echo (int)$p['permissionid']; ?>">
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:12px">
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Subject Type</label>
                        <select name="subject_type" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
                            <option value="user" <?php echo ($p['subject_type'] ?? 'user') === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="role" <?php echo ($p['subject_type'] ?? '') === 'role' ? 'selected' : ''; ?>>Role</option>
                        </select>
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Subject ID</label>
                        <input type="text" name="subject_id" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars((string)($p['subject_id'] ?? '')); ?>">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Object Type</label>
                        <input type="text" name="object_type" list="pm-objects" autocomplete="off" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($p['object_type'] ?? ''); ?>" placeholder="e.g. resource">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Object ID</label>
                        <input type="text" name="object_id" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars((string)($p['object_id'] ?? '')); ?>" placeholder="Leave blank for all">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Action</label>
                        <input type="text" name="action" list="pm-actions" autocomplete="off" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required value="<?php echo htmlspecialchars($p['action'] ?? ''); ?>" placeholder="e.g. read, write, *">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Grant Type</label>
                        <select name="grant_type" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
                            <option value="allow" <?php echo ($p['grant_type'] ?? 'allow') === 'allow' ? 'selected' : ''; ?>>Allow</option>
                            <option value="deny" <?php echo ($p['grant_type'] ?? '') === 'deny' ? 'selected' : ''; ?>>Deny</option>
                        </select>
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Priority</label>
                        <input type="number" name="priority" min="0" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo (int)($p['priority'] ?? 100); ?>">
                        <div style="font-size:.85em;color:#666;margin-top:4px">The higher priority decides; a deny wins a tie.</div>
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Application ID</label>
                        <input type="number" name="app_id" min="1" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars((string)($p['app_id'] ?? '')); ?>" placeholder="Leave blank for every application">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Expires</label>
                        <input type="datetime-local" name="expires_at" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars(substr(str_replace(' ', 'T', (string)($p['expires_at'] ?? '')), 0, 16)); ?>">
                        <div style="font-size:.85em;color:#666;margin-top:4px">Leave blank for a permanent grant.</div>
                    </div>
                    <div style="flex:1 1 100%">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Conditions (JSON)</label>
                        <textarea name="conditions" rows="3" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" placeholder='{"location_id": [1, 2]}'><?php echo htmlspecialchars((string)($p['conditions'] ?? '')); ?></textarea>
                        <div style="font-size:.85em;color:#666;margin-top:4px">Passed to the application with the grant, which evaluates it. Leave blank for an unconditional grant.</div>
                        <div id="pm-conditions" style="font-size:.85em;color:#666;margin-top:4px"></div>
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo adminUrl('Permissions'); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<datalist id="pm-objects"></datalist>
<datalist id="pm-actions"></datalist>
<script>
/*
 * The vocabulary the entered application declares: its resources for Object Type, the chosen
 * resource's actions (and *) for Action, its condition keys under Conditions. No application, or
 * one that declared nothing, leaves both fields free text. The save checks the names either way.
 */
(function () {
    var vocabulary = <?php echo json_encode((object) ($this->vocabulary ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var app = document.querySelector('[name=app_id]');
    var object = document.querySelector('[name=object_type]');
    var hint = document.getElementById('pm-conditions');
    function fill(id, names) {
        document.getElementById(id).replaceChildren.apply(document.getElementById(id), names.map(function (name) {
            var option = document.createElement('option');
            option.value = name;
            return option;
        }));
    }
    function update() {
        var declared = vocabulary[app.value];
        var resources = declared ? declared.resources : {};
        var conditions = declared ? declared.conditions : {};
        fill('pm-objects', Object.keys(resources));
        fill('pm-actions', resources[object.value] ? resources[object.value].concat('*') : []);
        hint.textContent = Object.keys(conditions).length === 0 ? '' : 'Condition keys this application evaluates: '
            + Object.keys(conditions).map(function (key) { return key + ' (' + conditions[key] + ')'; }).join(', ');
    }
    app.addEventListener('input', update);
    object.addEventListener('input', update);
    update();
})();
</script>
