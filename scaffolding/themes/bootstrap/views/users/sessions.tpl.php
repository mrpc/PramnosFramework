{{-- User sessions list (Bootstrap theme).

Variables:
  $this->user        — user row array
  $this->sessionList — iterable session rows --}}
<div class="container-fluid py-4">
    @php $this->activeNav = 'users_sessions'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="d-flex align-items-center gap-3 mb-3">
        <h2 class="mb-0">Sessions — {{ $this->user['username'] ?? '' }}</h2>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Last Active</th><th>Status</th></tr>
                </thead>
                <tbody>
                @foreach (($this->sessionList ?? []) as $s)
                    @php $active = (int) ($s['logout'] ?? 0) === 0; @endphp
                    <tr>
                        <td><code>{!! htmlspecialchars(substr((string) ($s['visitorid'] ?? ''), 0, 16)) . '…' !!}</code></td>
                        <td>{{ $s['host_addr'] ?? '' }}</td>
                        <td class="text-muted small">{{ substr((string) ($s['agent'] ?? ''), 0, 60) }}</td>
                        <td>{!! isset($s['time']) ? htmlspecialchars(localDateTime((int) $s['time'])) : '' !!}</td>
                        <td><span class="badge bg-{!! $active ? 'success' : 'secondary' !!}">{!! $active ? 'Active' : 'Logged out' !!}</span></td>
                    </tr>
                @endforeach
                @if (empty($this->sessionList))
                    <tr><td colspan="5" class="text-center text-muted py-4">No active sessions.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
