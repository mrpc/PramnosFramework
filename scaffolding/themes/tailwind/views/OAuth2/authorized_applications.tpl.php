@php /**
 * Authorized Applications page (Tailwind theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->routeBase      — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'applications'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')
    <h2 class="text-2xl font-bold text-base-content mb-6">{{ t('Authorized Applications') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success mb-4">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error mb-4">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3">
            @if (empty($this->authorizedApps))
                <div role="status" class="alert alert-info text-center">
                    {{ t('You have no authorized applications.') }}
                </div>
            @else
                <div class="card bg-base-100 shadow-sm">
                    @foreach ($this->authorizedApps as $app)
                        <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between px-4 py-4">
                            <div>
                                <p class="font-semibold text-base-content">{{ $app['name'] }}</p>
                                @if (!empty($app['description']))
                                    <p class="text-sm text-base-content/60 mt-0.5">{{ $app['description'] }}</p>
                                @endif
                                <p class="text-xs text-base-content/60 mt-1">
                                    {{ $app['token_count'] != 1 ? t('%d active tokens', (int) $app['token_count']) : t('%d active token', (int) $app['token_count']) }}
                                    @if (!empty($app['last_used']))
                                        &middot; {{ t('Last used %s', date('d M Y', (int) $app['last_used'])) }}
                                    @endif
                                </p>
                            </div>
                            <form method="post" action="{!! sURL . $routeBase !!}/revokeapplication"
                                  onsubmit="return confirm({!! json_encode(t('Revoke access for %s?', (string) $app['name']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!})">
                                <input type="hidden" name="client_id" value="{{ $app['apikey'] }}">
                                <button type="submit"
                                        class="btn btn-outline btn-error btn-sm">
                                    {{ t('Revoke') }}
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</div>
