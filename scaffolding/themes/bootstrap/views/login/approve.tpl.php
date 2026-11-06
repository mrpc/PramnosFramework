@php /**
 * "Is it you trying to sign in?" — the page a trusted phone answers on (Bootstrap theme).
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
$btnStyle = $primary !== '' ? 'background-color:' . $primary . ';border-color:' . $primary : ''; @endphp
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    @include('../partials/auth_brand')
                    @if ($approval === null)
                        <h1 class="h4 mb-2">{{ t('This cannot be answered here') }}</h1>
                        <p class="text-muted small">
                            {{ t('Sign-ins are approved from a device you trust, signed in to your account. Open the notification on that device, or sign in there first.') }}
                        </p>
                    @elseif ($result === 'approved' || ($result === '' && ($approval['decision'] ?? null) === 'approved'))
                        <h1 class="h4 mb-2">{{ t('You\'re signing in') }}</h1>
                        <p class="text-muted small">{{ t('The other device will finish signing in now. You can close this.') }}</p>
                    @elseif ($result === 'denied' || ($result === '' && ($approval['decision'] ?? null) === 'denied'))
                        <h1 class="h4 mb-2">{{ t('Sign-in refused') }}</h1>
                        <p class="text-muted small mb-3">
                            {{ t('Nobody got in. But whoever tried used your password, so somebody else knows it.') }}
                        </p>
                        <a href="{!! $base !!}/changepassword" class="btn btn-primary w-100" style="{!! $btnStyle !!}">{{ t('Change your password') }}</a>
                    @elseif (empty($approval['open']))
                        <h1 class="h4 mb-2">{{ t('This request has expired') }}</h1>
                        <p class="text-muted small">{{ t('If you are signing in, send a new notification from the other device.') }}</p>
                    @else
                        <h1 class="h4 mb-1">{{ t('Is it you trying to sign in?') }}</h1>
                        <dl class="row small my-3">
                            <dt class="col-4 text-muted fw-normal">{{ t('Device') }}</dt><dd class="col-8 text-end">{{ (string) $approval['browser'] }}</dd>
                            <dt class="col-4 text-muted fw-normal">{{ t('Where') }}</dt><dd class="col-8 text-end">{{ $where }}@if (!empty($approval['ip'])) · {{ (string) $approval['ip'] }}@endif</dd>
                            <dt class="col-4 text-muted fw-normal">{{ t('When') }}</dt><dd class="col-8 text-end mb-0">{!! date('H:i', (int) $approval['created_at']) !!}</dd>
                        </dl>

                        @if (($this->error ?? '') === 'invalid_token')
                            <div role="alert" class="alert alert-danger">{{ t('This page expired. Open the notification again.') }}</div>
                        @endif

                        @if ($approval['choices'] !== [])
                            <p class="small mb-3">{{ t('Pick the number shown on the screen you are signing in on.') }}</p>
                            <form method="POST" action="{!! $base !!}/approve" class="row g-3 mb-3">
                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                <input type="hidden" name="token" value="{!! $token !!}">
                                <input type="hidden" name="decision" value="approved">
                                @foreach ($approval['choices'] as $choice)
                                    <div class="col-4">
                                        <button type="submit" name="number" value="{!! (int) $choice !!}"
                                                class="btn btn-outline-secondary w-100 py-3 fs-3 fw-bold">{!! (int) $choice !!}</button>
                                    </div>
                                @endforeach
                            </form>
                        @else
                            <form method="POST" action="{!! $base !!}/approve" class="mb-2">
                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                <input type="hidden" name="token" value="{!! $token !!}">
                                <input type="hidden" name="decision" value="approved">
                                <button type="submit" class="btn btn-primary w-100" style="{!! $btnStyle !!}">{{ t("Yes, it's me") }}</button>
                            </form>
                        @endif

                        <form method="POST" action="{!! $base !!}/approve">
                            {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                            <input type="hidden" name="token" value="{!! $token !!}">
                            <input type="hidden" name="decision" value="denied">
                            <button type="submit" class="btn btn-link text-danger w-100">{{ t("No, it's not me") }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
