@php /**
 * Mass messages, newest first (Bootstrap theme).
 *
 * Variables:
 *   $this->messages — rows, each with a `progress` array {total, pending, delivered, failed}
 *   $this->types    — channel number => label
 *
 * The progress columns are the reason this screen exists rather than a list of subjects: a
 * send is queued and delivered on a timer, so "did it go out" is a question about recipient
 * rows and not about whether somebody pressed the button.
 */
$messages = is_array($this->messages ?? null) ? $this->messages : [];
$types    = is_array($this->types ?? null) ? $this->types : [];
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when     = static function ($value): string {
    $time = (int) $value;

    return $time > 0 ? localDateTime( $time) : '—';
}; @endphp
<div class="container py-4">
    @php $this->activeNav = 'massmessages'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <h2 class="text-lg font-semibold">Mass messages</h2>
        <span class="badge bg-secondary">{!! count($messages) !!}</span>
        <a href="{!! adminUrl('MassMessages/edit') !!}" class="btn btn-primary btn-sm ms-auto">
            {!! \Pramnos\Html\Icon::svg('edit') !!} New message
        </a>
    </div>

    @if ($messages === [])
    <div class="card p-4 text-muted small">
        Nothing has been sent from here yet. A mass message is composed, counted against its
        audience, and then queued — delivery happens on the schedule, a batch at a time, so a
        send of thousands is not a request somebody has to keep open.
    </div>
    @else
    <div class="card mb-3">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead class="bg-base-200 text-xs text-muted uppercase">
                    <tr>
                        <th>Subject</th>
                        <th>Channel</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th>Delivered</th>
                        <th>Failed</th>
                        <th>Pending</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($messages as $row)
                    @php $id       = (int) ($row['messageid'] ?? 0);
                    $progress = is_array($row['progress'] ?? null) ? $row['progress'] : [];
                    $status   = (int) ($row['status'] ?? 0);
                    $url      = adminUrl('MassMessages/view/') . $id; @endphp
                    <tr>
                        <td><a class="link" href="{!! $url !!}">{{ $row['subject'] ?? '(no subject)' }}</a></td>
                        <td>{{ $types[(int) ($row['type'] ?? 0)] ?? '—' }}</td>
                        <td class="text-xs whitespace-nowrap">{{ $when($row['created'] ?? 0) }}</td>
                        <td>
                            @if ($status === \Pramnos\Messaging\MassMessage::STATUS_SENT)
                                <span class="pf-state pf-state-on">Sent</span>
                            @elseif ($status === \Pramnos\Messaging\MassMessage::STATUS_SCHEDULED)
                                <span class="pf-state">Scheduled {{ $when($row['scheduled'] ?? 0) }}</span>
                            @elseif ((int) ($progress['total'] ?? 0) > 0)
                                <span class="pf-state">Sending</span>
                            @else
                                <span class="pf-state pf-state-off">Draft</span>
                            @endif
                        </td>
                        <td>{!! (int) ($progress['delivered'] ?? 0) !!}</td>
                        <td>
                            @php $failed = (int) ($progress['failed'] ?? 0); @endphp
                            @if ($failed > 0)
                                <span class="text-error font-medium">{!! $failed !!}</span>
                            @else
                                0
                            @endif
                        </td>
                        <td>{!! (int) ($progress['pending'] ?? 0) !!}</td>
                        <td class="text-right">
                            {!! \Pramnos\Html\Icon::link($url, 'view', 'Open this message') !!}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
