@php /**
 * Email detail view (Tailwind theme).
 *
 * Variables:
 *   $this->mail — email row array
 */
$mail = $this->mail ?? [];

/*
 * The column names, read from the table rather than guessed.
 *
 * This screen showed an empty recipient, a raw Unix timestamp and "No body content." for
 * every mail ever queued, because it read `recipient`/`mailto`, `body`/`mailbody` and
 * printed `date` as it found it. The `mails` table has `tomail`, `toname`, `content` and an
 * integer `date`. Nothing was broken in the mailer: the preview was reading fields that do
 * not exist, and `?? ''` turned each miss into a blank rather than into an error — which is
 * why it looked like a mailer that sends empty mail.
 *
 * The alternative names are kept as fallbacks for an application whose own table differs.
 */
$recipient = (string) ($mail['tomail'] ?? $mail['recipient'] ?? $mail['mailto'] ?? '');
$toName    = (string) ($mail['toname'] ?? '');
$body      = (string) ($mail['content'] ?? $mail['body'] ?? $mail['mailbody'] ?? '');

$rawDate = $mail['date'] ?? $mail['maildate'] ?? '';
$sentAt  = is_numeric($rawDate) && (int) $rawDate > 0
    ? date('j M Y, H:i', (int) $rawDate)
    : (string) $rawDate; @endphp
