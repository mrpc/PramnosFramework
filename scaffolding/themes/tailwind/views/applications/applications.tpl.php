{{-- OAuth2 Applications list (Tailwind theme).

Variables:
  $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
  $this->success   — optional success flash message
  $this->error     — optional error flash message --}}
<div class="px-4 py-6">
    @if (!empty($this->success))
        <div role="status" class="alert alert-success mb-4">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-error mb-4">{{ $this->error }}</div>
    @endif
    <div class="flex justify-between items-center mb-4">
        <h2>OAuth2 Applications</h2>
        <a href="{!! adminUrl('Applications/edit') !!}" class="btn btn-primary btn-sm">+ New Application</a>
    </div>
    {!! $this->datatable->render() !!}
</div>
