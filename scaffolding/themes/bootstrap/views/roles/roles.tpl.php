{{-- Roles list (Bootstrap theme).

Variables:
  $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
  $this->success   — optional success flash message
  $this->error     — optional error flash message --}}
<div class="container py-4">
    @php $this->activeNav = 'roles'; $this->insert('../partials/admin_breadcrumb'); @endphp
    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Roles</h2>
        <a href="{!! adminUrl('Roles/edit') !!}" class="btn btn-primary">+ New Role</a>
    </div>
    {!! $this->datatable->render() !!}
</div>
