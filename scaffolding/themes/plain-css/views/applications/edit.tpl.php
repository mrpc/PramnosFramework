@php /**
 * OAuth2 Application create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->application — app row array (null when creating)
 *   $this->message     — success flash (string)
 *   $this->error       — error flash (string)
 *   $this->policy      — what the application may do and how much: ApplicationService::policy()
 *   $this->grantTypes, $this->authMethods — the choices: OAuthPolicyHelper
 */
$app   = $this->application ?? [];
$isNew = empty($app['appid']);

$apptypes    = [0 => 'Web Application', 1 => 'Mobile App', 2 => 'Service / Daemon', 3 => 'Desktop App', 4 => 'IoT Device', 5 => 'Other'];
$accessTypes = [0 => 'REST (API Key)', 1 => 'OAuth2', 2 => 'Legacy API Only'];

$inp = 'width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:13px'; @endphp
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2>{!! $isNew ? 'New Application' : 'Edit Application' !!}</h2>
        <a href="{!! adminUrl('applications') !!}" class="btn btn-outline-secondary">Back to list</a>
    </div>

    @if (!empty($this->message))
        <div style="background:#d4edda;border:1px solid #c3e6cb;padding:10px 16px;border-radius:4px;margin-bottom:12px;color:#155724">
            {{ $this->message === 'secret_rotated' ? 'Client secret rotated.' : 'Application saved.' }}
        </div>
    @endif
    @if (!empty($this->error))
        <div style="background:#fde8e8;border:1px solid #f5c6cb;padding:10px 16px;border-radius:4px;margin-bottom:12px;color:#721c24">
            {{ $this->error === 'name_required' ? 'Application name is required.' : $this->error }}
        </div>
    @endif

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

    @if (!$isNew && !empty($app['apikey']))
        <div style="background:#d1ecf1;border:1px solid #bee5eb;padding:10px 16px;border-radius:4px;margin-bottom:12px;display:flex;align-items:center;gap:16px">
            <div style="font-size:13px"><strong>Client ID:</strong> <code style="background:#fff;padding:2px 4px;border-radius:3px">{{ $app['apikey'] ?? '' }}</code></div>
            <form method="post" action="{!! adminUrl('applications' . '/rotate/' . ((int)$app['appid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
               style="margin-left:auto" class="btn btn-sm btn-outline-warning"
               data-confirm="Rotate the client secret?">Rotate Secret</button></form>
        </div>
    @endif

    <form method="post" action="{!! adminUrl('applications/save') !!}">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        @if (!$isNew)
            <input type="hidden" name="appid" value="{!! (int)$app['appid'] !!}">
        @endif

        <!-- Tab nav -->
        <div style="display:flex;border-bottom:2px solid #e0e0e0;margin-bottom:20px;gap:4px">
            <button type="button" class="app-plain-tab active" data-tab="app-plain-basic" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;border-bottom:2px solid #0d6efd;margin-bottom:-2px;color:#0d6efd">Basic</button>
            <button type="button" class="app-plain-tab" data-tab="app-plain-org" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">Organisation</button>
            <button type="button" class="app-plain-tab" data-tab="app-plain-oauth" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">OAuth2 / API</button>
            @if (!$isNew)<button type="button" class="app-plain-tab" data-tab="app-plain-access" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">Access &amp; limits</button>@endif
            <button type="button" class="app-plain-tab" data-tab="app-plain-legal" style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666">Legal</button>
        </div>

        <!-- Basic -->
        <div id="app-plain-basic" class="app-plain-pane">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="grid-column:1/-1">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Application Name <span style="color:#dc3545">*</span></label>
                        <input type="text" name="name" required style="{!! $inp !!}" value="{{ $app['name'] ?? '' }}">
                    </div>
                    <div style="grid-column:1/-1">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Description</label>
                        <textarea name="description" style="{!! $inp !!}" rows="2">{{ $app['description'] ?? '' }}</textarea>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Application Type</label>
                        <select name="apptype" style="{!! $inp !!}">
                            @foreach ($apptypes as $v => $label)
                                <option value="{!! $v !!}"{!! ((int)($app['apptype'] ?? 0) === $v) ? ' selected' : '' !!}>{!! $label !!}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Access Type</label>
                        <select name="accesstype" style="{!! $inp !!}">
                            @foreach ($accessTypes as $v => $label)
                                <option value="{!! $v !!}"{!! ((int)($app['accesstype'] ?? 0) === $v) ? ' selected' : '' !!}>{!! $label !!}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">API Version</label>
                        <input type="text" name="apiversion" maxlength="20" style="{!! $inp !!}" value="{{ $app['apiversion'] ?? 'v1' }}" placeholder="v1">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">App Version</label>
                        <input type="text" name="appversion" maxlength="50" style="{!! $inp !!}" value="{{ $app['appversion'] ?? '' }}" placeholder="1.0.0">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Status</label>
                        <select name="status" style="{!! $inp !!}">
                            <option value="1"{!! ((int)($app['status'] ?? 1) === 1) ? ' selected' : '' !!}>Active</option>
                            <option value="0"{!! ((int)($app['status'] ?? 1) === 0) ? ' selected' : '' !!}>Disabled</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Public Directory</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px">
                            <input type="checkbox" name="public" value="1"
                                {!! ((int)($app['public'] ?? 0) === 1) ? 'checked' : '' !!}>
                            <span style="font-size:13px">Listed publicly</span>
                        </label>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" title="Confidential: runs on a server you control and keeps its client secret there. Public: a browser app, mobile app or desktop app &mdash; anything you ship to users, because every user then has its secret. A public client must have a registered redirect URI, or no one can sign in with it.">Client Type</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px">
                            <input type="checkbox" name="is_confidential" value="1"
                                {!! ((int)($app['is_confidential'] ?? 1) === 1) ? 'checked' : '' !!}>
                            <span style="font-size:13px">Confidential — can hold a client secret</span>
                        </label>
                        <p style="font-size:11px;color:#888;margin-top:4px">
                            Untick for a single-page app or a mobile binary: whatever secret
                            it ships with, every user of it has. A public client uses PKCE
                            and cannot use the client-credentials grant. A public client <strong>must</strong> have a registered redirect URI (OAuth2 tab), or no one can sign in with it. <a href="{!! \Pramnos\Auth\Application::CALLBACK_GUIDE_URL !!}" target="_blank" rel="noopener">What the difference means &rarr;</a>
                        </p>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Trusted</label>
                        <input type="hidden" name="trusted" value="0">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px">
                            <input type="checkbox" name="trusted" value="1"
                                {!! ((int)($app['trusted'] ?? 0) === 1) ? 'checked' : '' !!}>
                            <span style="font-size:13px">First-party — no consent screen</span>
                        </label>
                        <p style="font-size:11px;color:#888;margin-top:4px">Skips the consent screen: users signing in to it are not asked to approve its scopes. For your own first-party applications only — never for one a third party runs.</p>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Return to the app</label>
                        <input type="hidden" name="app_return_page" value="0">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px">
                            <input type="checkbox" name="app_return_page" value="1"
                                {!! ((int)($app['app_return_page'] ?? 0) === 1) ? 'checked' : '' !!}>
                            <span style="font-size:13px">A page instead of a redirect, for a custom-scheme callback</span>
                        </label>
                        <p style="font-size:11px;color:#888;margin-top:4px">For a desktop application that opens the system browser: the page opens the app and offers a button, where a redirect to a scheme nothing has registered leaves a blank tab. Leave off for a mobile app — its in-app browser expects the redirect.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Organisation -->
        <div id="app-plain-org" class="app-plain-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Organisation Name</label>
                        <input type="text" name="organization" style="{!! $inp !!}" value="{{ $app['organization'] ?? '' }}">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Organisation URL</label>
                        <input type="url" name="organizationurl" style="{!! $inp !!}" value="{{ $app['organizationurl'] ?? '' }}" placeholder="https://example.com">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Application URL</label>
                        <input type="url" name="url" style="{!! $inp !!}" value="{{ $app['url'] ?? '' }}" placeholder="https://app.example.com">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Support Email</label>
                        <input type="email" name="supportemail" style="{!! $inp !!}" value="{{ $app['supportemail'] ?? '' }}" placeholder="support@example.com">
                    </div>
                </div>
            </div>
        </div>

        <!-- OAuth2 / API -->
        <div id="app-plain-oauth" class="app-plain-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" title="The exact addresses this application may receive a sign-in code at. Optional for a confidential client that keeps its secret; required for a public one, or one with no secret, because for those it is the only thing tying the code to the application.">OAuth2 Redirect URI(s) / Callback</label>
                        <textarea name="callback" style="{!! $inp !!};font-family:monospace" rows="3" placeholder="https://app.example.com/callback&#10;One URI per line, or comma-separated">{{ $app['callback'] ?? '' }}</textarea>
                        <small style="color:#888;font-size:11px">Allowed redirect URIs for OAuth2 flows. Exact match. Required when the client is public or has no secret. <a href="{!! \Pramnos\Auth\Application::CALLBACK_GUIDE_URL !!}" target="_blank" rel="noopener">What the difference means &rarr;</a></small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Allowed Scopes</label>
                        <input type="text" name="scope" style="{!! $inp !!}" value="{{ $app['scope'] ?? '' }}" placeholder="openid profile email">
                        <small style="color:#888;font-size:11px">Space-separated. The client is refused any scope not listed, at sign-in and at the token endpoint, a refresh included. Empty: no restriction beyond the server's own scopes.</small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="access_token_ttl">Access token lifetime</label>
                        <input type="number" min="60" max="86400" name="access_token_ttl" id="access_token_ttl" style="{!! $inp !!}" value="{{ (string) ($app['access_token_ttl'] ?? '') }}" placeholder="server default">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="refresh_token_ttl">Refresh token lifetime</label>
                        <input type="number" min="60" max="31536000" name="refresh_token_ttl" id="refresh_token_ttl" style="{!! $inp !!}" value="{{ (string) ($app['refresh_token_ttl'] ?? '') }}" placeholder="server default">
                        <small style="color:#888;font-size:11px">Seconds. Empty: the server's default ({!! \Pramnos\Auth\OAuth2\TokenLifetimes::access() !!} for an access token, {!! \Pramnos\Auth\OAuth2\TokenLifetimes::refresh() !!} for a refresh token). Clamped to a minute – a day, and a minute – a year.</small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Public Key (PEM)</label>
                        <textarea name="public_key" style="{!! $inp !!};font-family:monospace" rows="4" placeholder="-----BEGIN PUBLIC KEY-----">{{ $app['public_key'] ?? '' }}</textarea>
                        <small style="color:#888;font-size:11px">For private_key_jwt client auth (RFC 7523).</small>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">JWKS URI</label>
                        <input type="url" name="jwks_uri" style="{!! $inp !!};font-family:monospace" value="{{ $app['jwks_uri'] ?? '' }}" placeholder="https://app.example.com/.well-known/jwks.json">
                        <small style="color:#888;font-size:11px">Dynamic key rotation endpoint.</small>
                    </div>
                </div>
            </div>
        </div>

            <!-- Access & limits: what the application may do, and how much -->
            @php if (!$isNew): $policy = $this->policy ?? ['grants' => [], 'grants_default' => true, 'methods' => [], 'methods_default' => true, 'settings' => \Pramnos\Auth\ApplicationSettings::DEFAULTS]; $limits = $policy['settings']; @endphp
        <div id="app-plain-access" class="app-plain-pane" style="display:none">
                <input type="hidden" name="policy_submitted" value="1">
                <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                    <h4 style="margin:0 0 4px;font-size:14px">Grant types</h4>
                    <p style="color:#888;font-size:11px;margin:0 0 8px">How this application may obtain tokens. Refused at the token endpoint with <code>unauthorized_client</code> otherwise.@if ($policy['grants_default']) This application follows the server's defaults; saving a different selection gives it its own.@endif</p>
                    @php foreach (($this->grantTypes ?? []) as $choice): $choiceId = 'grants-' . $choice['method']; @endphp
                    <div style="margin-bottom:4px"><input type="checkbox" name="grants[]" id="{{ $choiceId }}" value="{{ $choice['method'] }}"  {!! in_array($choice['method'], $policy['grants'], true) ? 'checked' : '' !!}> <label for="{{ $choiceId }}"><strong>{{ $choice['name'] }}</strong> — {{ $choice['description'] }}</label></div>
                    @endforeach
                </div>
                <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                    <h4 style="margin:0 0 4px;font-size:14px">Client authentication</h4>
                    <p style="color:#888;font-size:11px;margin:0 0 8px">How this application may prove itself at the token, revocation and introspection endpoints.@if ($policy['methods_default']) This application follows the server's defaults; saving a different selection gives it its own.@endif</p>
                    @php foreach (($this->authMethods ?? []) as $choice): $choiceId = 'auth_methods-' . $choice['method']; @endphp
                    <div style="margin-bottom:4px"><input type="checkbox" name="auth_methods[]" id="{{ $choiceId }}" value="{{ $choice['method'] }}"  {!! in_array($choice['method'], $policy['methods'], true) ? 'checked' : '' !!}> <label for="{{ $choiceId }}"><strong>{{ $choice['name'] }}</strong> — {{ $choice['description'] }}</label></div>
                    @endforeach
                </div>
                <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                    <h4 style="margin:0 0 4px;font-size:14px">Rate limit</h4>
                    <p style="color:#888;font-size:11px;margin:0 0 8px">A burst of requests at once, then a steady rate. A request over the limit is answered <code>429</code> with <code>Retry-After</code>.</p>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="rate_limit_requests">Requests per window</label>
                        <input type="number" min="1" name="rate_limit_requests" id="rate_limit_requests" style="{!! $inp !!}" value="{!! (int) $limits['rate_limit_requests'] !!}">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="rate_limit_window_seconds">Window (seconds)</label>
                        <input type="number" min="1" name="rate_limit_window_seconds" id="rate_limit_window_seconds" style="{!! $inp !!}" value="{!! (int) $limits['rate_limit_window_seconds'] !!}">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="rate_limit_burst">Burst</label>
                        <input type="number" min="0" name="rate_limit_burst" id="rate_limit_burst" style="{!! $inp !!}" value="{!! (int) $limits['rate_limit_burst'] !!}">
                    </div>
                    </div>
                </div>
                <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                    <h4 style="margin:0 0 4px;font-size:14px">Pagination</h4>
                    <p style="color:#888;font-size:11px;margin:0 0 8px">For the API's list endpoints. Enforced, a request for every row gets the first page at the default size; no page is ever larger than the maximum.</p>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
                    <div style="grid-column:span 3"><div style="margin-bottom:4px"><input type="checkbox" name="enforce_pagination" id="enforce_pagination" value="1"  {!! $limits['enforce_pagination'] ? 'checked' : '' !!}> <label for="enforce_pagination">Enforce pagination</label></div></div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="default_page_size">Default page size</label>
                        <input type="number" min="1" name="default_page_size" id="default_page_size" style="{!! $inp !!}" value="{!! (int) $limits['default_page_size'] !!}">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="max_page_size">Maximum page size</label>
                        <input type="number" min="1" name="max_page_size" id="max_page_size" style="{!! $inp !!}" value="{!! (int) $limits['max_page_size'] !!}">
                    </div>
                    </div>
                </div>
                <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                    <h4 style="margin:0 0 4px;font-size:14px">Network</h4>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
                    <div style="grid-column:span 3"><div style="margin-bottom:4px"><input type="checkbox" name="require_https" id="require_https" value="1"  {!! $limits['require_https'] ? 'checked' : '' !!}> <label for="require_https">Require HTTPS (requests from this machine are exempt)</label></div></div>
                    <div style="grid-column:span 3"><div style="margin-bottom:4px"><input type="checkbox" name="ip_lock_enabled" id="ip_lock_enabled" value="1"  {!! $limits['ip_lock_enabled'] ? 'checked' : '' !!}> <label for="ip_lock_enabled">Accept requests only from the allowed addresses</label></div></div>
                    <div style="grid-column:span 3">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="allowed_ips">Allowed addresses</label>
                        <textarea name="allowed_ips" id="allowed_ips" rows="3" style="{!! $inp !!};font-family:monospace" placeholder="203.0.113.10&#10;10.0.0.0/8">{{ implode("\n", $limits['allowed_ips']) }}</textarea>
                        <p style="color:#888;font-size:11px;margin:0 0 8px">One address or range per line. Used when the lock above is on.</p>
                    </div>
                    <div style="grid-column:span 3">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="blocked_ips">Blocked addresses</label>
                        <textarea name="blocked_ips" id="blocked_ips" rows="3" style="{!! $inp !!};font-family:monospace" placeholder="198.51.100.0/24">{{ implode("\n", $limits['blocked_ips']) }}</textarea>
                        <p style="color:#888;font-size:11px;margin:0 0 8px">Always refused, lock or not.</p>
                    </div>
                    </div>
                </div>
                <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                    <h4 style="margin:0 0 4px;font-size:14px">Browser origins (CORS)</h4>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
                    <div style="grid-column:span 3"><div style="margin-bottom:4px"><input type="checkbox" name="cors_enabled" id="cors_enabled" value="1"  {!! $limits['cors_enabled'] ? 'checked' : '' !!}> <label for="cors_enabled">Accept browser requests only from these origins</label></div></div>
                    <div style="grid-column:span 3">
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="cors_origins">Allowed origins</label>
                        <textarea name="cors_origins" id="cors_origins" rows="3" style="{!! $inp !!};font-family:monospace" placeholder="https://app.example">{{ implode("\n", $limits['cors_origins']) }}</textarea>
                        <p style="color:#888;font-size:11px;margin:0 0 8px">One per line, such as https://app.example. A request with no Origin — a server — is not affected.</p>
                    </div>
                    </div>
                </div>
            </div>
            @endif

        <!-- Legal -->
        <div id="app-plain-legal" class="app-plain-pane" style="display:none">
            <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Terms of Service URL</label>
                        <input type="url" name="termsurl" style="{!! $inp !!}" value="{{ $app['termsurl'] ?? '' }}" placeholder="https://example.com/terms">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Privacy Policy URL</label>
                        <input type="url" name="privacyurl" style="{!! $inp !!}" value="{{ $app['privacyurl'] ?? '' }}" placeholder="https://example.com/privacy">
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center">
            <button type="submit" class="btn btn-primary">Save Application</button>
            <a href="{!! adminUrl('applications') !!}" class="btn btn-outline-secondary">Cancel</a>
            @if (!$isNew)
                <a href="{!! adminUrl('applications' . '/tokens/' . ((int)$app['appid'])) !!}" style="margin-left:auto" class="btn btn-outline-info">View Tokens</a>
            @endif
        </div>
    </form>
</div>

<script>
(function () {
    var btns  = document.querySelectorAll('.app-plain-tab');
    var panes = document.querySelectorAll('.app-plain-pane');
    function activate(id) {
        btns.forEach(function (b) {
            var a = b.getAttribute('data-tab') === id;
            b.style.borderBottomColor = a ? '#0d6efd' : 'transparent';
            b.style.color = a ? '#0d6efd' : '#666';
            b.style.fontWeight = a ? '600' : 'normal';
        });
        panes.forEach(function (p) { p.style.display = p.id === id ? '' : 'none'; });
    }
    btns.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); }); });
    activate('app-plain-basic');
})();
</script>
