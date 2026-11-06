@php /**
 * User group create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->group — usergroups row, or null when creating
 */
$group = $this->group ?? [];
$isNew = empty($group['groupid']); @endphp
<div class="container-fluid py-4">
    @php $this->activeNav = 'groups_edit'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <h2 class="mb-0">{!! $isNew ? 'New group' : 'Edit group' !!}</h2>
    <div class="card mb-3"><div class="card-body">
        <form method="post" action="{!! adminUrl('Groups/save') !!}">
            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
            @if (!$isNew)
                <input type="hidden" name="groupid" value="{!! (int) $group['groupid'] !!}">
            @endif
            <div class="mb-3">
                <label class="form-label fw-semibold" for="group-name">Name</label>
                <input type="text" id="group-name" name="name" maxlength="80" required class="form-control" value="{{ (string) ($group['name'] ?? '') }}">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="group-description">Description</label>
                <textarea id="group-description" name="description" rows="3" class="form-control">{{ (string) ($group['description'] ?? '') }}</textarea>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="group-order">Order</label>
                <input type="number" id="group-order" name="order" min="-127" max="127" step="1" class="form-control" style="max-width:10rem" value="{!! isset($group['order']) && $group['order'] !== null ? (int) $group['order'] : '' !!}">
                <div class="form-text">Where the group comes in the list, from -127 to 127; empty puts it after those with one.</div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="{!! $isNew ? adminUrl('Groups') : adminUrl('Groups/view/') . (int) $group['groupid'] !!}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div></div>
</div>
