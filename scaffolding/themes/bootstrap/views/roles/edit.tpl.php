@php /**
 * Role create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->role          — \Pramnos\Auth\Role (null when creating)
 *   $this->organizations — [organization_id => name] for the owner select
 *
 * The organisation select is the field that matters: leaving it on "System-wide"
 * makes a role valid everywhere, and choosing an organisation restricts both who may
 * hold it and where it counts.
 */
$role  = $this->role ?? null;
$isNew = $role === null || (int) $role->roleid === 0;
$orgId = (int) ($role->organization_id ?? 0); @endphp
<div class="container py-4" style="max-width:640px">
    @php $this->activeNav = 'roles_edit'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <h2 class="mb-4">{!! $isNew ? 'New Role' : 'Edit Role' !!}</h2>
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    <div class="card mb-4">
        <div class="card-body">
            <form method="post" action="{!! adminUrl('Roles/save') !!}">
                {!! \Pramnos\Http\Middleware\CsrfMiddleware::tokenField() !!}
                @if (!$isNew)
                    <input type="hidden" name="roleid" value="{!! (int) $role->roleid !!}">
                @endif
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="role_name" class="form-control" required
                           value="{{ (string) ($role->role_name ?? '') }}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3">{{ (string) ($role->description ?? '') }}</textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Organisation</label>
                    <select name="organization_id" class="form-select">
                        <option value="0"{!! $orgId === 0 ? ' selected' : '' !!}>System-wide — valid everywhere</option>
                        @foreach (($this->organizations ?? []) as $id => $name)
                            <option value="{!! (int) $id !!}"{!! $orgId === (int) $id ? ' selected' : '' !!}>
                                {{ (string) $name }}
                            </option>
                        @endforeach
                    </select>
                    @if (!$isNew)
                        <small>An organisation cannot be changed once the role is held by
                        anyone — the holders who are not members of the new organisation
                        would silently lose it.</small>
                    @endif
                </div>
                @if (!$isNew)
                <div class="mb-3">
                    <label>
                        <input type="checkbox" name="is_active" value="1"{!! ((int) ($role->is_active ?? 1)) === 1 ? ' checked' : '' !!}>
                        Active — an inactive role grants nothing to anyone who holds it
                    </label>
                </div>
                @endif
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="{!! adminUrl('Roles') !!}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
