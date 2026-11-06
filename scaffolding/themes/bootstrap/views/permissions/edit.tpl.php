@php /**
 * Permission create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->permission — permission row array (null when creating)
 *   $this->vocabulary — appid => the resources, actions and condition keys it declares
 */
$p = $this->permission ?? [];
$isNew = empty($p['permissionid']); @endphp
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4">{!! $isNew ? 'New Permission' : 'Edit Permission' !!}</h2>
    <div class="card">
        <div class="card-body">
            <form method="post" action="{!! adminUrl('Permissions/save') !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                @if (!$isNew)
                    <input type="hidden" name="permissionid" value="{!! (int)$p['permissionid'] !!}">
                @endif
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Subject Type</label>
                        <select name="subject_type" class="form-select">
                            <option value="user" {!! ($p['subject_type'] ?? 'user') === 'user' ? 'selected' : '' !!}>User</option>
                            <option value="role" {!! ($p['subject_type'] ?? '') === 'role' ? 'selected' : '' !!}>Role</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Subject ID</label>
                        <input type="text" name="subject_id" class="form-control" value="{{ (string)($p['subject_id'] ?? '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Object Type</label>
                        <input type="text" name="object_type" list="pm-objects" autocomplete="off" class="form-control" value="{{ $p['object_type'] ?? '' }}" placeholder="e.g. resource">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Object ID</label>
                        <input type="text" name="object_id" class="form-control" value="{{ (string)($p['object_id'] ?? '') }}" placeholder="Leave blank for all">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Action</label>
                        <input type="text" name="action" list="pm-actions" autocomplete="off" class="form-control" required value="{{ $p['action'] ?? '' }}" placeholder="e.g. read, write, *">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Grant Type</label>
                        <select name="grant_type" class="form-select">
                            <option value="allow" {!! ($p['grant_type'] ?? 'allow') === 'allow' ? 'selected' : '' !!}>Allow</option>
                            <option value="deny" {!! ($p['grant_type'] ?? '') === 'deny' ? 'selected' : '' !!}>Deny</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Priority</label>
                        <input type="number" name="priority" min="0" class="form-control" value="{!! (int)($p['priority'] ?? 100) !!}">
                        <div class="form-text">The higher priority decides; a deny wins a tie.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Application ID</label>
                        <input type="number" name="app_id" min="1" class="form-control" value="{{ (string)($p['app_id'] ?? '') }}" placeholder="Leave blank for every application">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Expires</label>
                        <input type="datetime-local" name="expires_at" class="form-control" value="{{ substr(str_replace(' ', 'T', (string)($p['expires_at'] ?? '')), 0, 16) }}">
                        <div class="form-text">Leave blank for a permanent grant.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Conditions (JSON)</label>
                        <textarea name="conditions" rows="3" class="form-control" placeholder='{"location_id": [1, 2]}'>{{ (string)($p['conditions'] ?? '') }}</textarea>
                        <div class="form-text">Passed to the application with the grant, which evaluates it. Leave blank for an unconditional grant.</div>
                        <div id="pm-conditions" class="form-text"></div>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="{!! adminUrl('Permissions') !!}" class="btn btn-outline-secondary">Cancel</a>
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
    var vocabulary = {!! json_encode((object) ($this->vocabulary ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
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
