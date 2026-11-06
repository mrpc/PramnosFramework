{{-- OAuth2 Applications list (plain-CSS theme).

Variables:
  $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
  $this->success   — optional success flash message
  $this->error     — optional error flash message --}}
<div class="page-section">
    @if (!empty($this->success))
        <div style="padding:10px 14px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;margin-bottom:12px;color:#155724">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div style="padding:10px 14px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin-bottom:12px;color:#721c24">{{ $this->error }}</div>
    @endif
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2>OAuth2 Applications</h2>
        <a href="{!! adminUrl('Applications/edit') !!}" class="btn btn-primary">+ New Application</a>
    </div>
    {!! $this->datatable->render() !!}
</div>
