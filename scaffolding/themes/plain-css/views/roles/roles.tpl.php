{{-- Roles list (plain CSS theme).

Variables:
  $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
  $this->success   — optional success flash message
  $this->error     — optional error flash message --}}
<div class="page-section">
    @php $this->activeNav = 'roles'; $this->insert('../partials/admin_breadcrumb'); @endphp
    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2>Roles</h2>
        <a href="{!! adminUrl('Roles/edit') !!}" class="btn btn-primary">+ New Role</a>
    </div>
    {!! $this->datatable->render() !!}
</div>
