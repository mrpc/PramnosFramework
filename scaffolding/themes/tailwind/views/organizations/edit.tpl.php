@php /**
 * Organization create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->organization — row array (null when creating)
 */
$org = $this->organization ?? [];
$isNew = empty($org['organization_id']); @endphp
<div class="max-w-2xl mx-auto py-6 px-4">
    <h2 class="mb-6">{!! $isNew ? 'New Organization' : 'Edit Organization' !!}</h2>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="p-5">
            <form method="post" action="{!! adminUrl('Organizations/save') !!}">
                {!! \Pramnos\Http\Middleware\CsrfMiddleware::tokenField() !!}
                @if (!$isNew)
                    <input type="hidden" name="organization_id" value="{!! (int)$org['organization_id'] !!}">
                @endif
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Name</label>
                    <input type="text" name="name" class="input input-sm w-full" required value="{{ $org['name'] ?? '' }}">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Description</label>
                    <textarea name="description" class="input input-sm w-full" rows="3">{{ $org['description'] ?? '' }}</textarea>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Type</label>
                    <input type="text" name="org_type" class="input input-sm w-full" value="{{ (string) ($org['org_type'] ?? '') }}" placeholder="company, school, department…">
                </div>
                <div class="mb-4">
                    <input type="hidden" name="is_active" value="0">
                    <label><input type="checkbox" name="is_active" value="1" {!! (int) ($org['is_active'] ?? 1) === 1 ? 'checked' : '' !!}> Active</label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <a href="{!! adminUrl('Organizations') !!}" class="btn btn-outline btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
