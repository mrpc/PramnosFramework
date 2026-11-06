@php /**
 * One mass message: what it says, who it goes to, and how far it got (Bootstrap theme).
 *
 * Variables:
 *   $this->message  — the row
 *   $this->types    — channel number => label
 *   $this->progress — {total, pending, delivered, failed}
 *   $this->audience — the criteria, in a sentence
 *
 * The send button lives here rather than on the compose form because queueing is the one
 * action on this screen that cannot be undone: it is a POST with the anti-CSRF token, and
 * the count next to it is what somebody reads before pressing it.
 */
$message  = is_array($this->message ?? null) ? $this->message : [];
$types    = is_array($this->types ?? null) ? $this->types : [];
$progress = is_array($this->progress ?? null) ? $this->progress : [];
$id       = (int) ($message['messageid'] ?? 0);
$status   = (int) ($message['status'] ?? 0);
$total    = (int) ($progress['total'] ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when     = static function ($value): string {
    $time = (int) $value;

    return $time > 0 ? localDateTime( $time) : '—';
}; @endphp
<div class="container py-4">
    @php $this->activeNav = 'massmessages'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <h2 class="text-lg font-semibold">{{ $message['subject'] ?? '(no subject)' }}</h2>
        @if ($status === \Pramnos\Messaging\MassMessage::STATUS_SENT)
            <span class="pf-state pf-state-on">Sent</span>
        @elseif ($status === \Pramnos\Messaging\MassMessage::STATUS_SCHEDULED)
            <span class="pf-state">Scheduled {{ $when($message['scheduled'] ?? 0) }}</span>
        @elseif ($total > 0)
            <span class="pf-state">Sending</span>
        @else
            <span class="pf-state pf-state-off">Draft</span>
        @endif
        <span class="text-sm text-muted">{{ $types[(int) ($message['type'] ?? 0)] ?? '' }}</span>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="px-4 py-2 card-header small text-uppercase">Body</div>
                <pre class="p-4 text-xs whitespace-pre-wrap break-words">{{ $message['message'] ?? '' }}</pre>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="px-4 py-2 card-header small text-uppercase">Delivery</div>
                <table class="table table-sm">
                    <tbody>
                        <tr><td>Recipients</td><td class="text-right">{!! (int) $total !!}</td></tr>
                        <tr><td>Delivered</td><td class="text-right">{!! (int) ($progress['delivered'] ?? 0) !!}</td></tr>
                        <tr>
                            <td>Failed</td>
                            <td class="text-right">
                                @php $failed = (int) ($progress['failed'] ?? 0); @endphp
                                @if ($failed > 0)
                                    <span class="text-error font-medium">{!! $failed !!}</span>
                                @else
                                    0
                                @endif
                            </td>
                        </tr>
                        <tr><td>Pending</td><td class="text-right">{!! (int) ($progress['pending'] ?? 0) !!}</td></tr>
                        <tr><td>Created</td><td class="text-right text-xs">{{ $when($message['created'] ?? 0) }}</td></tr>
                    </tbody>
                </table>
                @if ($total > 0 && (int) ($progress['pending'] ?? 0) > 0)
                <div class="px-4 py-3 text-xs text-muted border-t border-base-300">
                    Delivery runs on the schedule, a batch at a time. Reload for the current
                    numbers — nothing is lost if a run is interrupted, because each recipient
                    is marked as it is attempted.
                </div>
                @endif
            </div>

            <div class="card p-3 mb-3">
                <div class="text-xs uppercase text-muted">Audience</div>
                <p class="text-sm">{{ $this->audience ?? 'every account' }}</p>

                @if ($total === 0)
                    <form method="post" action="{!! adminUrl('MassMessages/send/') . $id !!}">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            Queue for delivery
                        </button>
                    </form>
                    <p class="text-xs text-muted">
                        This writes one row per recipient and returns. It cannot be undone and
                        it cannot be done twice — a message that already has recipients is
                        refused rather than queued again.
                    </p>
                @else
                    <p class="text-xs text-muted">
                        Already queued. A second queueing would reach every person on the list
                        a second time, so it is refused.
                    </p>
                @endif
            </div>

            @if ($status !== \Pramnos\Messaging\MassMessage::STATUS_SENT && $total === 0)
            <div class="d-flex gap-2">
                <a href="{!! adminUrl('MassMessages/edit/') . $id !!}" class="btn btn-outline btn-sm">Edit</a>
                <form method="post" action="{!! adminUrl('MassMessages/delete/') . $id !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-error btn-sm">Delete</button></form>
            </div>
            @endif
        </div>
    </div>

    @php $trk = is_array($this->tracking ?? null) ? $this->tracking : []; if ((int) ($trk['tracked'] ?? 0) > 0): @endphp
    <div class="card p-3 mb-3">
        <h3 class="h6">Opens and clicks</h3>
        <p class="text-muted small">
            {!! (int) $trk['tracked'] !!} tracked ·
            <strong>{!! (int) $trk['opened'] !!}</strong> opened by a person ·
            {!! (int) $trk['proxyOnly'] !!} fetched only by a mailbox provider ·
            <strong>{!! (int) $trk['clicked'] !!}</strong> clicked ({!! (int) $trk['clicks'] !!} clicks)
        </p>
        <a href="{!! adminUrl('MassMessages/tracking/') . $id !!}" class="btn btn-sm btn-outline-secondary">Who opened and clicked</a>
    </div>
    @endif
</div>
