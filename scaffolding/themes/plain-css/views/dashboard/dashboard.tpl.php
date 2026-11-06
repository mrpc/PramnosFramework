@php /**
 * Account Dashboard overview (plain-CSS theme).
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
<div class="page-section">

    @include('../partials/account_breadcrumb')

    <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div>
            <h2 style="margin:0">{{ t('Account Dashboard') }}</h2>
            <small style="color:#666">
                {{ t('Welcome back, %s', (string) ($this->user->firstname ?? $this->user->username ?? '')) }}
            </small>
        </div>
        @if ($this->twoFactorEnabled)
            <span class="badge badge-success">&#10003; {{ t('2FA Active') }}</span>
        @else
            <span class="badge badge-warning">&#9888; {{ t('2FA Inactive') }}</span>
        @endif
    </div>

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <!-- Main column -->
        <div>
            <!-- Authorized apps -->
            <div class="card" style="margin-bottom:20px">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                    <strong>{{ t('Authorized Applications') }}</strong>
                    <a href="{!! sURL . $routeBase !!}/applications" class="btn btn-sm">{{ t('Manage') }}</a>
                </div>
                <div class="card-body" style="padding:0">
                    @if (empty($this->authorizedApps))
                        <p style="padding:12px 16px;color:#666;margin:0">{{ t('No authorized applications.') }}</p>
                    @else
                        <ul style="list-style:none;margin:0;padding:0">
                            @foreach (array_slice($this->authorizedApps, 0, 3) as $app)
                                <li style="border-bottom:1px solid #f0f0f0;padding:10px 16px;display:flex;justify-content:space-between;align-items:center">
                                    <div>
                                        <strong>{{ $app['name'] }}</strong>
                                        @if (!empty($app['description']))
                                            <small style="display:block;color:#888">
                                                {{ $app['description'] }}
                                            </small>
                                        @endif
                                    </div>
                                    <small style="color:#888">
                                        {{ t((int) $app['token_count'] === 1 ? '%d token' : '%d tokens', (int) $app['token_count']) }}
                                    </small>
                                </li>
                            @endforeach
                            @if (count($this->authorizedApps) > 3)
                                <li style="padding:10px 16px;text-align:center">
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
                <div class="card-header"><strong>{{ t('Recent Activity') }}</strong></div>
                <div class="card-body" style="padding:0">
                    @if (empty($this->recentActivity))
                        <p style="padding:12px 16px;color:#666;margin:0">{{ t('No recent activity.') }}</p>
                    @else
                        <ul style="list-style:none;margin:0;padding:0">
                            @foreach ($this->recentActivity as $entry)
                                <li style="border-bottom:1px solid #f0f0f0;padding:10px 16px">
                                    <div style="display:flex;justify-content:space-between">
                                        <span>{{ $entry['action'] }}</span>
                                        <small style="color:#888">{{ $entry['created_at'] }}</small>
                                    </div>
                                    @if (!empty($entry['ip_address']))
                                        <small style="color:#aaa">
                                            {{ t('from %s', (string) $entry['ip_address']) }}
                                        </small>
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
