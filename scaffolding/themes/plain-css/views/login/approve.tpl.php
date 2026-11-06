@php /**
 * "Is it you trying to sign in?" — the page a trusted phone answers on (plain-CSS theme).
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::approve):
 *   $this->routeBase, $this->brand, $this->token,
 *   $this->approval  the ask as PushApprovals::forAnswering() gives it, or null when this
 *                    browser may not answer it (not signed in as the account, not trusted)
 *   $this->result    after a POST: approved | denied | expired | invalid
 *   $this->error     invalid_token when the form's session token failed
 *
 * An ask that needs the number shows three and no Yes: the right one is on the screen of the
 * browser signing in, and picking it is the approval. A plain Yes is only for an ask that
 * needs none — a browser the account trusted before, with nothing unusual about the attempt.
 */
$brand    = $this->brand ?? \Pramnos\Auth\Brand::current();
// The brand's colour only where one was set: otherwise the theme's own primary, light or dark.
$primary  = ($brand['primary_color_set'] ?? true) ? htmlspecialchars((string) ($brand['primary_color'] ?? ''), ENT_QUOTES) : '';
$base     = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
$approval = $this->approval ?? null;
$result   = (string) ($this->result ?? '');
$token    = htmlspecialchars((string) ($this->token ?? ''), ENT_QUOTES);
$country  = $approval !== null ? (string) ($approval['country'] ?? '') : '';
$where    = $country !== '' ? $country : t('an unknown place');
$btnStyle = 'width:100%' . ($primary !== '' ? ';background-color:' . $primary . ';border-color:' . $primary : '');
$row      = 'display:flex;justify-content:space-between;gap:16px;margin:0 0 4px'; @endphp
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        @include('../partials/auth_brand')
        <div class="card-body" style="padding:24px">
            @if ($approval === null)
                <h2 style="margin:0 0 8px;font-size:1.25rem">{{ t('This cannot be answered here') }}</h2>
                <p style="font-size:14px;color:#666">
                    {{ t('Sign-ins are approved from a device you trust, signed in to your account. Open the notification on that device, or sign in there first.') }}
                </p>
            @elseif ($result === 'approved' || ($result === '' && ($approval['decision'] ?? null) === 'approved'))
                <h2 style="margin:0 0 8px;font-size:1.25rem">{{ t('You\'re signing in') }}</h2>
                <p style="font-size:14px;color:#666">{{ t('The other device will finish signing in now. You can close this.') }}</p>
            @elseif ($result === 'denied' || ($result === '' && ($approval['decision'] ?? null) === 'denied'))
                <h2 style="margin:0 0 8px;font-size:1.25rem">{{ t('Sign-in refused') }}</h2>
                <p style="font-size:14px;color:#666;margin-bottom:12px">
                    {{ t('Nobody got in. But whoever tried used your password, so somebody else knows it.') }}
                </p>
                <a href="{!! $base !!}/changepassword" class="btn" style="{!! $btnStyle !!};display:block;text-align:center;box-sizing:border-box">{{ t('Change your password') }}</a>
            @elseif (empty($approval['open']))
                <h2 style="margin:0 0 8px;font-size:1.25rem">{{ t('This request has expired') }}</h2>
                <p style="font-size:14px;color:#666">{{ t('If you are signing in, send a new notification from the other device.') }}</p>
            @else
                <h2 style="margin:0 0 4px;font-size:1.25rem">{{ t('Is it you trying to sign in?') }}</h2>
                <dl style="font-size:14px;margin:16px 0">
                    <div style="{!! $row !!}"><dt style="color:#666">{{ t('Device') }}</dt><dd style="margin:0;text-align:right">{{ (string) $approval['browser'] }}</dd></div>
                    <div style="{!! $row !!}"><dt style="color:#666">{{ t('Where') }}</dt><dd style="margin:0;text-align:right">{{ $where }}@if (!empty($approval['ip'])) · {{ (string) $approval['ip'] }}@endif</dd></div>
                    <div style="{!! $row !!}"><dt style="color:#666">{{ t('When') }}</dt><dd style="margin:0;text-align:right">{!! date('H:i', (int) $approval['created_at']) !!}</dd></div>
                </dl>

                @if (($this->error ?? '') === 'invalid_token')
                    <div role="alert" class="alert alert-danger">{{ t('This page expired. Open the notification again.') }}</div>
                @endif

                @if ($approval['choices'] !== [])
                    <p style="font-size:14px;margin-bottom:12px">{{ t('Pick the number shown on the screen you are signing in on.') }}</p>
                    <form method="POST" action="{!! $base !!}/approve" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="token" value="{!! $token !!}">
                        <input type="hidden" name="decision" value="approved">
                        @foreach ($approval['choices'] as $choice)
                            <button type="submit" name="number" value="{!! (int) $choice !!}"
                                    class="btn" style="height:64px;font-size:24px;font-weight:700;background:#fff;color:#111;border:1px solid #ccc">{!! (int) $choice !!}</button>
                        @endforeach
                    </form>
                @else
                    <form method="POST" action="{!! $base !!}/approve" style="margin-bottom:8px">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <input type="hidden" name="token" value="{!! $token !!}">
                        <input type="hidden" name="decision" value="approved">
                        <button type="submit" class="btn" style="{!! $btnStyle !!}">{{ t("Yes, it's me") }}</button>
                    </form>
                @endif

                <form method="POST" action="{!! $base !!}/approve">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="hidden" name="token" value="{!! $token !!}">
                    <input type="hidden" name="decision" value="denied">
                    <button type="submit" class="btn btn-sm" style="width:100%;background:transparent;color:#b91c1c;border-color:transparent">{{ t("No, it's not me") }}</button>
                </form>
            @endif
        </div>
    </div>
</div>
