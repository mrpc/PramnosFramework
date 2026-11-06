@php /**
 * Who opened and clicked one mass message (plain CSS theme).
 *
 * Variables:
 *   $this->message    — the row
 *   $this->summary    — Tracking::campaign(): tracked, opened, proxyOnly, clicked, clicks
 *   $this->links      — Tracking::campaignLinks(): url, clicks, people
 *   $this->recipients — Tracking::campaignRecipients() for $this->show
 *   $this->show       — '', 'opened', 'clicked' or 'unopened'
 *
 * An open by a mailbox provider is shown apart from an open by a person, never added to it:
 * Apple and Google fetch the image on delivery, and counted together they report a message
 * nobody read as widely read. A click is a person.
 */
$message    = is_array($this->message ?? null) ? $this->message : [];
$summary    = is_array($this->summary ?? null) ? $this->summary : [];
$links      = is_array($this->links ?? null) ? $this->links : [];
$recipients = is_array($this->recipients ?? null) ? $this->recipients : [];
$show       = (string) ($this->show ?? '');
$id         = (int) ($message['messageid'] ?? 0);
$e          = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when       = static fn ($t): string => $t ? localDateTime((int) $t) : '—';
$filters    = ['' => 'Everyone tracked', 'opened' => 'Opened', 'clicked' => 'Clicked', 'unopened' => 'Not opened by a person']; @endphp
<div class="container">
    <h2 class="mb-3">{{ $message['subject'] ?? '' }} — opens and clicks</h2>
    <p><a href="{!! adminUrl('MassMessages/view/') . $id !!}">Back to the message</a></p>

    <p class="pf-muted small">
        {!! (int) ($summary['tracked'] ?? 0) !!} tracked ·
        <strong>{!! (int) ($summary['opened'] ?? 0) !!}</strong> opened by a person ·
        {!! (int) ($summary['proxyOnly'] ?? 0) !!} fetched only by a mailbox provider, which is not somebody reading it ·
        <strong>{!! (int) ($summary['clicked'] ?? 0) !!}</strong> clicked ({!! (int) ($summary['clicks'] ?? 0) !!} clicks)
    </p>

    @if ($links !== [])
    <h3 class="mb-3">Links followed</h3>
    <table class="table table-hover">
        <thead><tr><th>Link</th><th>Clicks</th><th>People</th></tr></thead>
        <tbody>
        @foreach ($links as $link)
            <tr><td style="word-break:break-all">{{ $link['url'] }}</td><td>{!! (int) $link['clicks'] !!}</td><td>{!! (int) $link['people'] !!}</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <h3 class="mb-3">Recipients</h3>
    <div class="d-flex mb-3" style="gap:8px;flex-wrap:wrap">
        @foreach ($filters as $value => $label)
            <a href="{!! adminUrl('MassMessages/tracking/') . $id . ($value !== '' ? '?show=' . $value : '') !!}" class="{!! $value === $show ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-secondary' !!}">{!! $label !!}</a>
        @endforeach
    </div>
    <table class="table table-hover">
        <thead><tr><th>Recipient</th><th>Opens</th><th>Provider fetches</th><th>Clicks</th><th>First opened</th><th>First clicked</th></tr></thead>
        <tbody>
        @foreach ($recipients as $row)
            <tr>
                <td>{{ $row['recipient'] }}</td>
                <td>{!! (int) $row['opens'] !!}</td>
                <td>{!! (int) $row['proxy_opens'] !!}</td>
                <td>{!! (int) $row['clicks'] !!}</td>
                <td>{!! $when($row['first_open_at']) !!}</td>
                <td>{!! $when($row['first_click_at']) !!}</td>
            </tr>
        @endforeach
        @if ($recipients === [])
            <tr><td colspan="6" class="pf-muted small">Nobody.</td></tr>
        @endif
        </tbody>
    </table>
</div>
