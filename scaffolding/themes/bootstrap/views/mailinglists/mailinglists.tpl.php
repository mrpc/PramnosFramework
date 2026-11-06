@php /**
 * Mailing lists and their subscribers (Bootstrap theme).
 *
 * Variables:
 *   $this->lists   — every opt-in list
 *   $this->counts  — list => ['pending' => n, 'confirmed' => n, 'unsubscribed' => n]
 *   $this->list    — the list shown
 *   $this->status  — '', or the one state shown
 *   $this->search  — part of an address, or ''
 *   $this->page    — the page
 *   $this->perPage — rows on a page
 *   $this->result  — ['rows' => one page of subscribers, 'total' => every match]
 *
 * Nobody is added here: an opt-in list holds the people who asked, with the proof that they did.
 */
$lists   = is_array($this->lists ?? null) ? $this->lists : [];
$counts  = is_array($this->counts ?? null) ? $this->counts : [];
$list    = (string) ($this->list ?? '');
$status  = (string) ($this->status ?? '');
$search  = (string) ($this->search ?? '');
$page    = max(1, (int) ($this->page ?? 1));
$perPage = max(1, (int) ($this->perPage ?? 50));
$rows    = $this->result['rows'] ?? [];
$total   = (int) ($this->result['total'] ?? 0);
$pages   = max(1, (int) ceil($total / $perPage));
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when    = static fn ($t): string => $t ? localDateTime((int) $t) : '—';
$link    = static fn (array $q): string => adminUrl('MailingLists') . '?' . http_build_query(array_filter(
    $q + ['list' => $list, 'status' => $status, 'q' => $search],
    static fn ($v): bool => $v !== '' && $v !== null
));
$states  = ['pending' => ['Waiting to confirm', 'badge text-bg-warning'], 'confirmed' => ['Confirmed', 'badge text-bg-success'], 'unsubscribed' => ['Left', 'badge text-bg-secondary']];
// The row's place, carried by its forms so the redirect comes back to it.
$here    = '<input type="hidden" name="list" value="' . $e($list) . '"><input type="hidden" name="status" value="' . $e($status) . '">'
         . '<input type="hidden" name="q" value="' . $e($search) . '"><input type="hidden" name="page" value="' . $page . '">'; @endphp
<div class="container-fluid py-4">
    <h2 class="mb-3">Mailing lists</h2>

    @if ($lists === [])
        <p class="text-muted small">No opt-in list is registered. A list is a <code>MailType</code> with <code>optIn: true</code> — see the Email Guide.</p>
    @else
    <div class="row g-3 mb-4">
        @php foreach ($lists as $name): $c = $counts[$name] ?? []; @endphp
            <a href="{{ $link(['list' => $name, 'status' => '', 'q' => '']) }}" class="col-6 col-lg-3 card p-3{!! $name === $list ? ' border-primary' : '' !!}" style="text-decoration:none;color:inherit">
                <div class="fw-semibold mb-1">{{ $name }}</div>
                @foreach ($states as $state => [$label, $badge])
                    <div class="text-muted small">{!! $label !!}: <strong>{!! (int) ($c[$state] ?? 0) !!}</strong></div>
                @endforeach
            </a>
        @endforeach
    </div>

    <form method="get" data-pf-tool="find_list_subscribers" data-pf-tool-description="Find the addresses on the mailing list this page shows, by words in the address and by their state (subscribed, unsubscribed, bounced…)." action="{!! adminUrl('MailingLists') !!}" class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <input type="hidden" name="list" value="{{ $list }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search addresses" class="form-control form-control-sm" style="max-width:260px">
        <select name="status" class="form-control form-control-sm" style="max-width:200px">
            <option value="">Every state</option>
            @foreach ($states as $state => [$label])
                <option value="{!! $state !!}" {!! $status === $state ? 'selected' : '' !!}>{!! $label !!}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
        <a href="{!! adminUrl('MailingLists/export') . '?list=' . rawurlencode($list) !!}" class="btn btn-sm btn-outline-secondary">Export confirmed (CSV)</a>
    </form>

    <table class="table table-hover">
        <thead><tr><th>Address</th><th>State</th><th>Source</th><th>Language</th><th>Asked</th><th>Confirmed</th><th>Left</th><th></th></tr></thead>
        <tbody>
        @php foreach ($rows as $row): $id = (int) $row['subscriberid']; [$label, $badge] = $states[$row['status']] ?? [$row['status'], 'badge text-bg-secondary']; @endphp
            <tr>
                <td>
                    {{ $row['email'] }}
                    @if (!empty($row['userid']))
                        <a href="{!! adminUrl('users/view/') . (int) $row['userid'] !!}" class="text-muted small">account</a>
                    @endif
                </td>
                <td><span class="{!! $badge !!}">{!! $label !!}</span></td>
                <td>{{ $row['source'] }}</td>
                <td>{{ $row['language'] }}</td>
                <td>{!! $when($row['created_at']) !!}</td>
                <td>{!! $when($row['confirmed_at']) !!}</td>
                <td>{!! $when($row['unsubscribed_at']) !!}</td>
                <td style="white-space:nowrap">
                    @if ($row['status'] === 'pending')
                        <form method="post" action="{!! adminUrl('MailingLists/resend/') . $id !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() . $here !!}<button type="submit" class="btn btn-sm btn-outline-secondary">Send confirmation again</button></form>
                    @endif
                    @if ($row['status'] !== 'unsubscribed')
                        <form method="post" action="{!! adminUrl('MailingLists/unsubscribe/') . $id !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() . $here !!}<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Take {{ $row['email'] }} off {{ $list }}?">Unsubscribe</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
        @if ($rows === [])
            <tr><td colspan="8" class="text-muted small">Nobody matches.</td></tr>
        @endif
        </tbody>
    </table>

    @if ($pages > 1)
        <div class="d-flex gap-2 align-items-center">
            @if ($page > 1)<a href="{{ $link(['page' => $page - 1]) }}" class="btn btn-sm btn-outline-secondary">Previous</a>@endif
            <span class="text-muted small">page {!! $page !!} of {!! $pages !!} · {!! $total !!} addresses</span>
            @if ($page < $pages)<a href="{{ $link(['page' => $page + 1]) }}" class="btn btn-sm btn-outline-secondary">Next</a>@endif
        </div>
    @endif
    @endif
</div>
