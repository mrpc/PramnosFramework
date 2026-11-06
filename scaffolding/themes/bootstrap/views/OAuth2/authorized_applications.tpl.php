@php /**
 * Authorized Applications page (Bootstrap theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->routeBase      — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'applications'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Authorized Applications') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            @if (empty($this->authorizedApps))
                <div role="status" class="alert alert-info">{{ t('You have no authorized applications.') }}</div>
            @else
                <div class="card">
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            @foreach ($this->authorizedApps as $app)
                                <li class="list-group-item">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-12 col-sm">
                                            <strong>{{ $app['name'] }}</strong>
                                            @if (!empty($app['description']))
                                                <p class="mb-0 text-muted small">{{ $app['description'] }}</p>
                                            @endif
                                            <small class="text-muted">
                                                {{ $app['token_count'] != 1 ? t('%d active tokens', (int) $app['token_count']) : t('%d active token', (int) $app['token_count']) }}
                                                @if (!empty($app['last_used']))
                                                    &nbsp;·&nbsp; {{ t('Last used: %s', date('d M Y', (int) $app['last_used'])) }}
                                                @endif
                                            </small>
                                        </div>
                                        <div class="col-auto">
                                            <form method="post" action="{!! sURL . $routeBase !!}/revokeapplication"
                                                  onsubmit="return confirm({{ json_encode(t('Revoke access for %s?', (string) $app['name']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                                                <input type="hidden" name="client_id" value="{{ $app['apikey'] }}">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ t('Revoke') }}</button>
                                            </form>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>

</div>
