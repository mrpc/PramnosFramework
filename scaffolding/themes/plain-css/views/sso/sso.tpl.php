{{-- Single Sign-On status page (plain-CSS theme).

Variables:
  $this->header      — Page heading
  $this->isLoggedIn  — bool
  $this->user        — User object (username, email) — set when logged in
  $this->activeApps  — array[] {name, website_url} — authorized apps --}}
<div class="page-section">
    <h2 style="margin-bottom:20px">{{ $this->header ?? t('Single Sign-On') }}</h2>

    @if ($this->isLoggedIn ?? false)
        <div role="status" class="alert alert-info" style="margin-bottom:16px">
            <strong>&#10003; {{ t('Signed In') }}</strong> — {!! sprintf(htmlspecialchars(t('Signed in as %s (%s)'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars($this->user->username ?? '', ENT_QUOTES, 'UTF-8') . '</strong>', htmlspecialchars($this->user->email ?? '', ENT_QUOTES, 'UTF-8')) !!}
        </div>

        @if (!empty($this->activeApps))
            <h4 style="margin-bottom:12px">{{ t('Active Applications') }}</h4>
            <ul style="list-style:none;margin:0 0 16px;padding:0">
                @foreach ($this->activeApps as $app)
                    <li style="border:1px solid #eee;border-radius:4px;padding:10px 14px;margin-bottom:8px">
                        <strong>{{ $app['name'] }}</strong>
                        @if (!empty($app['website_url']))
                            <br><small><a href="{{ $app['website_url'] }}" target="_blank">{{ $app['website_url'] }}</a></small>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <a href="{!! sURL . rawurlencode((string) ($this->routeBase ?? 'Account')) !!}" class="btn">{{ t('Your account') }}</a>
        <a href="{!! sURL !!}login/logout" class="btn" style="margin-left:8px;background:#e74c3c;color:white">{{ t('Sign Out') }}</a>
    @else
        <div class="alert" style="background:#f5f5f5;border:1px solid #ddd;padding:12px 16px;border-radius:4px;margin-bottom:16px">
            <strong>&#10007; {{ t('Not Signed In') }}</strong> — {{ t('You are not currently signed in to any application.') }}
        </div>
        <a href="{!! sURL !!}login" class="btn">{{ t('Sign In') }}</a>
        @if ($this->registrationOpen ?? true)
            <a href="{!! sURL !!}register" class="btn" style="margin-left:8px;background:#f0f0f0;color:#333">{{ t('Create Account') }}</a>
        @endif
    @endif
</div>
