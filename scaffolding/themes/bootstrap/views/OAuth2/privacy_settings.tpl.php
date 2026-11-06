@php /**
 * Privacy page (Bootstrap theme). GDPR Article 7 — conditions for consent.
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool, notifysignin: bool}
 *   $this->mailingLists    — opt-in lists: list<array{list, label, description, status}>
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'privacy'; @endphp
<div class="container py-4">

    @include('../partials/account_breadcrumb')
    <h2 class="mb-4">{{ t('Privacy') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-danger">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="row g-4">

        @include('../partials/account_sidebar')

        <div class="col-lg-9 col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ t('Control how your data is used. You can update these preferences at any time. Changes take effect immediately.') }}
                    </p>

                    <form method="post" action="{!! sURL . $routeBase !!}/privacy">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="analytics" name="analytics" role="switch"
                                       {!! !empty($this->privacySettings['analytics']) ? 'checked' : '' !!}>
                                <label class="form-check-label fw-semibold" for="analytics">
                                    {{ t('Analytics & Usage Data') }}
                                </label>
                            </div>
                            <div class="form-text ms-4">
                                {{ t('Allow collection of anonymous usage data to help us improve the service. No personal information is shared with third parties.') }}
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="marketing" name="marketing" role="switch"
                                       {!! !empty($this->privacySettings['marketing']) ? 'checked' : '' !!}>
                                <label class="form-check-label fw-semibold" for="marketing">
                                    {{ t('Marketing Communications') }}
                                </label>
                            </div>
                            <div class="form-text ms-4">
                                {{ t('Receive occasional emails about new features, tips, and offers. You can unsubscribe at any time.') }}
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="notifysignin" name="notifysignin" role="switch"
                                       {!! !empty($this->privacySettings['notifysignin']) ? 'checked' : '' !!}>
                                <label class="form-check-label fw-semibold" for="notifysignin">
                                    {{ t('New sign-in alerts') }}
                                </label>
                            </div>
                            <div class="form-text ms-4">
                                {{ t('Email me when my account is signed in to from a browser or device I have not used before. You will not be emailed when your browser updates, or when your network address changes.') }}
                            </div>
                        </div>

                        @php foreach ((array) ($this->mailingLists ?? []) as $choice):
    $listId  = 'list-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $choice['list']);
    $checked = in_array($choice['status'], ['confirmed', 'pending'], true); @endphp
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="{!! $listId !!}" name="lists[{{ (string) $choice['list'] }}]" value="1"
                                       {!! $checked ? 'checked' : '' !!}>
                                <label class="form-check-label fw-semibold" for="{!! $listId !!}">
                                    {{ (string) $choice['label'] }}
                                </label>
                            </div>
                            <div class="form-text ms-4">
                                {{ (string) $choice['description'] }}
                                @if ($choice['status'] === 'pending')
                                    <strong>{{ t('Waiting for you to confirm: check your inbox for the link.') }}</strong>
                                @endif
                            </div>
                        </div>
                        @endforeach

                        <button type="submit" class="btn btn-primary">{{ t('Save Preferences') }}</button>
                        {{-- Browser notifications, outside the form on purpose: this is not a
preference the server can store. Permission and the subscription live
in the browser, and a checkbox here would tick and do nothing. --}}
                        <hr class="my-4">
                        <h5>{{ t('Browser notifications') }}</h5>
                        <p class="text-muted small">
                            {{ t('Get told on this device when something happens on your account, even when this site is not open. This browser only.') }}
                        </p>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" data-push-subscribe hidden>
                                {{ t('Turn on notifications') }}
                            </button>
                            <span class="text-muted small" data-push-state></span>
                        </div>
                        <script src="{!! assetUrl('assets/js/push.js') !!}"{!! \Pramnos\Push\BrowserScript::attributes() !!} defer></script>
                    </form>
                </div>
            </div>

            <div class="mt-4">
                <h5>{{ t('Your Data Rights') }}</h5>
                <p class="text-muted small">
                    {{ t('Under GDPR you have the right to access, rectify, and erase your personal data.') }}
                    {!! sprintf(htmlspecialchars(t('%s or %s.'), ENT_QUOTES, 'UTF-8'), '<a href="' . sURL . $routeBase . '/exportdata">' . htmlspecialchars(t('Download a copy of your data'), ENT_QUOTES, 'UTF-8') . '</a>', '<a href="' . sURL . $routeBase . '/deleteaccount" class="text-danger">' . htmlspecialchars(t('delete your account'), ENT_QUOTES, 'UTF-8') . '</a>') !!}
                </p>
            </div>
        </div>
    </div>

</div>
