@php /**
 * Organization create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->organization — row array (null when creating)
 */
$org = $this->organization ?? [];
$isNew = empty($org['organization_id']); @endphp
<div class="page-section" style="max-width:640px">
    <h2 style="margin-bottom:16px">{!! $isNew ? 'New Organization' : 'Edit Organization' !!}</h2>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="{!! adminUrl('Organizations/save') !!}">
                {!! \Pramnos\Http\Middleware\CsrfMiddleware::tokenField() !!}
                @if (!$isNew)
                    <input type="hidden" name="organization_id" value="{!! (int)$org['organization_id'] !!}">
                @endif
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Name</label>
                    <input type="text" name="name" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required value="{{ $org['name'] ?? '' }}">
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Description</label>
                    <textarea name="description" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" rows="3">{{ $org['description'] ?? '' }}</textarea>
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Type</label>
                    <input type="text" name="org_type" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="{{ (string) ($org['org_type'] ?? '') }}" placeholder="company, school, department…">
                </div>
                <div style="margin-bottom:12px">
                    <input type="hidden" name="is_active" value="0">
                    <label><input type="checkbox" name="is_active" value="1" {!! (int) ($org['is_active'] ?? 1) === 1 ? 'checked' : '' !!}> Active</label>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="{!! adminUrl('Organizations') !!}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
