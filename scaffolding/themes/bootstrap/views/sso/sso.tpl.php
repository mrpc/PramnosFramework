{{-- Single Sign-On status page (Bootstrap theme).

Variables:
  $this->header      — Page heading
  $this->isLoggedIn  — bool
  $this->user        — User object (username, email) — set when logged in
  $this->activeApps  — array[] {name, website_url} — authorized apps --}}
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            @include('../partials/auth_brand')
            <h2 class="mb-4">{{ $this->header ?? t('Single Sign-On') }}</h2>

            @if ($this->isLoggedIn ?? false)
                <div role="status" class="alert alert-success">
                    <strong>&#10003; {{ t('Signed In') }}</strong> — {!! sprintf(htmlspecialchars(t('Signed in as %s'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars($this->user->username ?? '', ENT_QUOTES, 'UTF-8') . '</strong>') !!}
                    ({{ $this->user->email ?? '' }})
                </div>

                @if (!empty($this->activeApps))
                    <h5 class="mb-2">{{ t('Active Applications') }}</h5>
                    <ul class="list-group mb-3">
                        @foreach ($this->activeApps as $app)
                            <li class="list-group-item">
                                <strong>{{ $app['name'] }}</strong>
                                @if (!empty($app['website_url']))
                                    <br><small><a href="{{ $app['website_url'] }}" target="_blank">{{ $app['website_url'] }}</a></small>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a href="{!! sURL . rawurlencode((string) ($this->routeBase ?? 'Account')) !!}" class="btn btn-primary me-2">{{ t('Your account') }}</a>
                <a href="{!! sURL !!}login/logout" class="btn btn-outline-danger">{{ t('Sign Out') }}</a>
            @else
                <div class="alert alert-secondary">
                    <strong>&#10007; {{ t('Not Signed In') }}</strong> — {{ t('You are not currently signed in to any application.') }}
                </div>
                <a href="{!! sURL !!}login" class="btn btn-primary me-2">{{ t('Sign In') }}</a>
                @if ($this->registrationOpen ?? true)
                    <a href="{!! sURL !!}register" class="btn btn-outline-secondary">{{ t('Create Account') }}</a>
                @endif
            @endif
        </div>
    </div>
</div>