<div class="max-w-6xl mx-auto py-6 px-4">
    @php /*
     * A trail, like every other screen in this area. The preview had a Back button and no
     * breadcrumb, so it was the one page here that could not say where it sat — and "Back"
     * only helps somebody who arrived the way the button assumes.
     */
    $this->activeNav = 'emails_show';
    $this->insert('../partials/admin_breadcrumb'); @endphp
    <div class="flex items-center gap-3 mb-4">
        <a href="{{ (string) ($this->backUrl ?? adminUrl('Emails')) }}" class="btn btn-outline btn-xs">&larr; {{ t('Back') }}</a>
        <h2 >{{ t('Email #%d', (int) ($mail['id'] ?? 0)) }}</h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4">
        <div class="p-5">
            {{-- Two columns and label-above-value: four short facts were four full-width
                     rows, and the header pushed the message itself below the fold before the
                     message had lost any width at all. --}}
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
                @php if (!empty($this->origin)): $o = $this->origin; @endphp
                <div class="sm:col-span-2" data-field="origin">
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide">{{ t('Sent by') }}</dt>
                    <dd>@php $what = htmlspecialchars(t('rule %s, version %s, step %s', (string) ($o['name'] ?? '#' . $o['rule']), (string) $o['version'], $o['step']), ENT_QUOTES, 'UTF-8');
                        echo $o['url'] !== null ? '<a href="' . htmlspecialchars($o['url'], ENT_QUOTES, 'UTF-8') . '">' . $what . '</a>' : $what; @endphp</dd>
                </div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide">{{ t('Subject') }}</dt>
                    <dd class="break-words">{{ $mail['subject'] ?? '' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide">{{ t('To') }}</dt>
                    <dd class="break-all">{!! htmlspecialchars($recipient); echo $toName !== '' ? ' (' . htmlspecialchars($toName) . ')' : '' !!}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide">{{ t('Date') }}</dt>
                    <dd>{{ $sentAt }}
                        {!! $this->statusBadge ?? '' !!}
                    </dd>
                </div>
            </dl>
            @php /* Whether mail still goes to this address ({@see \Pramnos\Email\Bounces}): a bounce or a
               block stops every message to it, a password reset included. */
            $bounce = $this->bounce ?? null;
            if (is_array($bounce)): @endphp
                <form method="post" action="{{ $bounce['action'] }}" class="flex flex-wrap items-center gap-2 mt-4 text-sm">
                    <input type="hidden" name="_csrf_token" value="{{ $bounce['csrf'] }}">
                    @if ($bounce['refusal'] !== null)
                        <span class="text-error">{{ t('Mail to this address is not sent:') }} {{ $bounce['refusal'] }}.</span>
                        <button type="submit" name="op" value="clear" class="btn btn-outline btn-xs">{{ t('Send to it again') }}</button>
                    @else
                        <span class="text-success">{{ t('Mail to this address is sent.') }}</span>
                        <input type="text" name="reason" placeholder="{{ t('Why (optional)') }}" maxlength="255" class="input input-bordered input-xs">
                        <button type="submit" name="op" value="block" class="btn btn-outline btn-error btn-xs">{{ t('Stop all mail to it') }}</button>
                    @endif
                </form>
            @endif
        </div>
    </div>
    @php /*
     * Tabs, not a sidebar.
     *
     * The findings were beside the message, and they took a third of the width from the one
     * thing on the page that has a designed width: an email is laid out for about 600 pixels
     * and there is nothing useful to do with less. The result was a squeezed message with
     * "Not tracked." next to it — a permanent cost paid for a panel that is usually one line
     * saying nothing happened.
     *
     * So the message gets the page, and each finding gets the page when it is asked for. They
     * are consulted, not read: somebody opens this screen to see the mail, and goes to Tracking
     * when they have a question about tracking.
     *
     * Radio inputs rather than script — daisyUI's own tab pattern. This screen is inside the
     * administration area under a CSP, and a tab strip is not worth a nonce.
     */
    $report = $this->report ?? null;

    if ($report !== null) {
        $tracking = $report->tracking();
        $blocks   = $report->structuredData();
        $links    = $report->links();
        $unsub    = $report->unsubscribe();
        $when     = static fn (int $at): string => $at > 0 ? date('j M Y, H:i', $at) : '—';

        /*
         * The count in the label, because it is the answer for most messages.
         *
         * "Tracking" tells you to open the tab; "Tracking · off" tells you not to. A tab strip
         * whose labels carry no state is a strip somebody has to click through every time.
         */
        $trackingLabel = !empty($tracking['recorded'])
            ? t('Tracking') . ' · ' . (int) $tracking['clicks'] . ' ' . t('clicked')
            : t('Tracking') . ' · ' . t('off');
        $actionsLabel  = t('Gmail actions') . ' · ' . ($blocks === [] ? t('none') : count($blocks));
        $linksLabel    = t('Links') . ' · ' . count($links);
    } @endphp

    <div role="tablist" class="tabs tabs-lift">
        <input type="radio" name="pf-mail-tabs" role="tab" class="tab" aria-label="{{ t('Message') }}" checked>
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            @if ($body !== '')
                {{-- Sandboxed: the body is whatever was queued, and this screen is inside
                         the administration area. `allow-same-origin` is deliberately absent, so
                         the frame cannot reach this page's DOM or cookies — nor this page the
                         frame's, so its height is fixed and the reader drags it taller. --}}
                {{-- Popups allowed and nothing else: a link opens in a window of its own (the
                         preview carries `<base target="_blank">`), scripts stay off, and the
                         preview has no tracking pixel ({@see \Pramnos\Email\MessageReport::preview()}). --}}
                <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ (string) ($this->preview ?? $body) }}"
                    style="width:100%;border:none;height:600px;resize:vertical;overflow:auto"></iframe>
            @else
                <p class="text-base-content/70">{{ t('No body content.') }}</p>
            @endif
        </div>

        @if ($report !== null)
        @php /* A tracked message's figures change while this page is open — following one of its
                 links from the preview is a click — so they are fetched again when the reader
                 comes back to the page (`data-pf-refresh-on-return`, pf-utils.js). */
        $refresh = !empty($tracking['recorded']) ? ' data-pf-refresh-on-return' : ''; @endphp
        <input type="radio" name="pf-mail-tabs" role="tab" class="tab" id="pf-mail-tracking-tab"{!! $refresh !!}
               aria-label="{{ $trackingLabel }}">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5" id="pf-mail-tracking"{!! $refresh !!}>
            <div class="max-w-2xl text-sm space-y-3">
                @if (!empty($tracking['recorded']))
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-success">{!! (int) $tracking['clicks'] !!} {{ t('clicked') }}</span>
                        <span class="badge">{!! (int) $tracking['opens'] !!} {{ t('opened') }}</span>
                        <span class="badge badge-ghost">{!! (int) $tracking['proxyOpens'] !!} {{ t('prefetched') }}</span>
                    </div>
                    <p class="opacity-70 text-xs">
                        {{ t('Prefetched means a mailbox provider fetched the image on delivery. It is not somebody reading the message, which is why it is counted apart.') }}
                    </p>
                    <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-2 text-xs">
                        <div><dt class="inline font-semibold">{{ t('List') }}:</dt>
                            <dd class="inline">{{ (string) ($tracking['list'] ?? '') }}</dd></div>
                        <div><dt class="inline font-semibold">{{ t('First opened') }}:</dt>
                            <dd class="inline">{!! $when((int) ($tracking['firstOpenAt'] ?? 0)) !!}</dd></div>
                        <div><dt class="inline font-semibold">{{ t('Last opened') }}:</dt>
                            <dd class="inline">{!! $when((int) ($tracking['lastOpenAt'] ?? 0)) !!}</dd></div>
                        <div><dt class="inline font-semibold">{{ t('First clicked') }}:</dt>
                            <dd class="inline">{!! $when((int) ($tracking['firstClickAt'] ?? 0)) !!}</dd></div>
                    </dl>
                @else
                    <p class="opacity-70">{{ t('Not tracked.') }}</p>
                @endif

                <p class="text-xs opacity-70">
                    {{ t('Pixel in the body') }}: {{ !empty($tracking['pixel']) ? t('yes') : t('no') }} ·
                    {{ t('Wrapped links') }}: {!! (int) ($tracking['wrappedLinks'] ?? 0) !!}
                </p>

                @if (!empty($tracking['note']))
                    <div role="status" class="alert alert-warning py-2">
                        <span class="text-xs">{{ t($tracking['note']) }}</span>
                    </div>
                @endif
            </div>
        </div>

        <input type="radio" name="pf-mail-tabs" role="tab" class="tab"
               aria-label="{{ $actionsLabel }}">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            <div class="max-w-2xl text-sm">
                @if ($blocks === [])
                    <p class="opacity-70">{{ t('None. Gmail draws no button in the message list for this mail.') }}</p>
                @php else: foreach ($blocks as $block): @endphp
                    <div class="mb-3">
                        <span class="badge badge-outline badge-sm">{{ (string) $block['type'] }}</span>
                        @if (!empty($block['description']))
                            <span class="opacity-70 text-xs">{{ $block['description'] }}</span>
                        @endif
                        @if (!empty($block['actions']))
                            <ul class="mt-2 space-y-1 text-xs">
                                @foreach ($block['actions'] as $action)
                                    <li>
                                        <code>{{ (string) ($action['action'] ?? '') }}</code>
                                        {{ (string) ($action['name'] ?? '') }}
                                        @if (!empty($action['url']))
                                            <span class="block break-all opacity-70">{{ $action['url'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if (!empty($block['raw']))
                            <p class="text-error text-xs">{{ t('Unreadable JSON — Gmail ignores it silently.') }}</p>
                        @endif
                    </div>
                @endforeach @endif
                <p class="opacity-70 text-xs mt-2">
                    {{ t('Gmail shows none of this until the sending domain is registered with Google.') }}
                </p>
            </div>
        </div>

        <input type="radio" name="pf-mail-tabs" role="tab" class="tab"
               aria-label="{{ $linksLabel }}">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            <div class="max-w-3xl text-sm">
                @if ($links === [])
                    <p class="opacity-70">{{ t('No links in this message.') }}</p>
                @else
                    {{-- The width is worth having here: a wrapped link's real destination is
                             a long URL, and wrapping it over four lines is what made this panel
                             unreadable in a sidebar. --}}
                    <table class="table table-sm">
                        <tbody>
                        @foreach ($links as $link)
                            <tr>
                                <td class="break-all">{{ (string) ($link['destination'] ?? $link['url']) }}</td>
                                <td class="whitespace-nowrap text-right">
                                    @if (!empty($link['broken']))
                                        <span class="badge badge-error badge-xs">{{ t('token does not verify') }}</span>
                                    @elseif (!empty($link['wrapped']))
                                        <span class="badge badge-ghost badge-xs">{{ t('tracked') }}</span>
                                    @endif
                                    @if ((int) $link['count'] > 1)
                                        <span class="opacity-60">×{!! (int) $link['count'] !!}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                @if (!empty($unsub['note']))
                    <p class="opacity-70 text-xs mt-2">{{ t($unsub['note']) }}</p>
                @endif
            </div>
        </div>

        <input type="radio" name="pf-mail-tabs" role="tab" class="tab" aria-label="{{ t('Plain text') }}">
        <div role="tabpanel" class="tab-content bg-base-100 border-base-300 p-5">
            {{-- The half nobody looks at — and the half that used to arrive as the
                     stylesheet with every link removed. --}}
            <pre class="text-xs whitespace-pre-wrap break-words opacity-90 max-w-3xl">{{ $report->plainText() }}</pre>
        </div>
        @endif
    </div>
</div>
