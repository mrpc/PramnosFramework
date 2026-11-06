{{-- Organizations list (Bootstrap theme).

Variables:
  $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
  $this->success   — optional success flash message
  $this->error     — optional error flash message --}}
<div class="container-fluid py-4">
    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Organizations</h2>
        <a href="{!! adminUrl('Organizations/edit') !!}" class="btn btn-primary">+ New Organization</a>
    </div>
    {!! $this->datatable->render() !!}
</div>
