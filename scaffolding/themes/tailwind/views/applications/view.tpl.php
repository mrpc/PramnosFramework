@php /**
 * Application detail (read-only) view (Tailwind theme).
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

$isActive = (bool) ($app['status'] ?? 1);

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
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{!! adminUrl('applications') !!}" class="btn btn-outline btn-sm">&larr; Applications</a>
        <h2 class="text-2xl font-semibold">{{ $app['name'] ?? '' }}</h2>
        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! $isActive ? 'bg-success/10 text-success' : 'bg-error/10 text-error' !!}">
            {!! $isActive ? 'Active' : 'Disabled' !!}
        </span>
    </div>

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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: credentials + stats + actions -->
        <div class="space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide rounded-t-xl">Credentials</div>
                <div class="p-4 space-y-3">
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Client ID (API Key)</div>
                        <div class="flex gap-1">
                            <input type="text" readonly
                                   class="input input-xs flex-1 font-mono"
                                   value="{{ $app['apikey'] ?? '' }}">
                            <button class="btn btn-outline btn-xs"
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
                        <div class="text-xs text-base-content/60 mb-1">Client Secret</div>
                        <div class="text-xs text-base-content/60 italic mb-1">
                            Stored hashed and not recoverable.
                        </div>
                        <form method="post" action="{!! adminUrl('applications/rotate/') . $appId !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                           class="btn btn-outline btn-xs"
                           data-confirm="Generate a new client secret? The current one stops working immediately.">Rotate</button></form>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">Token Statistics</div>
                <div class="divide-y divide-base-300 text-sm">
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Total</span>
                        <a href="{!! adminUrl('applications' . '/tokens/' . ($appId)) !!}" class="font-semibold text-primary hover:underline">
                            {!! (int) ($tokenStats['total'] ?? 0) !!}
                        </a>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Active</span>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-success/10 text-success">{!! (int) ($tokenStats['active'] ?? 0) !!}</span>
                    </div>
                    <div class="px-4 py-2.5 flex justify-between">
                        <span class="text-base-content/70">Revoked</span>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium bg-error/10 text-error">{!! (int) ($tokenStats['revoked'] ?? 0) !!}</span>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-4 py-2 bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide rounded-t-xl">Actions</div>
                @php /**
                 * Left-aligned, icon-first, `btn-block` — the same shape the user record
                 * uses, from the same `Html\Icon` set. Centred label-only buttons in a
                 * narrow column read as four identical pills: the icon is what makes the
                 * list scannable, and a daisyUI `btn` centres its content unless the
                 * content is told where to go.
                 */
                // `$post` for an action that changes something: a one-button POST form with the
                // session's token, because the controller refuses it any other way.
                $action = static function (
                    string $url,
                    string $icon,
                    string $label,
                    string $classes = 'btn-outline',
                    string $confirm = '',
                    bool $post = false
                ): void {
                    $content = \Pramnos\Html\Icon::svg($icon)
                        . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    $class   = 'btn btn-sm btn-block justify-start gap-2 ' . $classes;

                    if ($post) {
                        echo \Pramnos\Html\Icon::postControl($url, $content, ['class' => $class]
                            + ($confirm !== '' ? ['data-confirm' => $confirm] : []));

                        return;
                    }

                    echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
                        . ' class="' . $class . '"'
                        . ($confirm !== ''
                            ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '"'
                            : '')
                        . '>' . $content . '</a>';
                }; @endphp
                <div class="p-4 grid gap-2">
                    @php $action(adminUrl('applications/edit/' . $appId), 'edit', 'Edit application', 'btn-primary');
                    $action(adminUrl('applications/tokens/' . $appId), 'tokens', 'View tokens');
                    $action(adminUrl('applications/rotate/' . $appId), 'retry', 'Rotate secret', 'btn-outline btn-warning',
                        'Rotate the client secret? Existing tokens remain valid.', true);
                    $action(adminUrl('applications/delete/' . $appId), 'deactivate', 'Disable application', 'btn-outline btn-error',
                        'Disable this application and revoke all active tokens?', true); @endphp
                </div>
            </div>

        </div>

        <!-- Right: details + last users -->
        <div class="lg:col-span-2 space-y-4">

            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-6 py-3 border-b border-base-300 font-semibold text-base-content">Application Details</div>
                <div class="p-6 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                    @php $field = function(string $label, string $value) {; @endphp
                        <div>
                            <div class="text-xs text-base-content/60 mb-0.5">{!! $label !!}</div>
                            <div class="text-base-content">{!! $value !!}</div>
                        </div>
                    @php }; @endphp
                    @php $field('App ID', '<code class="text-xs">' . $appId . '</code>'); @endphp
                    @php $field('Type', $appTypeLabel((int) ($app['apptype'] ?? 0))); @endphp
                    @php $field('Access Type', $accessTypeLabel((int) ($app['accesstype'] ?? 0))); @endphp
                    @php $field('API Version', htmlspecialchars($app['apiversion'] ?? 'v1', ENT_QUOTES, 'UTF-8')); @endphp
                    @php $field('App Version', ($app['appversion'] ?? '') !== '' ? htmlspecialchars($app['appversion'], ENT_QUOTES, 'UTF-8') : '<span class="text-base-content/50">—</span>'); @endphp
                    <div>
                        <div class="text-xs text-base-content/60 mb-0.5">Public</div>
                        <span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium {!! (int) ($app['public'] ?? 0) ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/80' !!}">
                            {!! (int) ($app['public'] ?? 0) ? 'Yes' : 'No' !!}
                        </span>
                    </div>
                    @php $field('Added', ($app['added'] ?? 0) > 0 ? localDateTime( (int) $app['added']) : '—'); @endphp
                    @if (!empty($app['description']))
                    <div class="col-span-full">
                        <div class="text-xs text-base-content/60 mb-0.5">Description</div>
                        <div class="text-base-content">{{ $app['description'] }}</div>
                    </div>
                    @endif
                    @if (!empty($app['callback']))
                    <div class="col-span-full">
                        <div class="text-xs text-base-content/60 mb-0.5">Callback URL</div>
                        <div class="font-mono text-xs text-base-content/80 break-all">{{ $app['callback'] }}</div>
                    </div>
                    @endif
                    @if (!empty($app['scope']))
                    <div class="col-span-full">
                        <div class="text-xs text-base-content/60 mb-0.5">Scope</div>
                        <div class="font-mono text-xs text-base-content/80">{{ $app['scope'] }}</div>
                    </div>
                    @endif
                    @if (!empty($app['organization']))
                    @php $field('Organization', htmlspecialchars($app['organization'], ENT_QUOTES, 'UTF-8')); @endphp
                    @endif
                    @if (!empty($app['url']))
                    @php $field('URL', '<span class="text-xs break-all">' . htmlspecialchars($app['url'], ENT_QUOTES, 'UTF-8') . '</span>'); @endphp
                    @endif
                    @if (!empty($app['public_key']))
                    <div class="col-span-full">
                        <div class="text-xs text-base-content/60 mb-0.5">Public Key</div>
                        <pre class="bg-base-200 rounded-sm p-2 text-xs overflow-x-auto max-h-28">{{ $app['public_key'] }}</pre>
                    </div>
                    @endif
                </div>
            </div>

            @if (!empty($lastUsers))
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-6 py-3 border-b border-base-300 flex justify-between items-center">
                    <span class="font-semibold text-base-content">Recent Users</span>
                    <a href="{!! adminUrl('applications' . '/tokens/' . ($appId)) !!}"
                       class="text-sm text-primary hover:underline">All Tokens</a>
                </div>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">User</th>
                            <th class="px-4 py-2 text-left">Scope</th>
                            <th class="px-4 py-2 text-left">IP</th>
                            <th class="px-4 py-2 text-left">Last Used</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-300">
                    @foreach ($lastUsers as $u)
                        <tr class="hover:bg-base-200">
                            <td class="px-4 py-2">
                                <a href="{!! adminUrl('users/view/') !!}{!! (int) ($u['userid'] ?? 0) !!}"
                                   class="text-primary hover:underline">
                                    {{ $u['username'] ?? '—' }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-xs text-base-content/60">{{ $u['scope'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-base-content/60">{{ $u['ipaddress'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs">{!! ($u['lastused'] ?? 0) > 0 ? localDateTime((int) $u['lastused']) : '—' !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
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
            <div class="mt-8">
                <div class="flex items-baseline justify-between mb-2">
                    <h3 class="font-semibold text-base-content">Declared capabilities</h3>
                    @if (!empty($caps['synced_at']))
                        <span class="text-xs text-base-content/60">
                            last pushed {{ (string) $caps['synced_at'] }}
                        </span>
                    @endif
                </div>

                @if (empty($caps['resources']) && empty($caps['conditions']))
                    <p class="text-sm text-base-content/70">
                        This client has not pushed a capabilities manifest. Until it does,
                        the server knows no resource or scope names for it.
                    </p>
                @else
                    @if (!empty($caps['resources']))
                        <table class="w-full text-sm mb-4">
                            <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                                <tr><th class="px-4 py-2 text-left">Resource</th><th class="px-4 py-2 text-left">Scopes</th></tr>
                            </thead>
                            <tbody class="divide-y divide-base-300">
                            @foreach ($caps['resources'] as $resource)
                                <tr>
                                    <td class="px-4 py-2 align-top">
                                        <span class="font-mono{!! $resource['is_active'] ? '' : ' line-through text-base-content/40' !!}">
                                            {{ $resource['name'] }}
                                        </span>
                                        @if (!empty($resource['description']))
                                            <div class="text-xs text-base-content/70">{{ (string) $resource['description'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 align-top">
                                        @if (empty($resource['scopes']))
                                            <span class="text-xs text-base-content/60">no scopes declared</span>
                                        @else
                                            @foreach ($resource['scopes'] as $scope)
                                                <span class="badge badge-sm mr-1 mb-1{!! $scope['is_active'] ? ' badge-neutral' : ' badge-ghost line-through' !!}"
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
                        <h4 class="text-sm font-semibold mb-1 text-base-content">Condition keys</h4>
                        <ul class="text-sm">
                        @foreach ($caps['conditions'] as $condition)
                            <li class="py-0.5{!! $condition['is_active'] ? '' : ' line-through text-base-content/40' !!}">
                                <span class="font-mono">{{ $condition['key'] }}</span>
                                <span class="text-xs text-base-content/70">
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
            <div class="mt-8" id="webhooks">
                <h3 class="font-semibold text-base-content mb-2">Webhooks</h3>

                @if (empty($webhooks))
                    <p class="text-sm text-base-content/70 mb-4">
                        No endpoints. The application can register its own through
                        <code>/Webhook/register</code>, or you can add one here.
                    </p>
                @else
                    <div class="overflow-x-auto mb-4">
                    <table class="table table-sm text-sm">
                        <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                            <tr><th>Event</th><th>Endpoint</th><th>Set by</th><th>Deliveries</th><th></th></tr>
                        </thead>
                        <tbody>
                        @foreach ($webhooks as $hook)
                            @php $hookId = (int) $hook['webhook_id']; @endphp
                            <tr>
                                <td class="font-mono text-xs">{{ (string) $hook['webhook_type'] }}</td>
                                <td class="font-mono text-xs break-all">{{ (string) $hook['endpoint_url'] }}</td>
                                <td>
                                    @if (($hook['registered_by'] ?? '') === 'admin')
                                        <span class="badge badge-sm badge-primary">Administrator</span>
                                    @else
                                        <span class="badge badge-sm badge-ghost">Application</span>
                                    @endif
                                </td>
                                <td class="text-xs whitespace-nowrap">
                                    @php $ev = $hook['events'] ?? []; @endphp
                                    <span title="sent">{!! (int) ($ev['sent'] ?? 0) !!} sent</span>
                                    · <span title="waiting for the delivery schedule">{!! (int) ($ev['pending'] ?? 0) !!} pending</span>
                                    · <span class="{!! !empty($ev['failed']) ? 'text-error' : '' !!}">{!! (int) ($ev['failed'] ?? 0) !!} failed</span>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <form method="post" class="inline" action="{!! adminUrl('applications/webhooksecret/' . $hookId) !!}">
                                        {!! $csrf !!}
                                        <input type="hidden" name="appid" value="{!! $appId !!}">
                                        <button type="submit" class="btn btn-outline btn-xs">Show secret</button>
                                    </form>
                                    <form method="post" class="inline" action="{!! adminUrl('applications/webhookrotate/' . $hookId) !!}">
                                        {!! $csrf !!}
                                        <input type="hidden" name="appid" value="{!! $appId !!}">
                                        <button type="submit" class="btn btn-outline btn-warning btn-xs"
                                                data-confirm="Issue a new signing secret? The current one stops verifying immediately.">Regenerate</button>
                                    </form>
                                    <form method="post" class="inline" action="{!! adminUrl('applications/webhookdelete/' . $hookId) !!}">
                                        {!! $csrf !!}
                                        <input type="hidden" name="appid" value="{!! $appId !!}">
                                        <button type="submit" class="btn btn-outline btn-error btn-xs"
                                                data-confirm="Remove this endpoint? Events not yet delivered to it will not be.">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif

                <form method="post" action="{!! adminUrl('applications/webhook') !!}"
                      class="card bg-base-100 border border-base-300 shadow-xs p-4">
                    {!! $csrf !!}
                    <input type="hidden" name="appid" value="{!! $appId !!}">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                        <fieldset class="fieldset">
                            <legend class="fieldset-legend">Event</legend>
                            <select name="webhook_type" class="select select-sm w-full" required>
                                @foreach ($webhookTypes as $type)
                                    <option value="{{ $type }}">{{ $type }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset md:col-span-2">
                            <legend class="fieldset-legend">Endpoint URL</legend>
                            <input type="url" name="endpoint_url" class="input input-sm w-full font-mono"
                                   placeholder="https://app.internal/hooks" pattern="{!! $httpsOnly ? 'https://.*' : 'https?://.*' !!}" required>
                        </fieldset>
                        <fieldset class="fieldset md:col-span-2">
                            <legend class="fieldset-legend">Signing secret</legend>
                            <input type="text" name="secret" class="input input-sm w-full font-mono" autocomplete="off"
                                   placeholder="Leave empty to generate one">
                        </fieldset>
                    </div>
                    <p class="text-xs text-base-content/60 mt-2">
                        An address entered here is delivered to as written, including one on a
                        private network.{!! $httpsOnly ? ' It must be https.' : '' !!} A type that already has an endpoint is replaced.
                    </p>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary btn-sm">Save endpoint</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
