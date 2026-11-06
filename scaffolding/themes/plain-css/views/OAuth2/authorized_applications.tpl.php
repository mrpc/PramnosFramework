@php /**
 * Authorized Applications page (plain-CSS theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->routeBase      — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'applications'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Authorized Applications') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            @if (empty($this->authorizedApps))
                <div role="status" class="alert alert-info">{{ t('You have no authorized applications.') }}</div>
            @else
                <div class="card">
                    <div class="card-body" style="padding:0">
                        <ul style="list-style:none;margin:0;padding:0">
                            @foreach ($this->authorizedApps as $app)
                                <li class="pf-row-split" style="border-bottom:1px solid #f0f0f0;padding:14px 16px">
                                    <div>
                                        <strong>{{ $app['name'] }}</strong>
                                        @if (!empty($app['description']))
                                            <small style="display:block;color:#888">
                                                {{ $app['description'] }}
                                            </small>
                                        @endif
                                        <small style="color:#aaa">
                                            {{ $app['token_count'] != 1 ? t('%d active tokens', (int) $app['token_count']) : t('%d active token', (int) $app['token_count']) }}
                                            @if (!empty($app['last_used']))
                                                &middot; {{ t('Last used %s', date('d M Y', (int) $app['last_used'])) }}
                                            @endif
                                        </small>
                                    </div>
                                    <form method="post" action="{!! sURL . $routeBase !!}/revokeapplication"
                                          onsubmit="return confirm({{ json_encode(t('Revoke access for %s?', (string) $app['name']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) }})">
                                        <input type="hidden" name="client_id" value="{{ $app['apikey'] }}">
                                        <button type="submit" class="btn" style="border-color:#c00;color:#c00">{{ t('Revoke') }}</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>

</div>
