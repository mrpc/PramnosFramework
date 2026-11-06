{{-- Single Sign-On status page (Tailwind theme).

Variables:
  $this->header      — Page heading
  $this->isLoggedIn  — bool
  $this->user        — User object (username, email) — set when logged in
  $this->activeApps  — array[] {name, website_url} — authorized apps --}}
<div class="container mx-auto py-8 px-4 max-w-lg">
    @include('../partials/auth_brand')
    <h2 class="text-2xl font-semibold mb-6">{{ $this->header ?? t('Single Sign-On') }}</h2>

    @if ($this->isLoggedIn ?? false)
        <div role="status" class="alert alert-success mb-5 block">
            <strong>&#10003; {{ t('Signed In') }}</strong> — {!! sprintf(htmlspecialchars(t('Signed in as %s (%s)'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars($this->user->username ?? '', ENT_QUOTES, 'UTF-8') . '</strong>', htmlspecialchars($this->user->email ?? '', ENT_QUOTES, 'UTF-8')) !!}
        </div>

        @if (!empty($this->activeApps))
            <h4 class="text-lg font-semibold mb-3">{{ t('Active Applications') }}</h4>
            <ul class="space-y-2 mb-5">
                @foreach ($this->activeApps as $app)
                    <li class="border border-base-300 rounded-lg p-3">
                        <strong class="text-sm">{{ $app['name'] }}</strong>
                        @if (!empty($app['website_url']))
                            <br><a href="{{ $app['website_url'] }}" class="text-xs text-primary hover:underline" target="_blank">{{ $app['website_url'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="flex gap-3">
            <a href="{!! sURL . rawurlencode((string) ($this->routeBase ?? 'Account')) !!}" class="btn btn-primary">{{ t('Your account') }}</a>
            <a href="{!! sURL !!}login/logout" class="btn btn-outline btn-error">{{ t('Sign Out') }}</a>
        </div>
    @else
        <div class="alert mb-5 block">
            <strong>&#10007; {{ t('Not Signed In') }}</strong> — {{ t('You are not currently signed in to any application.') }}
        </div>
        <div class="flex gap-3">
            <a href="{!! sURL !!}login" class="btn btn-primary">{{ t('Sign In') }}</a>
            @if ($this->registrationOpen ?? true)
                <a href="{!! sURL !!}register" class="btn btn-outline">{{ t('Create Account') }}</a>
            @endif
        </div>
    @endif
</div>
