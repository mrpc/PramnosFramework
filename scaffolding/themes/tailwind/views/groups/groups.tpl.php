{{-- User groups list (Tailwind theme) — the usergroups feature.

Variables:
  $this->datatable — \Pramnos\Html\Datatable, server-side, its rows from `Groups/data` --}}
<div class="px-4 py-6">
    @php $this->activeNav = 'groups'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="flex justify-between items-center gap-2 mb-4">
        <h2>Groups</h2>
        <a href="{!! adminUrl('Groups/edit') !!}" class="btn btn-primary btn-sm">+ New group</a>
    </div>
    {!! $this->datatable->render() !!}
</div>
