@php /**
 * OAuth2 Application create/edit form (Tailwind theme).
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

$inp  = 'w-full border border-base-300 rounded-sm px-3 py-2 text-sm focus:outline-hidden focus:ring-2 focus:ring-primary';
$lbl  = 'block text-sm font-semibold text-base-content mb-1';
$card = 'bg-base-100 rounded-xl shadow-xs border border-base-300 p-5 mb-4'; @endphp
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">{!! $isNew ? 'New Application' : 'Edit Application' !!}</h2>
        <a href="{!! adminUrl('applications') !!}" class="btn btn-outline btn-sm">Back to list</a>
    </div>

    {{-- The message itself, not a lookup on a code. This used to map
             'secret_rotated' to a sentence and everything else to
             "Application saved." — but the controller flashes a sentence, so no
             code ever matched: rotating a client secret confirmed a save that
             had not happened, on the page an operator checks precisely because
             the rotation is invisible otherwise. --}}
    @if (!empty($this->message))
        <div role="status" class="alert alert-success mb-4">
            {{ (string) $this->message }}
        </div>
    @endif
    @if (!empty($this->error))
        <div role="alert" class="alert alert-error mb-4">
            {{ (string) $this->error }}
        </div>
    @endif

    @if (!empty($app['appid']) && \Pramnos\Auth\Application::needsARegisteredCallback($app))
        <div role="status" class="alert alert-warning mb-4 block">
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
        <div class="bg-primary/10 border border-primary rounded-sm px-4 py-3 mb-4 flex items-center gap-4 text-sm">
            <div><strong>Client ID:</strong> <code class="card bg-base-100 border border-base-300 px-1 py-0.5 font-mono">{{ $app['apikey'] ?? '' }}</code></div>
            <form method="post" action="{!! adminUrl('applications' . '/rotate/' . ((int)$app['appid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
               class="btn btn-outline btn-warning btn-xs ml-auto"
               data-confirm="Rotate the client secret?">Rotate Secret</button></form>
        </div>
    @endif

    <form method="post" action="{!! adminUrl('applications/save') !!}" id="appEditForm">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        @if (!$isNew)
            <input type="hidden" name="appid" value="{!! (int)$app['appid'] !!}">
        @endif

        <div class="flex border-b border-base-300 mb-4 gap-1">
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-base-300 bg-base-100 text-primary" data-tab="app-tab-basic">Basic</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="app-tab-org">Organisation</button>
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="app-tab-oauth">OAuth2 / API</button>
            @if (!$isNew)<button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="app-tab-access">Access &amp; limits</button>@endif
            <button type="button" class="app-tab-btn px-4 py-2 text-sm font-medium rounded-t border border-b-0 border-transparent text-base-content/80 hover:text-primary" data-tab="app-tab-legal">Legal</button>
        </div>

        <div id="app-tab-basic" class="app-tab-pane">
            <div class="{!! $card !!}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2"><label class="{!! $lbl !!}">Application Name <span class="text-error">*</span></label>
                        <input type="text" name="name" class="{!! $inp !!}" required value="{{ $app['name'] ?? '' }}"></div>
                    <div class="md:col-span-2"><label class="{!! $lbl !!}">Description</label>
                        <textarea name="description" class="{!! $inp !!}" rows="2">{{ $app['description'] ?? '' }}</textarea></div>
                    <div><label class="{!! $lbl !!}">Application Type</label>
                        <select name="apptype" class="{!! $inp !!}">
                            @foreach ($apptypes as $v => $label)
                                <option value="{!! $v !!}"{!! ((int)($app['apptype'] ?? 0) === $v) ? ' selected' : '' !!}>{!! $label !!}</option>
                            @endforeach
                        </select></div>
                    <div><label class="{!! $lbl !!}">Access Type</label>
                        <select name="accesstype" class="{!! $inp !!}">
                            @foreach ($accessTypes as $v => $label)
                                <option value="{!! $v !!}"{!! ((int)($app['accesstype'] ?? 0) === $v) ? ' selected' : '' !!}>{!! $label !!}</option>
                            @endforeach
                        </select></div>
                    <div><label class="{!! $lbl !!}">API Version</label>
                        <input type="text" name="apiversion" class="{!! $inp !!}" maxlength="20" value="{{ $app['apiversion'] ?? 'v1' }}" placeholder="v1"></div>
                    <div><label class="{!! $lbl !!}">App Version</label>
                        <input type="text" name="appversion" class="{!! $inp !!}" maxlength="50" value="{{ $app['appversion'] ?? '' }}" placeholder="1.0.0"></div>
                    <div><label class="{!! $lbl !!}">Status</label>
                        <select name="status" class="{!! $inp !!}">
                            <option value="1"{!! ((int)($app['status'] ?? 1) === 1) ? ' selected' : '' !!}>Active</option>
                            <option value="0"{!! ((int)($app['status'] ?? 1) === 0) ? ' selected' : '' !!}>Disabled</option>
                        </select></div>
                    <div><label class="{!! $lbl !!}">Public Directory</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="public" value="1" class="w-4 h-4"
                                {!! ((int)($app['public'] ?? 0) === 1) ? 'checked' : '' !!}>
                            <span class="text-sm text-base-content/80">Listed publicly</span>
                        </label></div>
                    <div><label class="{!! $lbl !!}" title="Confidential: runs on a server you control and keeps its client secret there. Public: a browser app, mobile app or desktop app &mdash; anything you ship to users, because every user then has its secret. A public client must have a registered redirect URI, or no one can sign in with it.">Client Type</label>
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="is_confidential" value="1" class="w-4 h-4"
                                {!! ((int)($app['is_confidential'] ?? 1) === 1) ? 'checked' : '' !!}>
                            <span class="text-sm text-base-content/80">Confidential — can hold a client secret</span>
                        </label>
                        <p class="text-xs text-base-content/60 mt-1">
                            Untick for a single-page app or a mobile binary: whatever secret
                            it ships with, every user of it has. A public client uses PKCE
                            and cannot use the client-credentials grant. A public client <strong>must</strong> have a registered redirect URI (OAuth2 tab), or no one can sign in with it. <a href="{!! \Pramnos\Auth\Application::CALLBACK_GUIDE_URL !!}" target="_blank" rel="noopener">What the difference means &rarr;</a>
                        </p></div>
                    <div><label class="{!! $lbl !!}">Trusted</label>
                        <input type="hidden" name="trusted" value="0">
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="trusted" value="1" class="w-4 h-4"
                                {!! ((int)($app['trusted'] ?? 0) === 1) ? 'checked' : '' !!}>
                            <span class="text-sm text-base-content/80">First-party — no consent screen</span>
                        </label>
                        <p class="text-xs text-base-content/60 mt-1">Skips the consent screen: users signing in to it are not asked to approve its scopes. For your own first-party applications only — never for one a third party runs.</p></div>
                    <div><label class="{!! $lbl !!}">Return to the app</label>
                        <input type="hidden" name="app_return_page" value="0">
                        <label class="flex items-center gap-2 mt-1 cursor-pointer">
                            <input type="checkbox" name="app_return_page" value="1" class="w-4 h-4"
                                {!! ((int)($app['app_return_page'] ?? 0) === 1) ? 'checked' : '' !!}>
                            <span class="text-sm text-base-content/80">A page instead of a redirect, for a custom-scheme callback</span>
                        </label>
                        <p class="text-xs text-base-content/60 mt-1">For a desktop application that opens the system browser: the page opens the app and offers a button, where a redirect to a scheme nothing has registered leaves a blank tab. Leave off for a mobile app — its in-app browser expects the redirect.</p></div>
                </div>
            </div>
        </div>

        <div id="app-tab-org" class="app-tab-pane hidden">
            <div class="{!! $card !!}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="{!! $lbl !!}">Organisation Name</label>
                        <input type="text" name="organization" class="{!! $inp !!}" value="{{ $app['organization'] ?? '' }}"></div>
                    <div><label class="{!! $lbl !!}">Organisation URL</label>
                        <input type="url" name="organizationurl" class="{!! $inp !!}" value="{{ $app['organizationurl'] ?? '' }}" placeholder="https://example.com"></div>
                    <div><label class="{!! $lbl !!}">Application URL</label>
                        <input type="url" name="url" class="{!! $inp !!}" value="{{ $app['url'] ?? '' }}" placeholder="https://app.example.com"></div>
                    <div><label class="{!! $lbl !!}">Support Email</label>
                        <input type="email" name="supportemail" class="{!! $inp !!}" value="{{ $app['supportemail'] ?? '' }}" placeholder="support@example.com"></div>
                </div>
            </div>
        </div>

        <div id="app-tab-oauth" class="app-tab-pane hidden">
            <div class="{!! $card !!}">
                <div class="grid grid-cols-1 gap-4">
                    <div><label class="{!! $lbl !!}" title="The exact addresses this application may receive a sign-in code at. Optional for a confidential client that keeps its secret; required for a public one, or one with no secret, because for those it is the only thing tying the code to the application.">OAuth2 Redirect URI(s) / Callback</label>
                        <textarea name="callback" class="{!! $inp !!} font-mono" rows="3" placeholder="https://app.example.com/callback&#10;One URI per line, or comma-separated">{{ $app['callback'] ?? '' }}</textarea>
                        <p class="text-xs text-base-content/60 mt-1">Allowed redirect URIs. Exact match. Required when the client is public or has no secret. <a href="{!! \Pramnos\Auth\Application::CALLBACK_GUIDE_URL !!}" target="_blank" rel="noopener">What the difference means &rarr;</a></p></div>
                    <div><label class="{!! $lbl !!}">Allowed Scopes</label>
                        <input type="text" name="scope" class="{!! $inp !!}" value="{{ $app['scope'] ?? '' }}" placeholder="openid profile email">
                        <p class="text-xs text-base-content/60 mt-1">Space-separated. The client is refused any scope not listed, at sign-in and at the token endpoint, a refresh included. Empty: no restriction beyond the server's own scopes.</p></div>
                    <div><label class="{!! $lbl !!}" for="access_token_ttl">Access token lifetime</label>
                        <input type="number" min="60" max="86400" name="access_token_ttl" id="access_token_ttl" class="{!! $inp !!}" value="{{ (string) ($app['access_token_ttl'] ?? '') }}" placeholder="server default"></div>
                    <div><label class="{!! $lbl !!}" for="refresh_token_ttl">Refresh token lifetime</label>
                        <input type="number" min="60" max="31536000" name="refresh_token_ttl" id="refresh_token_ttl" class="{!! $inp !!}" value="{{ (string) ($app['refresh_token_ttl'] ?? '') }}" placeholder="server default">
                        <p class="text-xs text-base-content/60 mt-1">Seconds. Empty: the server's default ({!! \Pramnos\Auth\OAuth2\TokenLifetimes::access() !!} for an access token, {!! \Pramnos\Auth\OAuth2\TokenLifetimes::refresh() !!} for a refresh token). Clamped to a minute – a day, and a minute – a year.</p></div>
                    <div><label class="{!! $lbl !!}">Public Key (PEM)</label>
                        <textarea name="public_key" class="{!! $inp !!} font-mono" rows="4" placeholder="-----BEGIN PUBLIC KEY-----">{{ $app['public_key'] ?? '' }}</textarea>
                        <p class="text-xs text-base-content/60 mt-1">For <code>private_key_jwt</code> client auth (RFC 7523).</p></div>
                    <div><label class="{!! $lbl !!}">JWKS URI</label>
                        <input type="url" name="jwks_uri" class="{!! $inp !!} font-mono" value="{{ $app['jwks_uri'] ?? '' }}" placeholder="https://app.example.com/.well-known/jwks.json">
                        <p class="text-xs text-base-content/60 mt-1">Dynamic key rotation endpoint.</p></div>
                </div>
            </div>
        </div>

            <!-- Access & limits: what the application may do, and how much -->
            @php if (!$isNew): $policy = $this->policy ?? ['grants' => [], 'grants_default' => true, 'methods' => [], 'methods_default' => true, 'settings' => \Pramnos\Auth\ApplicationSettings::DEFAULTS]; $limits = $policy['settings']; @endphp
        <div id="app-tab-access" class="app-tab-pane hidden">
                <input type="hidden" name="policy_submitted" value="1">
                <div class="{!! $card !!}">
                    <h3 class="font-semibold mb-1">Grant types</h3>
                    <p class="text-xs text-base-content/60 mb-2">How this application may obtain tokens. Refused at the token endpoint with <code>unauthorized_client</code> otherwise.@if ($policy['grants_default']) This application follows the server's defaults; saving a different selection gives it its own.@endif</p>
                    @php foreach (($this->grantTypes ?? []) as $choice): $choiceId = 'grants-' . $choice['method']; @endphp
                    <div class="flex items-start gap-2 mb-1"><input type="checkbox" name="grants[]" id="{{ $choiceId }}" value="{{ $choice['method'] }}" class="w-4 h-4 mt-1" {!! in_array($choice['method'], $policy['grants'], true) ? 'checked' : '' !!}> <label for="{{ $choiceId }}"><strong>{{ $choice['name'] }}</strong> — {{ $choice['description'] }}</label></div>
                    @endforeach
                </div>
                <div class="{!! $card !!}">
                    <h3 class="font-semibold mb-1">Client authentication</h3>
                    <p class="text-xs text-base-content/60 mb-2">How this application may prove itself at the token, revocation and introspection endpoints.@if ($policy['methods_default']) This application follows the server's defaults; saving a different selection gives it its own.@endif</p>
                    @php foreach (($this->authMethods ?? []) as $choice): $choiceId = 'auth_methods-' . $choice['method']; @endphp
                    <div class="flex items-start gap-2 mb-1"><input type="checkbox" name="auth_methods[]" id="{{ $choiceId }}" value="{{ $choice['method'] }}" class="w-4 h-4 mt-1" {!! in_array($choice['method'], $policy['methods'], true) ? 'checked' : '' !!}> <label for="{{ $choiceId }}"><strong>{{ $choice['name'] }}</strong> — {{ $choice['description'] }}</label></div>
                    @endforeach
                </div>
                <div class="{!! $card !!}">
                    <h3 class="font-semibold mb-1">Rate limit</h3>
                    <p class="text-xs text-base-content/60 mb-2">A burst of requests at once, then a steady rate. A request over the limit is answered <code>429</code> with <code>Retry-After</code>.</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="{!! $lbl !!}" for="rate_limit_requests">Requests per window</label>
                        <input type="number" min="1" name="rate_limit_requests" id="rate_limit_requests" class="{!! $inp !!}" value="{!! (int) $limits['rate_limit_requests'] !!}">
                    </div>
                    <div>
                        <label class="{!! $lbl !!}" for="rate_limit_window_seconds">Window (seconds)</label>
                        <input type="number" min="1" name="rate_limit_window_seconds" id="rate_limit_window_seconds" class="{!! $inp !!}" value="{!! (int) $limits['rate_limit_window_seconds'] !!}">
                    </div>
                    <div>
                        <label class="{!! $lbl !!}" for="rate_limit_burst">Burst</label>
                        <input type="number" min="0" name="rate_limit_burst" id="rate_limit_burst" class="{!! $inp !!}" value="{!! (int) $limits['rate_limit_burst'] !!}">
                    </div>
                    </div>
                </div>
                <div class="{!! $card !!}">
                    <h3 class="font-semibold mb-1">Pagination</h3>
                    <p class="text-xs text-base-content/60 mb-2">For the API's list endpoints. Enforced, a request for every row gets the first page at the default size; no page is ever larger than the maximum.</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-3"><div class="flex items-start gap-2 mb-1"><input type="checkbox" name="enforce_pagination" id="enforce_pagination" value="1" class="w-4 h-4 mt-1" {!! $limits['enforce_pagination'] ? 'checked' : '' !!}> <label for="enforce_pagination">Enforce pagination</label></div></div>
                    <div>
                        <label class="{!! $lbl !!}" for="default_page_size">Default page size</label>
                        <input type="number" min="1" name="default_page_size" id="default_page_size" class="{!! $inp !!}" value="{!! (int) $limits['default_page_size'] !!}">
                    </div>
                    <div>
                        <label class="{!! $lbl !!}" for="max_page_size">Maximum page size</label>
                        <input type="number" min="1" name="max_page_size" id="max_page_size" class="{!! $inp !!}" value="{!! (int) $limits['max_page_size'] !!}">
                    </div>
                    </div>
                </div>
                <div class="{!! $card !!}">
                    <h3 class="font-semibold mb-1">Network</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-3"><div class="flex items-start gap-2 mb-1"><input type="checkbox" name="require_https" id="require_https" value="1" class="w-4 h-4 mt-1" {!! $limits['require_https'] ? 'checked' : '' !!}> <label for="require_https">Require HTTPS (requests from this machine are exempt)</label></div></div>
                    <div class="md:col-span-3"><div class="flex items-start gap-2 mb-1"><input type="checkbox" name="ip_lock_enabled" id="ip_lock_enabled" value="1" class="w-4 h-4 mt-1" {!! $limits['ip_lock_enabled'] ? 'checked' : '' !!}> <label for="ip_lock_enabled">Accept requests only from the allowed addresses</label></div></div>
                    <div class="md:col-span-3">
                        <label class="{!! $lbl !!}" for="allowed_ips">Allowed addresses</label>
                        <textarea name="allowed_ips" id="allowed_ips" rows="3" class="{!! $inp !!} font-mono" placeholder="203.0.113.10&#10;10.0.0.0/8">{{ implode("\n", $limits['allowed_ips']) }}</textarea>
                        <p class="text-xs text-base-content/60 mb-2">One address or range per line. Used when the lock above is on.</p>
                    </div>
                    <div class="md:col-span-3">
                        <label class="{!! $lbl !!}" for="blocked_ips">Blocked addresses</label>
                        <textarea name="blocked_ips" id="blocked_ips" rows="3" class="{!! $inp !!} font-mono" placeholder="198.51.100.0/24">{{ implode("\n", $limits['blocked_ips']) }}</textarea>
                        <p class="text-xs text-base-content/60 mb-2">Always refused, lock or not.</p>
                    </div>
                    </div>
                </div>
                <div class="{!! $card !!}">
                    <h3 class="font-semibold mb-1">Browser origins (CORS)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-3"><div class="flex items-start gap-2 mb-1"><input type="checkbox" name="cors_enabled" id="cors_enabled" value="1" class="w-4 h-4 mt-1" {!! $limits['cors_enabled'] ? 'checked' : '' !!}> <label for="cors_enabled">Accept browser requests only from these origins</label></div></div>
                    <div class="md:col-span-3">
                        <label class="{!! $lbl !!}" for="cors_origins">Allowed origins</label>
                        <textarea name="cors_origins" id="cors_origins" rows="3" class="{!! $inp !!} font-mono" placeholder="https://app.example">{{ implode("\n", $limits['cors_origins']) }}</textarea>
                        <p class="text-xs text-base-content/60 mb-2">One per line, such as https://app.example. A request with no Origin — a server — is not affected.</p>
                    </div>
                    </div>
                </div>
            </div>
            @endif

        <div id="app-tab-legal" class="app-tab-pane hidden">
            <div class="{!! $card !!}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="{!! $lbl !!}">Terms of Service URL</label>
                        <input type="url" name="termsurl" class="{!! $inp !!}" value="{{ $app['termsurl'] ?? '' }}" placeholder="https://example.com/terms"></div>
                    <div><label class="{!! $lbl !!}">Privacy Policy URL</label>
                        <input type="url" name="privacyurl" class="{!! $inp !!}" value="{{ $app['privacyurl'] ?? '' }}" placeholder="https://example.com/privacy"></div>
                </div>
            </div>
        </div>

        <div class="flex gap-3 mt-2">
            <button type="submit" class="btn btn-primary btn-sm">Save Application</button>
            <a href="{!! adminUrl('applications') !!}" class="btn btn-outline btn-sm">Cancel</a>
            @if (!$isNew)
                <a href="{!! adminUrl('applications' . '/tokens/' . ((int)$app['appid'])) !!}" class="btn btn-outline btn-primary btn-sm ml-auto">View Tokens</a>
            @endif
        </div>
    </form>
</div>

<script>
(function () {
    function initTabs() {
        var btns  = document.querySelectorAll('.app-tab-btn');
        var panes = document.querySelectorAll('.app-tab-pane');
        function activate(id) {
            btns.forEach(function (b) {
                var a = b.getAttribute('data-tab') === id;
                b.classList.toggle('text-primary', a); b.classList.toggle('bg-base-100', a); b.classList.toggle('border-base-300', a);
                b.classList.toggle('text-base-content/80', !a); b.classList.toggle('border-transparent', !a);
            });
            panes.forEach(function (p) { p.classList.toggle('hidden', p.id !== id); });
        }
        btns.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); }); });
        activate('app-tab-basic');
    }
    document.addEventListener('DOMContentLoaded', initTabs);
})();
</script>
