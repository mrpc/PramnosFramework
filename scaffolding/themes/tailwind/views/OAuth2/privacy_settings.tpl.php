@php /**
 * Privacy page (Tailwind theme).
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool, notifysignin: bool}
 *   $this->mailingLists    — opt-in lists: list<array{list, label, description, status}>
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'privacy'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')
    <h2 class="text-2xl font-bold text-base-content mb-6">{{ t('Privacy') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success mb-4">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error mb-4">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3 space-y-6">
            <div class="card bg-base-100 shadow-sm p-6">
                <p class="text-sm text-base-content/70 mb-6">
                    {{ t('Control how your data is used. You can update these preferences at any time.') }}
                </p>

                <form method="post" action="{!! sURL . $routeBase !!}/privacy">
                    {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                    <div class="space-y-5 mb-6">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <div class="mt-0.5">
                                <input type="checkbox" id="analytics" name="analytics"
                                       class="checkbox checkbox-primary checkbox-sm"
                                       {!! !empty($this->privacySettings['analytics']) ? 'checked' : '' !!}>
                            </div>
                            <div>
                                <span class="font-semibold text-base-content">{{ t('Analytics & Usage Data') }}</span>
                                <p class="text-sm text-base-content/70 mt-0.5">
                                    {{ t('Allow anonymous usage analytics to help us improve the service.') }}
                                </p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <div class="mt-0.5">
                                <input type="checkbox" id="marketing" name="marketing"
                                       class="checkbox checkbox-primary checkbox-sm"
                                       {!! !empty($this->privacySettings['marketing']) ? 'checked' : '' !!}>
                            </div>
                            <div>
                                <span class="font-semibold text-base-content">{{ t('Marketing Communications') }}</span>
                                <p class="text-sm text-base-content/70 mt-0.5">
                                    {{ t('Receive occasional emails about new features and offers.') }}
                                </p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer mt-4">
                            <div class="mt-0.5">
                                <input type="checkbox" id="notifysignin" name="notifysignin"
                                       class="checkbox checkbox-primary checkbox-sm"
                                       {!! !empty($this->privacySettings['notifysignin']) ? 'checked' : '' !!}>
                            </div>
                            <div>
                                <span class="font-semibold text-base-content">{{ t('New sign-in alerts') }}</span>
                                <p class="text-sm text-base-content/70 mt-0.5">
                                    {{ t('Email me when my account is signed in to from a browser or device I have not used before. You will not be emailed when your browser updates, or when your network address changes.') }}
                                </p>
                            </div>
                        </label>
                        @php foreach ((array) ($this->mailingLists ?? []) as $choice):
    $listId  = 'list-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $choice['list']);
    $checked = in_array($choice['status'], ['confirmed', 'pending'], true); @endphp
                        <label class="flex items-start gap-3 cursor-pointer mt-4">
                            <div class="mt-0.5">
                                <input type="checkbox" id="{!! $listId !!}" name="lists[{{ (string) $choice['list'] }}]" value="1"
                                       class="checkbox checkbox-primary checkbox-sm"
                                       {!! $checked ? 'checked' : '' !!}>
                            </div>
                            <div>
                                <span class="font-semibold text-base-content">{{ (string) $choice['label'] }}</span>
                                <p class="text-sm text-base-content/70 mt-0.5">
                                    {{ (string) $choice['description'] }}
                                    @if ($choice['status'] === 'pending')
                                        <strong>{{ t('Waiting for you to confirm: check your inbox for the link.') }}</strong>
                                    @endif
                                </p>
                            </div>
                        </label>
                        @endforeach
                    </div>

                    <button type="submit"
                            class="btn btn-primary">
                        {{ t('Save Preferences') }}
                    </button>
                </form>
            </div>

            {{-- Browser notifications, outside the form on purpose.

The others are a column in a table and a checkbox can express them. This one is
not a preference the server can store: `Notification.requestPermission()` and
`PushManager.subscribe()` exist only in a page, only mean anything from a click,
and the answer lives in the browser rather than in the account. Putting it in the
form would offer a checkbox that ticks and does nothing.

Hidden until the script says otherwise, so a browser that cannot do this at all
shows nothing rather than a control that fails when pressed. --}}
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="p-6">
                    <h3 class="font-semibold text-base-content">{{ t('Browser notifications') }}</h3>
                    <p class="text-sm text-base-content/70 mt-1">
                        {{ t('Get told on this device when something happens on your account — a new sign-in, a message — even when this site is not open. It applies to this browser only: turning it on here does not turn it on on your phone.') }}
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" class="btn btn-outline btn-sm" data-push-subscribe hidden>
                            {{ t('Turn on notifications') }}
                        </button>
                        <span class="text-sm text-base-content/70" data-push-state></span>
                    </div>
                </div>
            </div>
            <script src="{!! assetUrl('assets/js/push.js') !!}"{!! \Pramnos\Push\BrowserScript::attributes() !!} defer></script>

            <div class="text-sm text-base-content/70">
                <p>
                    {{ t('Under GDPR you have the right to access, rectify, and erase your data.') }}
                    {!! sprintf(htmlspecialchars(t('%s or %s.'), ENT_QUOTES, 'UTF-8'), '<a href="' . sURL . $routeBase . '/exportdata" class="text-primary hover:underline">' . htmlspecialchars(t('Download your data'), ENT_QUOTES, 'UTF-8') . '</a>', '<a href="' . sURL . $routeBase . '/deleteaccount" class="text-error hover:underline">' . htmlspecialchars(t('delete your account'), ENT_QUOTES, 'UTF-8') . '</a>') !!}
                </p>
            </div>
        </div>
    </div>

</div>
