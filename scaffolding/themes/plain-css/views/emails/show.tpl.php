@php /**
 * Email detail view (plain-CSS theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];
$h    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/*
 * The column names, read from the table rather than guessed — see the note in the tailwind
 * copy of this screen. `mails` has `tomail`, `toname`, `content` and an integer `date`.
 */
$recipient = (string) ($mail['tomail'] ?? '');
$body      = (string) ($mail['content'] ?? $mail['body'] ?? $mail['mailbody'] ?? '');
$rawDate   = $mail['date'] ?? '';
$sentAt    = is_numeric($rawDate) && (int) $rawDate > 0
    ? date('j M Y, H:i', (int) $rawDate)
    : (string) $rawDate; @endphp
<div class="page-section" style="max-width:860px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="{{ (string) ($this->backUrl ?? adminUrl('Emails')) }}" class="btn btn-sm btn-outline-secondary">&larr; {{ t('Back') }}</a>
        <h2 >{{ t('Email #%d', (int) ($mail['id'] ?? 0)) }}</h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <dl >
                <dt style="font-weight:600;min-width:120px;display:inline-block">{{ t('To') }}</dt>
                <dd >{{ $recipient }}</dd>
                @php if (!empty($this->origin)): $o = $this->origin; @endphp
                <dt style="font-weight:600;min-width:120px;display:inline-block">{{ t('Sent by') }}</dt>
                <dd data-field="origin">@php $what = htmlspecialchars(t('rule %s, version %s, step %s', (string) ($o['name'] ?? '#' . $o['rule']), (string) $o['version'], $o['step']), ENT_QUOTES, 'UTF-8');
                    echo $o['url'] !== null ? '<a href="' . htmlspecialchars($o['url'], ENT_QUOTES, 'UTF-8') . '">' . $what . '</a>' : $what; @endphp</dd>
                @endif
                <dt style="font-weight:600;min-width:120px;display:inline-block">{{ t('Subject') }}</dt>
                <dd >{{ $mail['subject'] ?? '' }}</dd>
                <dt style="font-weight:600;min-width:120px;display:inline-block">{{ t('Date') }}</dt>
                <dd >{{ $sentAt }}</dd>
                <dt style="font-weight:600;min-width:120px;display:inline-block">{{ t('Status') }}</dt>
                <dd >{!! $this->statusBadge ?? '' !!}</dd>
            </dl>
            @php /* Whether mail still goes to this address ({@see \Pramnos\Email\Bounces}): a bounce or a
               block stops every message to it, a password reset included. */
            $bounce = $this->bounce ?? null;
            if (is_array($bounce)): @endphp
                <form method="post" action="{{ $bounce['action'] }}" >
                    <input type="hidden" name="_csrf_token" value="{{ $bounce['csrf'] }}">
                    @if ($bounce['refusal'] !== null)
                        <span >{{ t('Mail to this address is not sent:') }} {{ $bounce['refusal'] }}.</span>
                        <button type="submit" name="op" value="clear" class="btn btn-sm">{{ t('Send to it again') }}</button>
                    @else
                        <span >{{ t('Mail to this address is sent.') }}</span>
                        <input type="text" name="reason" placeholder="{{ t('Why (optional)') }}" maxlength="255" >
                        <button type="submit" name="op" value="block" class="btn btn-sm">{{ t('Stop all mail to it') }}</button>
                    @endif
                </form>
            @endif
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">{!! $h(t('Body')) !!}</div>
        <div class="card-body" style="padding:16px">
            @if ($body !== '')
                {{-- Popups allowed and nothing else: a link opens in a window of its own (the
                         preview carries `<base target="_blank">`), scripts stay off, and the
                         preview has no tracking pixel ({@see \Pramnos\Email\MessageReport::preview()}). --}}
                <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ (string) ($this->preview ?? $body) }}"
                    style="width:100%;border:none;height:400px;resize:vertical;overflow:auto"></iframe>
            @else
                <p style="color:#888">{!! $h(t('No body content.')) !!}</p>
            @endif
        </div>
    </div>

@php /*
 * Everything else known about this message, read out of the stored body rather than out of the
 * sending code: a template that lost its unsubscribe link and one that kept it are identical
 * from there. In cards inside the page's column, as the rest of this screen; a link breaks
 * anywhere, because an unsubscribe link is one word of two hundred characters.
 */
$report = $this->report ?? null;

