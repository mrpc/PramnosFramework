{{-- Users list (Bootstrap theme).

Variables:
  $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
  $this->success   — optional success flash message
  $this->error     — optional error flash message --}}
<div class="container-fluid py-4">
    @php $this->activeNav = 'users'; $this->insert('../partials/admin_breadcrumb'); @endphp
    @if (!empty($this->success))
        <div role="status" class="alert alert-success">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    {{-- The two links are wrapped: `space-between` on three children puts the middle
             one in the centre of the row, which reads as a broken stylesheet rather than as
             a layout — the heading left, "+ New User" floating in the middle, "User types"
             right. Grouped, it is heading | actions again. --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Users</h2>
        <div class="d-flex align-items-center gap-2">
            <a href="{!! adminUrl('Users/types') !!}" class="btn btn-sm btn-link" title="What each user type may do">User types</a>
            <a href="{!! adminUrl('Users/edit') !!}" class="btn btn-primary">+ New User</a>
        </div>
    </div>
    {!! $this->datatable->render() !!}
</div>
