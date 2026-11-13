@php /**
 * One push notification in full (plain-CSS theme).
 *
 * Variables:
 *   $this->row          — the push log row
 *   $this->outcome      — Log::outcome(): kind, label, explanation
 *   $this->subscription — Subscriptions::describe() of the device it went to, or null
 *   $this->history      — the latest attempts to that same device, newest first
 *   $this->receipt      — for a test notification, TestPush::status(): whether the device
 *                         itself confirmed it arrived; null for any other push
 *
 * The list says what happened; this page answers "why did this person not see it". The
 * device's own history is the evidence: deliveries to a browser the person says shows nothing
 * point at the device, a run of failures at the subscription.
 *
 * The endpoint is not shown because it is not stored: whoever holds it can push to that browser.
 */
$row     = is_array($this->row ?? null) ? $this->row : [];
$outcome = is_array($this->outcome ?? null) ? $this->outcome : \Pramnos\Push\Log::outcome($row);
$device  = is_array($this->subscription ?? null) ? $this->subscription : null;
$history = is_array($this->history ?? null) ? $this->history : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when    = static function (array $r): string {
    $time = \Pramnos\Push\Log::sentAt($r);

    return $time > 0 ? localDateTime($time) : '—';
};
$css = ['delivered' => 'pf-ok', 'gone' => 'pf-warn', 'not_sent' => 'pf-muted', 'busy' => 'pf-warn', 'failed' => 'pf-bad'];
$badge = static fn (array $what): string => '<span class="' . ($css[$what['kind']] ?? '') . '">'
    . htmlspecialchars($what['label'], ENT_QUOTES, 'UTF-8') . '</span>';
$receipt = is_array($this->receipt ?? null) ? $this->receipt : null;
$names   = is_array($this->names ?? null) ? $this->names : [];
$userId = (int) ($row['userid'] ?? 0);
$hash   = (string) ($row['endpoint_hash'] ?? ''); @endphp
<div class="page-section">
    @php $this->activeNav = 'pushlog'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <p><a href="{{ adminUrl('PushLog') }}">&larr; {{ t('All push notifications') }}</a></p>

    <h2>{{ t('Push notification #%s', (string) (int) ($row['pushid'] ?? 0)) }} {!! $badge($outcome) !!}</h2>

    <div role="status" class="alert">
        <strong>{{ $outcome['label'] }}.</strong>
        {{ $outcome['explanation'] }}
        @if ((string) ($row['error'] ?? '') !== '')
            <div class="pf-muted">{{ t('Reason:') }} {{ $row['error'] }}</div>
        @endif
    </div>

    @if ($receipt !== null)
        @if ($receipt['received_at'] !== null)
            <div role="status" class="alert">
                <strong>{{ t('The device received this test') }}</strong> {{ t('at %s.', localDateTime($receipt['received_at'])) }}
                {{ t('If nothing appeared on its screen, the cause is on the device: notifications for this site or for the browser, battery optimisation, or Do Not Disturb.') }}
            </div>
        @elseif ($receipt['expired'])
            <div role="status" class="alert">
                <strong>{{ t('The device never confirmed this test.') }}</strong>
                {{ t('It did not reach the browser: the phone may have been off or offline for the hour the receipt was accepted, or the browser was closed and is not woken for pushes.') }}
            </div>
        @else
            <div role="status" class="alert">
                <strong>{{ t('Waiting for the device to confirm.') }}</strong>
                {{ t('Its service worker sends a receipt the moment the push arrives; reload in a moment.') }}
            </div>
        @endif
    @endif

    <div class="card">
        <h3>{{ t('The message') }}</h3>
        <table class="table">
            <tbody>
                <tr><th>{{ t('Sent') }}</th><td>{{ $when($row) }}</td></tr>
                <tr><th>{{ t('Account') }}</th><td>
                    @if ($userId > 0)
                        <a href="{{ adminUrl('Users/view/') . $userId }}">{{ $names[$userId] ?? ('#' . $userId) }}</a>
                        · <a href="{{ adminUrl('PushLog') . '?userid=' . $userId }}">{{ t('every push to this account') }}</a>
                    @else—@endif
                </td></tr>
                <tr><th>{{ t('Notification') }}</th><td><code>{{ ($row['notification'] ?? '') ?: '—' }}</code></td></tr>
                <tr><th>{{ t('Title') }}</th><td>{{ ($row['title'] ?? '') ?: '—' }}</td></tr>
                <tr><th>{{ t('Text') }}</th><td>{!! nl2br($e(($row['body'] ?? '') ?: '—')) !!}</td></tr>
                <tr><th>{{ t('Opens') }}</th><td>
                    @if ((string) ($row['url'] ?? '') !== '')
                        <a href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['url'] }}</a>
                    @else—@endif
                </td></tr>
                <tr><th>{{ t('Tag') }}</th><td>{{ ($row['tag'] ?? '') ?: '—' }}
                    @if ((string) ($row['tag'] ?? '') !== '')
                        <span class="pf-muted">— {{ t('a newer notification with the same tag replaces this one on the device') }}</span>
                    @endif
                </td></tr>
                <tr><th>{{ t('Push service answer') }}</th><td>{!! (int) ($row['status'] ?? 0) > 0 ? 'HTTP ' . (int) $row['status'] : t('none') !!}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>{{ t('The device') }}</h3>
        @if ($hash === '')
            <p class="pf-muted">{{ t('None: nothing was sent, so no device was chosen.') }}</p>
        @elseif ($device === null)
            <p class="pf-muted">{{ t('This subscription no longer exists. It is deleted when the push service says it is gone, or when the person turns notifications off on that device.') }}</p>
        @else
            <table class="table">
                <tbody>
                    <tr><th>{{ t('Browser') }}</th><td>{{ $device['user_agent'] ?: t('not recorded') }}</td></tr>
                    <tr><th>{{ t('Subscribed') }}</th><td>{!! $device['created_at'] > 0 ? $e(localDateTime($device['created_at'])) : '—' !!}</td></tr>
                    <tr><th>{{ t('Last delivered') }}</th><td>{!! $device['last_success_at'] ? $e(localDateTime($device['last_success_at'])) : t('never') !!}</td></tr>
                    <tr><th>{{ t('Failures since') }}</th><td>{!! (int) $device['failure_count'] !!}</td></tr>
                </tbody>
            </table>
            <form method="post" action="{{ adminUrl('PushLog/test/') . (int) ($row['pushid'] ?? 0) }}" style="margin-top:12px">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <button type="submit" class="btn">{{ t('Send a test to this device') }}</button>
                <span class="pf-muted">
                    {{ t('A fixed notification; its page shows whether the device confirms it.') }}
                </span>
            </form>
        @endif
    </div>

    @if ($history !== [])
    <div class="card">
        <h3>{{ t('Recent pushes to this device') }}</h3>
        <table class="table">
            <thead><tr><th>{{ t('Sent') }}</th><th>{{ t('Title') }}</th><th>{{ t('Outcome') }}</th></tr></thead>
            <tbody>
                @foreach ($history as $other)
                    <tr{!! (int) ($other['pushid'] ?? 0) === (int) ($row['pushid'] ?? 0) ? ' class="pf-current"' : '' !!}>
                        <td><a href="{{ adminUrl('PushLog/view/') . (int) ($other['pushid'] ?? 0) }}">{{ $when($other) }}</a></td>
                        <td>{{ ($other['title'] ?? '') ?: '—' }}</td>
                        <td>{!! $badge(\Pramnos\Push\Log::outcome($other)) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
