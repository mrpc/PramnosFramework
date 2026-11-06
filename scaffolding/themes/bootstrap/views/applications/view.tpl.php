@php /**
 * Application detail (read-only) view (Bootstrap theme).
 *
 * Variables:
 *   $this->app        — application row array (all fields from `applications` table)
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

$statusBadge = (int) ($app['status'] ?? 1) ? 'success' : 'danger';
$statusLabel = (int) ($app['status'] ?? 1) ? 'Active'  : 'Disabled';

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
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{!! adminUrl('applications') !!}" class="btn btn-sm btn-outline-secondary">&larr; Applications</a>
        <h2 class="mb-0">{{ $app['name'] ?? '' }}</h2>
        <span class="badge bg-{!! $statusBadge !!}">{!! $statusLabel !!}</span>
    </div>

    @if (!empty($app['appid']) && \Pramnos\Auth\Application::needsARegisteredCallback($app))
        <div role="status" class="alert alert-warning">
            <strong>This application cannot sign anyone in yet.</strong>
            It cannot keep a client secret &mdash; it is marked public, or it has no secret stored &mdash;
            and it has no registered redirect URI. For such a client the registered URI is the only
            thing tying a sign-in code to it, so the authorization endpoint refuses every sign-in
            until one is added. Add the exact callback URI(s) the application uses under
            <em>OAuth2 Redirect URI(s)</em>.
            <a href="{!! \Pramnos\Auth\Application::CALLBACK_GUIDE_URL !!}" target="_blank" rel="noopener">Why this is required &rarr;</a>
        </div>
    @endif

    <div class="row g-4">
        <!-- Left: key info + actions -->
        <div class="col-xl-4 col-lg-5">

            <div class="card mb-3">
                <div class="card-header fw-semibold small text-uppercase text-muted">Credentials</div>
                <div class="card-body">
                    <label class="form-label text-muted small">Client ID (API Key)</label>
                    <div class="input-group input-group-sm mb-3">
                        <input type="text" class="form-control font-monospace" readonly
                               value="{{ $app['apikey'] ?? '' }}">
                        <button class="btn btn-outline-secondary" type="button"
                                data-copy-prev
                                title="Copy">&#128203;</button>
                    </div>
{{-- The client secret is not shown. It is stored hashed, exactly as a password is,
so there is nothing here to display — and that is the point: a screen that can
show a credential is a screen that can leak one. The value exists in readable
form once, in the flash message that follows creating or rotating it.

A row written before hashing still holds a plaintext secret and converts itself
on the client's next successful authentication, so this stays true either way. --}}
                    <label class="form-label text-muted small">Client Secret</label>
                    <div class="text-muted small fst-italic mb-1">
                        Stored hashed and not recoverable.
                    </div>
                    <form method="post" action="{!! adminUrl('applications/rotate/') . $appId !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                       class="btn btn-outline-secondary btn-sm"
                       data-confirm="Generate a new client secret? The current one stops working immediately.">Rotate</button></form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold small text-uppercase text-muted">Token Statistics</div>
                <div class="list-group list-group-flush small">
                    <div class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Total Tokens</span>
                        <a href="{!! adminUrl('applications' . '/tokens/' . ($appId)) !!}" class="fw-semibold text-decoration-none">
                            {!! (int) ($tokenStats['total'] ?? 0) !!}
                        </a>
                    </div>
                    <div class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Active</span>
                        <span class="badge bg-success">{!! (int) ($tokenStats['active'] ?? 0) !!}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Revoked</span>
                        <span class="badge bg-danger">{!! (int) ($tokenStats['revoked'] ?? 0) !!}</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold small text-uppercase text-muted">Actions</div>
                <div class="card-body d-grid gap-2">
                    <a href="{!! adminUrl('applications' . '/edit/' . ($appId)) !!}" class="btn btn-primary btn-sm">Edit Application</a>
                    <a href="{!! adminUrl('applications' . '/tokens/' . ($appId)) !!}" class="btn btn-outline-secondary btn-sm">View Tokens</a>
                    <form method="post" action="{!! adminUrl('applications' . '/rotate/' . ($appId)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                       class="btn btn-outline-warning btn-sm"
                       data-confirm="Rotate the client secret? Existing tokens remain valid.">Rotate Secret</button></form>
                    <form method="post" action="{!! adminUrl('applications' . '/delete/' . ($appId)) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                       class="btn btn-outline-danger btn-sm"
                       data-confirm="Disable this application and revoke all active tokens?">Disable App</button></form>
                </div>
            </div>

        </div>

        <!-- Right: details + last users -->
        <div class="col-xl-8 col-lg-7">

            <div class="card mb-4">
                <div class="card-header fw-semibold">Application Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">App ID</div>
                            <code>{!! $appId !!}</code>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Type</div>
                            <div>{!! $appTypeLabel((int) ($app['apptype'] ?? 0)) !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Access Type</div>
                            <div>{!! $accessTypeLabel((int) ($app['accesstype'] ?? 0)) !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">API Version</div>
                            <div>{{ $app['apiversion'] ?? 'v1' }}</div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">App Version</div>
                            <div>{!! ($app['appversion'] ?? '') !== '' ? htmlspecialchars($app['appversion'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' !!}</div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Public</div>
                            <span class="badge bg-{!! (int) ($app['public'] ?? 0) ? 'info' : 'secondary' !!}">
                                {!! (int) ($app['public'] ?? 0) ? 'Yes' : 'No' !!}
                            </span>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small mb-1">Added</div>
                            <div>{!! ($app['added'] ?? 0) > 0 ? localDateTime((int) $app['added']) : '—' !!}</div>
                        </div>
                        @if (!empty($app['description']))
                        <div class="col-12">
                            <div class="text-muted small mb-1">Description</div>
                            <div>{{ $app['description'] }}</div>
                        </div>
                        @endif
                        @if (!empty($app['callback']))
                        <div class="col-12">
                            <div class="text-muted small mb-1">Callback URL</div>
                            <div class="font-monospace small text-break">{{ $app['callback'] }}</div>
                        </div>
                        @endif
                        @if (!empty($app['scope']))
                        <div class="col-12">
                            <div class="text-muted small mb-1">Scope</div>
                            <div class="font-monospace small">{{ $app['scope'] }}</div>
                        </div>
                        @endif
                        @if (!empty($app['organization']))
                        <div class="col-sm-6">
                            <div class="text-muted small mb-1">Organization</div>
                            <div>{{ $app['organization'] }}</div>
                        </div>
                        @endif
                        @if (!empty($app['url']))
                        <div class="col-sm-6">
                            <div class="text-muted small mb-1">URL</div>
                            <div class="small text-break">{{ $app['url'] }}</div>
                        </div>
                        @endif
                        @if (!empty($app['public_key']))
                        <div class="col-12">
                            <div class="text-muted small mb-1">Public Key</div>
                            <pre class="bg-light rounded p-2 small mb-0" style="max-height:120px;overflow-y:auto">{{ $app['public_key'] }}</pre>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            @if (!empty($lastUsers))
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Recent Users</span>
                    <a href="{!! adminUrl('applications' . '/tokens/' . ($appId)) !!}" class="btn btn-sm btn-outline-primary">All Tokens</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>User</th><th>Scope</th><th>IP</th><th>Last Used</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($lastUsers as $u)
                            <tr>
                                <td>
                                    <a href="{!! adminUrl('users/view/') !!}{!! (int) ($u['userid'] ?? 0) !!}" class="text-decoration-none">
                                        {{ $u['username'] ?? '—' }}
                                    </a>
                                </td>
                                <td class="small text-muted">{{ $u['scope'] ?? '—' }}</td>
                                <td class="small text-muted">{{ $u['ipaddress'] ?? '—' }}</td>
                                <td class="small">{!! ($u['lastused'] ?? 0) > 0 ? localDateTime((int) $u['lastused']) : '—' !!}</td>
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
            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-baseline mb-2">
                    <h3 class="font-semibold">Declared capabilities</h3>
                    @if (!empty($caps['synced_at']))
                        <span class="small text-muted">
                            last pushed {{ (string) $caps['synced_at'] }}
                        </span>
                    @endif
                </div>

                @if (empty($caps['resources']) && empty($caps['conditions']))
                    <p class="text-muted">
                        This client has not pushed a capabilities manifest. Until it does,
                        the server knows no resource or scope names for it.
                    </p>
                @else
                    @if (!empty($caps['resources']))
                        <table class="table table-sm mb-4">
                            <thead class="table-light small text-uppercase">
                                <tr><th >Resource</th><th >Scopes</th></tr>
                            </thead>
                            <tbody >
                            @foreach ($caps['resources'] as $resource)
                                <tr>
                                    <td class="align-top">
                                        <span class="font-mono{!! $resource['is_active'] ? '' : ' text-decoration-line-through text-muted' !!}">
                                            {{ $resource['name'] }}
                                        </span>
                                        @if (!empty($resource['description']))
                                            <div class="small text-muted">{{ (string) $resource['description'] }}</div>
                                        @endif
                                    </td>
                                    <td class="align-top">
                                        @if (empty($resource['scopes']))
                                            <span class="small text-muted">no scopes declared</span>
                                        @else
                                            @foreach ($resource['scopes'] as $scope)
                                                <span class="badge me-1 mb-1 text-dark{!! $scope['is_active'] ? ' bg-light' : ' bg-light text-decoration-line-through text-muted' !!}"
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
                        <h4 class="h6">Condition keys</h4>
                        <ul class="list-unstyled">
                        @foreach ($caps['conditions'] as $condition)
                            <li class="{!! $condition['is_active'] ? '' : ' text-decoration-line-through text-muted' !!}">
                                <span class="font-mono">{{ $condition['key'] }}</span>
                                <span class="small text-muted">
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
            <div class="mt-4" id="webhooks">
                <h3 class="font-semibold">Webhooks</h3>

                @if (empty($webhooks))
                    <p class="text-muted small">
                        No endpoints. The application can register its own through
                        <code>/Webhook/register</code>, or you can add one here.
                    </p>
                @else
                    <table class="table table-sm mb-3">
                        <thead class="table-light small text-uppercase">
                            <tr><th>Event</th><th>Endpoint</th><th>Set by</th><th>Deliveries</th><th></th></tr>
                        </thead>
                        <tbody>
                        @foreach ($webhooks as $hook)
                            @php $hookId = (int) $hook['webhook_id']; $ev = $hook['events'] ?? []; @endphp
                            <tr>
                                <td class="font-mono">{{ (string) $hook['webhook_type'] }}</td>
                                <td class="font-mono">{{ (string) $hook['endpoint_url'] }}</td>
                                <td>
                                    @if (($hook['registered_by'] ?? '') === 'admin')
                                        <span class="badge bg-primary">Administrator</span>
                                    @else
                                        <span class="badge bg-light text-dark">Application</span>
                                    @endif
                                </td>
                                <td>
                                    {!! (int) ($ev['sent'] ?? 0) !!} sent ·
                                    {!! (int) ($ev['pending'] ?? 0) !!} pending ·
                                    {!! (int) ($ev['failed'] ?? 0) !!} failed
                                </td>
                                <td class="text-end text-nowrap">
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

                <form method="post" action="{!! adminUrl('applications/webhook') !!}" class="border rounded p-3">
                    {!! $csrf !!}
                    <input type="hidden" name="appid" value="{!! $appId !!}">
                    <label class="form-label d-block mb-2">Event
                        <select name="webhook_type" class="form-select form-select-sm" required>
                            @foreach ($webhookTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="form-label d-block mb-2">Endpoint URL
                        <input type="url" name="endpoint_url" class="form-control form-control-sm font-mono"
                               placeholder="https://app.internal/hooks" pattern="{!! $httpsOnly ? 'https://.*' : 'https?://.*' !!}" required>
                    </label>
                    <label class="form-label d-block mb-2">Signing secret
                        <input type="text" name="secret" class="form-control form-control-sm font-mono" autocomplete="off" placeholder="Leave empty to generate one">
                    </label>
                    <p class="text-muted small">
                        An address entered here is delivered to as written, including one on a
                        private network.{!! $httpsOnly ? ' It must be https.' : '' !!} A type that already has an endpoint is replaced.
                    </p>
                    <button type="submit" class="btn btn-primary btn-sm">Save endpoint</button>
                </form>
            </div>

        </div>
    </div>
</div>