if ($report !== null):
    $tracking = $report->tracking();
    $when = static fn (int $at): string => $at > 0 ? date('j M Y, H:i', $at) : '—'; @endphp
    {{-- Fetched again when the reader comes back to the page, for a tracked message: following
             one of its links from the preview is a click (`data-pf-refresh-on-return`, pf-utils.js). --}}
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px" id="pf-mail-tracking"{!! !empty($tracking['recorded']) ? ' data-pf-refresh-on-return' : '' !!}>
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">{!! $h(t('Tracking')) !!}</div>
        <div class="card-body" style="padding:16px">
        @if (!empty($tracking['recorded']))
            <p>
                <strong>{!! (int) $tracking['clicks'] !!}</strong> {!! $h(t('clicked')) !!} &middot;
                <strong>{!! (int) $tracking['opens'] !!}</strong> {!! $h(t('opened')) !!} &middot;
                <strong>{!! (int) $tracking['proxyOpens'] !!}</strong> {!! $h(t('prefetched')) !!}
            </p>
            <p style="color:#888;font-size:0.875em">{!! $h(t('Prefetched means a mailbox provider fetched the image on delivery. It is not somebody reading the message, which is why it is counted apart.')) !!}</p>
            <p>
                {!! $h(t('First opened')) !!} {!! $h($when((int) ($tracking['firstOpenAt'] ?? 0))) !!},
                {!! $h(t('last opened')) !!} {!! $h($when((int) ($tracking['lastOpenAt'] ?? 0))) !!},
                {!! $h(t('first clicked')) !!} {!! $h($when((int) ($tracking['firstClickAt'] ?? 0))) !!}.
            </p>
        @else
            <p>{!! $h(t('Not tracked.')) !!}</p>
        @endif
            <p style="margin:0">
                {!! $h(t('Pixel in the body')) !!}: {!! $h(!empty($tracking['pixel']) ? t('yes') : t('no')) !!}.
                {!! $h(t('Wrapped links')) !!}: {!! (int) ($tracking['wrappedLinks'] ?? 0) !!}.
            </p>
        @if (!empty($tracking['note']))
            <p style="color:#888;font-size:0.875em;margin:8px 0 0"><em>{!! $h(t($tracking['note'])) !!}</em></p>
        @endif
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">{!! $h(t('Structured data (Gmail actions and highlights)')) !!}</div>
        <div class="card-body" style="padding:16px">
        @php $blocks = $report->structuredData(); @endphp
        @if ($blocks === [])
            <p>{!! $h(t('None. Gmail draws no button in the message list for this mail.')) !!}</p>
        @php else: foreach ($blocks as $block): @endphp
            <p>
                <code>{!! $h((string) $block['type']) !!}</code>
                {!! $h((string) ($block['description'] ?? $block['name'] ?? '')) !!}
            </p>
            @if (!empty($block['actions']))
                <ul>
                @foreach ($block['actions'] as $action)
                    <li style="overflow-wrap:anywhere">
                        <code>{!! $h((string) ($action['action'] ?? '')) !!}</code>
                        {!! $h((string) ($action['name'] ?? '')) !!}
                        @if (!empty($action['url']))&rarr; {!! $h($action['url']) !!}@endif
                    </li>
                @endforeach
                </ul>
            @endif
            @if (!empty($block['raw']))
                <p><strong>{!! $h(t('Unreadable JSON — Gmail ignores it silently.')) !!}</strong></p>
            @endif
        @endforeach @endif
            <p style="color:#888;font-size:0.875em;margin:0">{!! $h(t('Gmail shows none of this until the sending domain is registered with Google.')) !!}</p>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">{!! $h(t('Links, and where they really go')) !!}</div>
        <div class="card-body" style="padding:16px">
        @php $links = $report->links(); @endphp
        @if ($links === [])
            <p style="margin:0">{!! $h(t('No links in this message.')) !!}</p>
        @else
            @php // Fixed layout and breaking anywhere: an unsubscribe link is one 200-character word.; @endphp
            <table style="table-layout:fixed;width:100%;border-collapse:collapse">
                <thead><tr><th>{!! $h(t('In the markup')) !!}</th><th>{!! $h(t('Goes to')) !!}</th><th style="width:7rem"></th></tr></thead>
                <tbody>
                @foreach ($links as $link)
                    <tr>
                        <td style="overflow-wrap:anywhere">{!! $h((string) $link['url']) !!}</td>
                        <td style="overflow-wrap:anywhere">{!! $h((string) ($link['destination'] ?? $link['url'])) !!}</td>
                        <td>
                            @if (!empty($link['broken'])){!! $h(t('token does not verify')) !!}
                            @elseif (!empty($link['wrapped'])){!! $h(t('tracked')) !!}@endif
                            @if ((int) $link['count'] > 1) &times;{!! (int) $link['count'] !!}@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        @php $unsub = $report->unsubscribe(); @endphp
        @if (!empty($unsub['note']))
            <p style="color:#888;font-size:0.875em;margin:8px 0 0"><em>{!! $h(t($unsub['note'])) !!}</em></p>
        @endif
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">{!! $h(t('As a text-only client shows it')) !!}</div>
        <div class="card-body" style="padding:16px">
            {{-- The half nobody looks at, and the half that used to arrive as the stylesheet with every
                     link removed. A text part that does not match the HTML is a documented spam signal. --}}
            <pre style="margin:0;white-space:pre-wrap;overflow-wrap:anywhere">{!! $h($report->plainText()) !!}</pre>
        </div>
    </div>
@endif
</div>
