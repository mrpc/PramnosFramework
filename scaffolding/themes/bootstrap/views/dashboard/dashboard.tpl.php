@php /**
 * Account Dashboard overview (Bootstrap theme).
 *
 * Variables:
 *   $this->user            — User object (current user)
 *   $this->authorizedApps  — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->recentActivity  — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'dashboard'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="mb-0">{{ t('Account Dashboard') }}</h2>
            <small class="text-muted">{{ t('Welcome back, %s', (string) ($this->user->firstname ?? $this->user->username ?? '')) }}</small>
        </div>
        <div>
            @if ($this->twoFactorEnabled)
                <span class="badge bg-success fs-6"><i class="bi bi-shield-check"></i> {{ t('2FA Active') }}</span>
            @else
                <span class="badge bg-warning text-dark fs-6"><i class="bi bi-shield-exclamation"></i> {{ t('2FA Inactive') }}</span>
            @endif
        </div>
    </div>

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <!-- Main column -->
        <div class="col-lg-9 col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center fw-semibold">
                    <span>{{ t('Authorized Applications') }}</span>
                    <a href="{!! sURL . $routeBase !!}/applications" class="btn btn-sm btn-outline-secondary">{{ t('Manage') }}</a>
                </div>
                <div class="card-body p-0">
                    @if (empty($this->authorizedApps))
                        <p class="text-muted p-3 mb-0">{{ t('No authorized applications.') }}</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach (array_slice($this->authorizedApps, 0, 3) as $app)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>{{ $app['name'] }}</strong>
                                        @if (!empty($app['description']))
                                            <small class="d-block text-muted">{{ $app['description'] }}</small>
                                        @endif
                                    </div>
                                    <small class="text-muted">
                                        {{ t((int) $app['token_count'] === 1 ? '%d token' : '%d tokens', (int) $app['token_count']) }}
                                    </small>
                                </li>
                            @endforeach
                            @if (count($this->authorizedApps) > 3)
                                <li class="list-group-item text-center">
                                    <a href="{!! sURL . $routeBase !!}/applications">
                                        {{ t('+ %d more', count($this->authorizedApps) - 3) }}
                                    </a>
                                </li>
                            @endif
                        </ul>
                    @endif
                </div>
            </div>

            <!-- Recent activity -->
            <div class="card">
                <div class="card-header fw-semibold">{{ t('Recent Activity') }}</div>
                <div class="card-body p-0">
                    @if (empty($this->recentActivity))
                        <p class="text-muted p-3 mb-0">{{ t('No recent activity.') }}</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($this->recentActivity as $entry)
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <span>{{ $entry['action'] }}</span>
                                        <small class="text-muted">{{ $entry['created_at'] }}</small>
                                    </div>
                                    @if (!empty($entry['ip_address']))
                                        <small class="text-muted">{{ t('from %s', (string) $entry['ip_address']) }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>
