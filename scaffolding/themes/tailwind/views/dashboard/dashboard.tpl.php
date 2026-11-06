@php /**
 * Account Dashboard overview (Tailwind theme).
 *
 * Variables:
 *   $this->user            — User object
 *   $this->authorizedApps  — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->recentActivity  — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'dashboard'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h2 class="text-2xl font-bold text-base-content">{{ t('Account Dashboard') }}</h2>
            <p class="text-sm text-base-content/70 mt-1">
                {{ t('Welcome back, %s', (string) ($this->user->firstname ?? $this->user->username ?? '')) }}
            </p>
        </div>
        @if ($this->twoFactorEnabled)
            <span class="badge badge-success inline-flex items-center whitespace-nowrap h-auto py-1">
                &#10003; {{ t('2FA Active') }}
            </span>
        @else
            <span class="badge badge-warning inline-flex items-center whitespace-nowrap h-auto py-1">
                &#9888; {{ t('2FA Inactive') }}
            </span>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <!-- Main content -->
        <div class="md:col-span-3 space-y-6">

            <!-- Authorized apps -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex items-center justify-between px-4 py-3 border-b border-base-300">
                    <h3 class="font-semibold text-base-content">{{ t('Authorized Applications') }}</h3>
                    <a href="{!! sURL . $routeBase !!}/applications"
                       class="text-sm text-primary hover:underline">{{ t('Manage') }}</a>
                </div>
                @if (empty($this->authorizedApps))
                    <p class="px-4 py-4 text-sm text-base-content/60">{{ t('No authorized applications.') }}</p>
                @else
                    <ul class="divide-y divide-base-300">
                        @foreach (array_slice($this->authorizedApps, 0, 3) as $app)
                            <li class="flex items-center justify-between px-4 py-3">
                                <div>
                                    <span class="font-medium text-sm text-base-content">
                                        {{ $app['name'] }}
                                    </span>
                                    @if (!empty($app['description']))
                                        <p class="text-xs text-base-content/60 mt-0.5">
                                            {{ $app['description'] }}
                                        </p>
                                    @endif
                                </div>
                                <span class="text-xs text-base-content/60">
                                    {{ t((int) $app['token_count'] === 1 ? '%d token' : '%d tokens', (int) $app['token_count']) }}
                                </span>
                            </li>
                        @endforeach
                        @if (count($this->authorizedApps) > 3)
                            <li class="px-4 py-3 text-center text-sm text-primary">
                                <a href="{!! sURL . $routeBase !!}/applications">
                                    {{ t('+ %d more', count($this->authorizedApps) - 3) }}
                                </a>
                            </li>
                        @endif
                    </ul>
                @endif
            </div>

            <!-- Recent activity -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    {{ t('Recent Activity') }}
                </div>
                @if (empty($this->recentActivity))
                    <p class="px-4 py-4 text-sm text-base-content/60">{{ t('No recent activity.') }}</p>
                @else
                    <ul class="divide-y divide-base-300">
                        @foreach ($this->recentActivity as $entry)
                            <li class="px-4 py-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-base-content">
                                        {{ $entry['action'] }}
                                    </span>
                                    <span class="text-xs text-base-content/60">
                                        {{ $entry['created_at'] }}
                                    </span>
                                </div>
                                @if (!empty($entry['ip_address']))
                                    <span class="text-xs text-base-content/60">
                                        {{ t('from %s', (string) $entry['ip_address']) }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
