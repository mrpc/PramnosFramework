@php /**
 * "Is it you trying to sign in?" — the page a trusted phone answers on (Tailwind theme).
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
$where    = $country !== '' ? $country : t('an unknown place'); @endphp
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm"{!! $primary !== '' ? ' style="--color-primary:' . $primary . '"' : '' !!}>
        @include('../partials/auth_brand')
        @if ($approval === null)
            <h1 class="text-2xl font-semibold mb-2">{{ t('This cannot be answered here') }}</h1>
            <p class="text-sm text-base-content/70">
                {{ t('Sign-ins are approved from a device you trust, signed in to your account. Open the notification on that device, or sign in there first.') }}
            </p>
        @elseif ($result === 'approved' || ($result === '' && ($approval['decision'] ?? null) === 'approved'))
            <h1 class="text-2xl font-semibold mb-2">{{ t("You're signing in") }}</h1>
            <p class="text-sm text-base-content/70">{{ t('The other device will finish signing in now. You can close this.') }}</p>
        @elseif ($result === 'denied' || ($result === '' && ($approval['decision'] ?? null) === 'denied'))
            <h1 class="text-2xl font-semibold mb-2">{{ t('Sign-in refused') }}</h1>
            <p class="text-sm text-base-content/70 mb-3">
                {{ t('Nobody got in. But whoever tried used your password, so somebody else knows it.') }}
            </p>
            <a href="{!! $base !!}/changepassword" class="btn btn-primary w-full">{{ t('Change your password') }}</a>
        @elseif (empty($approval['open']))
            <h1 class="text-2xl font-semibold mb-2">{{ t('This request has expired') }}</h1>
            <p class="text-sm text-base-content/70">{{ t('If you are signing in, send a new notification from the other device.') }}</p>
        @else
            <h1 class="text-2xl font-semibold mb-1">{{ t('Is it you trying to sign in?') }}</h1>
            <dl class="text-sm my-4 space-y-1">
                <div class="flex justify-between gap-4"><dt class="text-base-content/60">{{ t('Device') }}</dt><dd class="text-right">{{ (string) $approval['browser'] }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-base-content/60">{{ t('Where') }}</dt><dd class="text-right">{{ $where }}@if (!empty($approval['ip'])) · {{ (string) $approval['ip'] }}@endif</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-base-content/60">{{ t('When') }}</dt><dd class="text-right">{!! date('H:i', (int) $approval['created_at']) !!}</dd></div>
            </dl>

            @if (($this->error ?? '') === 'invalid_token')
                <div role="alert" class="alert alert-error mb-4">{{ t('This page expired. Open the notification again.') }}</div>
            @endif

            @if ($approval['choices'] !== [])
                <p class="text-sm mb-3">{{ t('Pick the number shown on the screen you are signing in on.') }}</p>
                <form method="POST" action="{!! $base !!}/approve" class="grid grid-cols-3 gap-3 mb-4">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="hidden" name="token" value="{!! $token !!}">
                    <input type="hidden" name="decision" value="approved">
                    @foreach ($approval['choices'] as $choice)
                        <button type="submit" name="number" value="{!! (int) $choice !!}"
                                class="btn btn-outline h-16 text-2xl font-bold">{!! (int) $choice !!}</button>
                    @endforeach
                </form>
            @else
                <form method="POST" action="{!! $base !!}/approve" class="mb-3">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <input type="hidden" name="token" value="{!! $token !!}">
                    <input type="hidden" name="decision" value="approved">
                    <button type="submit" class="btn btn-primary w-full">{{ t("Yes, it's me") }}</button>
                </form>
            @endif

            <form method="POST" action="{!! $base !!}/approve">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <input type="hidden" name="token" value="{!! $token !!}">
                <input type="hidden" name="decision" value="denied">
                <button type="submit" class="btn btn-ghost w-full text-error">{{ t("No, it's not me") }}</button>
            </form>
        @endif
    </div>
</div>
