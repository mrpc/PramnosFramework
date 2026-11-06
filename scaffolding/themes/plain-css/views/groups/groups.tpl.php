{{-- User groups list (plain-CSS theme) — the usergroups feature.

Variables:
  $this->datatable — \Pramnos\Html\Datatable, server-side, its rows from `Groups/data` --}}
<div class="page-section">
    @php $this->activeNav = 'groups'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px">
        <h2>Groups</h2>
        <a href="{!! adminUrl('Groups/edit') !!}" class="btn btn-primary">+ New group</a>
    </div>
    {!! $this->datatable->render() !!}
</div>
