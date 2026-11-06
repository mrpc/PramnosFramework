@php /**
 * Users list (plain-CSS theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
$this->activeNav = 'users'; @endphp
<div class="page-section">
    @include('../partials/admin_breadcrumb')
    @if (!empty($this->success))
        <div style="padding:10px 14px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;margin-bottom:12px;color:#155724">{{ $this->success }}</div>
    @endif
    @if (!empty($this->error))
        <div style="padding:10px 14px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin-bottom:12px;color:#721c24">{{ $this->error }}</div>
    @endif
    {{-- The two links are wrapped: `space-between` on three children puts the middle
             one in the centre of the row, which reads as a broken stylesheet rather than as
             a layout — the heading left, "+ New User" floating in the middle, "User types"
             right. Grouped, it is heading | actions again. --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2>Users</h2>
        <div style="display:flex;align-items:center;gap:8px">
            <a href="{!! adminUrl('Users/types') !!}" class="btn btn-sm btn-outline-secondary" title="What each user type may do">User types</a>
            <a href="{!! adminUrl('Users/edit') !!}" class="btn btn-primary">+ New User</a>
        </div>
    </div>
    {!! $this->datatable->render() !!}
</div>
