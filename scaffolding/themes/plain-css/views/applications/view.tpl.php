@php /**
 * Application detail (read-only) view (plain-CSS theme).
 *
 * Variables:
 *   $this->app        — application row array
 *   $this->tokenStats — array: total, active, revoked
 *   $this->lastUsers  — array of recent token rows with userid, username, lastused, ipaddress, scope
 *   $this->webhooks     — this application's webhook endpoints (WebhookService::endpointsFor())
 *   $this->webhookTypes — the event types an endpoint may subscribe to
 *   $this->webhookRequiresHttps — whether an endpoint must be https (WebhookService::requiresHttps())
 */
$app        = $this->app ?? [];
$tokenStats = $this->tokenStats ?? ['total' => 0, 'active' => 0, 'revoked' => 0];
$lastUsers  = $this->lastUsers ?? [];
$appId      = (int) ($app['appid'] ?? 0);
$isActive   = (bool) ($app['status'] ?? 1);

$appTypeLabel = function (int $t): string {
    return match($t) {
        1 => 'Web', 2 => 'Mobile', 3 => 'Desktop', 4 => 'Service', 5 => 'IoT', default => 'General'
    };
};

$accessTypeLabel = function (int $t): string {
    return match($t) {
        1 => 'User Data', 2 => 'Read-Only', default => 'Full Access'
    };
}; @endphp
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
        <a href="{!! adminUrl('applications') !!}" class="btn btn-outline-secondary">&larr; Applications</a>
        <h2 style="margin:0">{{ $app['name'] ?? '' }}</h2>
        <span style="background:{!! $isActive ? '#198754' : '#dc3545' !!};color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">
            {!! $isActive ? 'Active' : 'Disabled' !!}
        </span>
    </div>

    @if (!empty($app['appid']) && \Pramnos\Auth\Application::needsARegisteredCallback($app))
        <div role="status" class="" style="background:#fff3cd;border:1px solid #ffe69c;padding:10px 16px;border-radius:4px;margin-bottom:12px;color:#664d03">
            <strong>This application cannot sign anyone in yet.</strong>
            It cannot keep a client secret &mdash; it is marked public, or it has no secret stored &mdash;
            and it has no registered redirect URI. For such a client the registered URI is the only
            thing tying a sign-in code to it, so the authorization endpoint refuses every sign-in
            until one is added. Add the exact callback URI(s) the application uses under
            <em>OAuth2 Redirect URI(s)</em>.
            <a href="{!! \Pramnos\Auth\Application::CALLBACK_GUIDE_URL !!}" target="_blank" rel="noopener">Why this is required &rarr;</a>
        </div>
    @endif

    <div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">
        <!-- Left: credentials + stats + actions -->
        <div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Credentials</div>
                <div style="padding:14px">
                    <div style="margin-bottom:12px">
                        <div style="font-size:11px;color:#888;margin-bottom:4px">Client ID (API Key)</div>
                        <div style="display:flex;gap:6px">
                            <input type="text" readonly style="flex:1;font-family:monospace;font-size:11px;border:1px solid #ddd;border-radius:3px;padding:5px 8px;background:#f9f9f9"
                                   value="{{ $app['apikey'] ?? '' }}">
                            <button style="border:1px solid #ddd;background:#f9f9f9;border-radius:3px;padding:5px 8px;cursor:pointer"
                                    data-copy-prev title="Copy">&#128203;</button>
                        </div>
                    </div>
                    <div>
{{-- The client secret is not shown. It is stored hashed, exactly as a password is,
so there is nothing here to display — and that is the point: a screen that can
show a credential is a screen that can leak one. The value exists in readable
form once, in the flash message that follows creating or rotating it.

A row written before hashing still holds a plaintext secret and converts itself
on the client's next successful authentication, so this stays true either way. --}}
                        <div style="font-size:11px;color:#888;margin-bottom:4px">Client Secret</div>
                        <div style="font-size:11px;color:#888;font-style:italic;margin-bottom:4px">
                            Stored hashed and not recoverable.
                        </div>
                        <form method="post" action="{!! adminUrl('applications/rotate/') . $appId !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                           class="btn btn-outline-secondary"
                           data-confirm="Generate a new client secret? The current one stops working immediately.">Rotate</button></form>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Token Statistics</div>
                @php $row = function(string $label, string $val) {; @endphp
                    <div style="display:flex;justify-content:space-between;padding:8px 14px;border-top:1px solid #f0f0f0;font-size:13px">
                        <span style="color:#666">{!! $label !!}</span>
                        <span>{!! $val !!}</span>
                    </div>
                @php }; @endphp
                @php $row('Total', '<a href="' . sURL . 'applications/tokens/' . $appId . '" style="font-weight:600">' . (int)($tokenStats['total'] ?? 0) . '</a>'); @endphp
                @php $row('Active', '<span style="color:#198754;font-weight:600">' . (int)($tokenStats['active'] ?? 0) . '</span>'); @endphp
                @php $row('Revoked', '<span style="color:#dc3545;font-weight:600">' . (int)($tokenStats['revoked'] ?? 0) . '</span>'); @endphp
            </div>

            <div class="card">
                <div style="padding:8px 14px;background:#f5f5f5;font-size:11px;font-weight:700;text-transform:uppercase;color:#666;letter-spacing:.05em">Actions</div>
                <div style="padding:12px;display:flex;flex-direction:column;gap:8px">
                    <a href="{!! adminUrl('applications' . '/edit/' . ($appId)) !!}" class="btn btn-primary" style="text-align:center">Edit Application</a>
                    <a href="{!! adminUrl('applications' . '/tokens/' . ($appId)) !!}" class="btn btn-outline-secondary" style="text-align:center">View Tokens</a>
                    <form method="post" action="{!! adminUrl('applications' . '/rotate/' . ($appId)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-warning" style="text-align:center"
                       data-confirm="Rotate the client secret? Existing tokens remain valid.">Rotate Secret</button></form>
                    <form method="post" action="{!! adminUrl('applications' . '/delete/' . ($appId)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline-danger" style="text-align:center"
                       data-confirm="Disable this application and revoke all active tokens?">Disable App</button></form>
                </div>
            </div>

        </div>

        <!-- Right: details + last users -->
        <div>

            <div class="card" style="margin-bottom:16px">
                <div style="padding:10px 16px;border-bottom:1px solid #eee;font-weight:600">Application Details</div>
                <div style="padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px;font-size:13px">
                    @php $field = function(string $label, string $val) {; @endphp
                        <div>
                            <div style="font-size:11px;color:#888;margin-bottom:2px">{!! $label !!}</div>
                            <div>{!! $val !!}</div>
                        </div>
                    @php }; @endphp
                    @php $field('App ID', '<code style="font-size:12px">' . $appId . '</code>'); @endphp
                    @php $field('Type', $appTypeLabel((int)($app['apptype'] ?? 0))); @endphp
                    @php $field('Access Type', $accessTypeLabel((int)($app['accesstype'] ?? 0))); @endphp
                    @php $field('API Version', htmlspecialchars($app['apiversion'] ?? 'v1', ENT_QUOTES, 'UTF-8')); @endphp
                    @php $field('App Version', ($app['appversion'] ?? '') !== '' ? htmlspecialchars($app['appversion'], ENT_QUOTES, 'UTF-8') : '<span style="color:#ccc">—</span>'); @endphp
                    <div>
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Public</div>
                        <span style="background:{!! (int)($app['public'] ?? 0) ? '#0dcaf0' : '#6c757d' !!};color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600">
                            {!! (int)($app['public'] ?? 0) ? 'Yes' : 'No' !!}
                        </span>
                    </div>
                    @php $field('Added', ($app['added'] ?? 0) > 0 ? localDateTime( (int)$app['added']) : '—'); @endphp
                    @if (!empty($app['description']))
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Description</div>
                        <div>{{ $app['description'] }}</div>
                    </div>
                    @endif
                    @if (!empty($app['callback']))
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Callback URL</div>
                        <div style="font-family:monospace;font-size:12px;word-break:break-all">{{ $app['callback'] }}</div>
                    </div>
                    @endif
                    @if (!empty($app['scope']))
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Scope</div>
                        <div style="font-family:monospace;font-size:12px">{{ $app['scope'] }}</div>
                    </div>
                    @endif
                    @if (!empty($app['organization']))
                    @php $field('Organization', htmlspecialchars($app['organization'], ENT_QUOTES, 'UTF-8')); @endphp
                    @endif
                    @if (!empty($app['public_key']))
                    <div style="grid-column:1/-1">
                        <div style="font-size:11px;color:#888;margin-bottom:2px">Public Key</div>
                        <pre style="background:#f5f5f5;border-radius:4px;padding:8px;font-size:11px;max-height:120px;overflow-y:auto;margin:0">{{ $app['public_key'] }}</pre>
                    </div>
                    @endif
                </div>
            </div>

            @if (!empty($lastUsers))
            <div class="card">
                <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
                    <span style="font-weight:600">Recent Users</span>
                    <a href="{!! adminUrl('applications' . '/tokens/' . ($appId)) !!}" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        <thead style="background:#f5f5f5">
                            <tr>
                                <th style="padding:7px 12px;text-align:left">User</th>
                                <th style="padding:7px 12px;text-align:left">Scope</th>
                                <th style="padding:7px 12px;text-align:left">IP</th>
                                <th style="padding:7px 12px;text-align:left">Last Used</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($lastUsers as $u)
                            <tr style="border-top:1px solid #f0f0f0">
                                <td style="padding:6px 12px">
                                    <a href="{!! adminUrl('users/view/') !!}{!! (int)($u['userid'] ?? 0) !!}">
                                        {{ $u['username'] ?? '—' }}
                                    </a>
                                </td>
                                <td style="padding:6px 12px;color:#888;font-size:12px">{{ $u['scope'] ?? '—' }}</td>
                                <td style="padding:6px 12px;color:#888;font-size:12px">{{ $u['ipaddress'] ?? '—' }}</td>
                                <td style="padding:6px 12px;font-size:12px">{!! ($u['lastused'] ?? 0) > 0 ? localDateTime((int)$u['lastused']) : '—' !!}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            @php /**
             * What this client has declared it understands.
             *
             * Pushed by the application itself through the capabilities endpoint,
             * usually from CI — so this is the application's own account of its
             * resources, scopes and ABAC condition keys, not something an
             * administrator typed. A permission grant names one of these, which is
             * why the list belongs next to the client rather than on a screen of
             * its own.
             *
             * A row an application has stopped declaring is shown struck through
             * rather than hidden: that is exactly what somebody is looking for when
             * a grant referring to it has stopped working.
             */
            $caps = $this->capabilities ?? []; @endphp
            <div style="margin-top:32px">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px">
                    <h3 class="font-semibold">Declared capabilities</h3>
                    @if (!empty($caps['synced_at']))
                        <span style="font-size:12px;color:#6b7280">
                            last pushed {{ (string) $caps['synced_at'] }}
                        </span>
                    @endif
                </div>

                @if (empty($caps['resources']) && empty($caps['conditions']))
                    <p style="color:#6b7280">
                        This client has not pushed a capabilities manifest. Until it does,
                        the server knows no resource or scope names for it.
                    </p>
                @else
                    @if (!empty($caps['resources']))
                        <table style="width:100%;font-size:14px;margin-bottom:16px">
                            <thead style="background:#f9fafb;font-size:12px;color:#6b7280;text-transform:uppercase">
                                <tr><th style="text-align:left;padding:8px">Resource</th><th style="text-align:left;padding:8px">Scopes</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($caps['resources'] as $resource)
                                <tr>
                                    <td style="padding:8px;vertical-align:top">
                                        <span class="font-mono{!! $resource['is_active'] ? '' : ' pf-inactive' !!}">
                                            {{ $resource['name'] }}
                                        </span>
                                        @if (!empty($resource['description']))
                                            <div style="font-size:12px;color:#6b7280">{{ (string) $resource['description'] }}</div>
                                        @endif
                                    </td>
                                    <td style="padding:8px;vertical-align:top">
                                        @if (empty($resource['scopes']))
                                            <span style="font-size:12px;color:#6b7280">no scopes declared</span>
                                        @else
                                            @foreach ($resource['scopes'] as $scope)
                                                <span class="pf-chip{!! $scope['is_active'] ? '' : ' pf-inactive' !!}"
                                                      title="{{ (string) ($scope['description'] ?? '') }}">
                                                    {{ $scope['name'] }}
                                                </span>
                                            @endforeach
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if (!empty($caps['conditions']))
                        <h4 style="font-size:14px;font-weight:600;margin-bottom:4px">Condition keys</h4>
                        <ul style="font-size:14px;list-style:none;padding:0">
                        @foreach ($caps['conditions'] as $condition)
                            <li class="{!! $condition['is_active'] ? '' : ' pf-inactive' !!}">
                                <span class="font-mono">{{ $condition['key'] }}</span>
                                <span style="font-size:12px;color:#6b7280">
                                    {{ $condition['value_type'] }}
                                    @if (!empty($condition['description']))
                                        — {{ (string) $condition['description'] }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            @php /**
             * Where this application's events are delivered.
             *
             * One row per event type. "Set by" says whose address it is: the
             * application's own, registered through the API and checked against the
             * private-network settings on every delivery — or an administrator's, entered
             * below and delivered to as written. Adding a type that already has an
             * endpoint replaces it, with a new secret.
             */
            $webhooks     = $this->webhooks ?? [];
            $webhookTypes = $this->webhookTypes ?? [];
            $httpsOnly    = $this->webhookRequiresHttps ?? true;
            $csrf         = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); @endphp
            <div id="webhooks" style="margin-top:24px">
                <h3 class="font-semibold">Webhooks</h3>

                @if (empty($webhooks))
                    <p style="font-size:13px;color:#6b7280">
                        No endpoints. The application can register its own through
                        <code>/Webhook/register</code>, or you can add one here.
                    </p>
                @else
                    <table style="width:100%;font-size:14px;margin-bottom:16px">
                        <thead style="background:#f9fafb;font-size:12px;color:#6b7280;text-transform:uppercase">
                            <tr><th style="text-align:left;padding:8px">Event</th><th style="text-align:left;padding:8px">Endpoint</th><th style="text-align:left;padding:8px">Set by</th><th style="text-align:left;padding:8px">Deliveries</th><th style="text-align:left;padding:8px"></th></tr>
                        </thead>
                        <tbody>
                        @foreach ($webhooks as $hook)
                            @php $hookId = (int) $hook['webhook_id']; $ev = $hook['events'] ?? []; @endphp
                            <tr>
                                <td style="padding:8px;vertical-align:top;word-break:break-all" class="font-mono">{{ (string) $hook['webhook_type'] }}</td>
                                <td style="padding:8px;vertical-align:top;word-break:break-all" class="font-mono">{{ (string) $hook['endpoint_url'] }}</td>
                                <td style="padding:8px;vertical-align:top;word-break:break-all">
                                    @if (($hook['registered_by'] ?? '') === 'admin')
                                        <span class="pf-chip">Administrator</span>
                                    @else
                                        <span class="pf-chip pf-inactive">Application</span>
                                    @endif
                                </td>
                                <td style="padding:8px;vertical-align:top;word-break:break-all">
                                    {!! (int) ($ev['sent'] ?? 0) !!} sent ·
                                    {!! (int) ($ev['pending'] ?? 0) !!} pending ·
                                    {!! (int) ($ev['failed'] ?? 0) !!} failed
                                </td>
                                <td style="padding:8px;text-align:right;white-space:nowrap">
                                    <form method="post" style="display:inline" action="{!! adminUrl('applications/webhooksecret/' . $hookId) !!}">
                                        {!! $csrf !!}
                                        <input type="hidden" name="appid" value="{!! $appId !!}">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Show secret</button>
                                    </form>
                                    <form method="post" style="display:inline" action="{!! adminUrl('applications/webhookrotate/' . $hookId) !!}">
                                        {!! $csrf !!}
                                        <input type="hidden" name="appid" value="{!! $appId !!}">
                                        <button type="submit" class="btn btn-sm btn-outline-warning"
                                                data-confirm="Issue a new signing secret? The current one stops verifying immediately.">Regenerate</button>
                                    </form>
                                    <form method="post" style="display:inline" action="{!! adminUrl('applications/webhookdelete/' . $hookId) !!}">
                                        {!! $csrf !!}
                                        <input type="hidden" name="appid" value="{!! $appId !!}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-confirm="Remove this endpoint? Events not yet delivered to it will not be.">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                <form method="post" action="{!! adminUrl('applications/webhook') !!}" style="border:1px solid #e5e7eb;border-radius:8px;padding:12px">
                    {!! $csrf !!}
                    <input type="hidden" name="appid" value="{!! $appId !!}">
                    <label style="display:block;margin-bottom:8px;font-size:14px">Event
                        <select name="webhook_type" class="form-select" required>
                            @foreach ($webhookTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label style="display:block;margin-bottom:8px;font-size:14px">Endpoint URL
                        <input type="url" name="endpoint_url" class="form-control font-mono"
                               placeholder="https://app.internal/hooks" pattern="{!! $httpsOnly ? 'https://.*' : 'https?://.*' !!}" required>
                    </label>
                    <label style="display:block;margin-bottom:8px;font-size:14px">Signing secret
                        <input type="text" name="secret" class="form-control font-mono" autocomplete="off" placeholder="Leave empty to generate one">
                    </label>
                    <p style="font-size:13px;color:#6b7280">
                        An address entered here is delivered to as written, including one on a
                        private network.{!! $httpsOnly ? ' It must be https.' : '' !!} A type that already has an endpoint is replaced.
                    </p>
                    <button type="submit" class="btn btn-primary btn-sm">Save endpoint</button>
                </form>
            </div>

        </div>
    </div>
</div>
