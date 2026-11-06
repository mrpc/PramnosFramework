@php /**
 * User sessions list (Tailwind theme).
 *
 * Variables:
 *   $this->user         — user row array (empty when this is the whole site's list)
 *   $this->sessionList  — iterable session rows
 *   $this->scopedToUser — bool: one account, or everybody
 *   $this->activeWindow — seconds a session counts as "active" for, matching the dashboard
 */
$scoped = (bool) ($this->scopedToUser ?? true);
$window = (int) ($this->activeWindow ?? 300);
$fresh  = time() - $window;
$rows   = $this->sessionList ?? [];
$live   = 0;

foreach ($rows as $row) {
    if ((int) ($row['logout'] ?? 0) === 0 && (int) ($row['time'] ?? 0) >= $fresh) {
        $live++;
    }
} @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'users_sessions'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="flex flex-wrap items-center gap-3 mb-4">
        @if ($scoped)
        <h2>Sessions — {{ $this->user['username'] ?? '' }}</h2>
        @else
        <h2>Active sessions</h2>
        <span class="badge badge-primary badge-sm">{!! $live !!} in the last
            {!! (int) round($window / 60) !!} min</span>
        <span class="text-xs text-base-content/60">
            {{-- The same window the dashboard's "Active users (now)" figure counts, so
                     the number somebody clicked and the list they land on agree. --}}
            Rows older than that are kept until the session table is swept.
        </span>
        @endif
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr>
                        @if (!$scoped)<th>Who</th>@endif
                        <th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Last Active</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                @foreach (($this->sessionList ?? []) as $s)
                    @php $active = (int) ($s['logout'] ?? 0) === 0 && (int) ($s['time'] ?? 0) >= $fresh;
                    $rowUserId = (int) ($s['userid'] ?? 0); @endphp
                    <tr>
                        @if (!$scoped)
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if ($rowUserId > 0)
                                <a class="link" href="{!! adminUrl('Users/view/') . $rowUserId !!}">
                                    {{ ($s['uname'] ?? '') !== '' ? $s['uname'] : ('#' . $rowUserId) }}
                                </a>
                            @else
                                <span class="text-base-content/60">guest</span>
                            @endif
                        </td>
                        @endif
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-base-content/70">{!! htmlspecialchars(substr((string) ($s['visitorid'] ?? ''), 0, 16)) . '…' !!}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-base-content">{{ $s['host_addr'] ?? '' }}</td>
                        <td class="px-6 py-4 text-sm text-base-content/70">{{ substr((string) ($s['agent'] ?? ''), 0, 60) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-base-content/70">{!! isset($s['time']) ? htmlspecialchars(localDateTime((int) $s['time'])) : '' !!}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm"><span class="px-2 py-0.5 rounded-full text-xs font-medium {!! $active ? 'bg-success/10 text-success' : 'bg-base-200 text-base-content/80' !!}">{!! $active ? 'Active' : 'Logged out' !!}</span></td>
                    </tr>
                @endforeach
                @if (empty($rows))
                    <tr><td colspan="{!! $scoped ? 5 : 6 !!}" class="text-center text-base-content/60 py-8">No sessions recorded.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
