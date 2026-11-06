@php /**
 * Privacy Settings page (plain-CSS theme).
 *
 * Variables:
 *   $this->privacySettings — array {analytics: bool, marketing: bool, notifysignin: bool}
 *   $this->mailingLists    — opt-in lists: list<array{list, label, description, status}>
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'privacy'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Privacy') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <div class="card">
                <div class="card-body">
                    <p style="font-size:.9em;color:#666;margin-bottom:16px">
                        {{ t('Control how your data is used. You can update these preferences at any time.') }}
                    </p>

                    <form method="post" action="{!! sURL . $routeBase !!}/privacy">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <div class="form-group" style="margin-bottom:20px">
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                                <input type="checkbox" name="analytics" id="analytics"
                                       style="margin-top:3px"
                                       {!! !empty($this->privacySettings['analytics']) ? 'checked' : '' !!}>
                                <div>
                                    <strong>{{ t('Analytics & Usage Data') }}</strong>
                                    <p style="font-size:.85em;color:#666;margin:4px 0 0">
                                        {{ t('Allow anonymous usage analytics to help us improve the service.') }}
                                    </p>
                                </div>
                            </label>
                        </div>

                        <div class="form-group" style="margin-bottom:24px">
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                                <input type="checkbox" name="marketing" id="marketing"
                                       style="margin-top:3px"
                                       {!! !empty($this->privacySettings['marketing']) ? 'checked' : '' !!}>
                                <div>
                                    <strong>{{ t('Marketing Communications') }}</strong>
                                    <p style="font-size:.85em;color:#666;margin:4px 0 0">
                                        {{ t('Receive occasional emails about new features and offers.') }}
                                    </p>
                                </div>
                            </label>
                        </div>

                        <div class="form-group" style="margin-bottom:24px">
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                                <input type="checkbox" name="notifysignin" id="notifysignin"
                                       style="margin-top:3px"
                                       {!! !empty($this->privacySettings['notifysignin']) ? 'checked' : '' !!}>
                                <div>
                                    <strong>{{ t('New sign-in alerts') }}</strong>
                                    <p style="font-size:.85em;color:#666;margin:4px 0 0">
                                        {{ t('Email me when my account is signed in to from a browser or device I have not used before. You will not be emailed when your browser updates, or when your network address changes.') }}
                                    </p>
                                </div>
                            </label>
                        </div>

                        @php foreach ((array) ($this->mailingLists ?? []) as $choice):
    $listId  = 'list-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $choice['list']);
    $checked = in_array($choice['status'], ['confirmed', 'pending'], true); @endphp
                        <div class="form-group" style="margin-bottom:24px">
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                                <input type="checkbox" id="{!! $listId !!}" name="lists[{{ (string) $choice['list'] }}]" value="1"
                                       style="margin-top:3px"
                                       {!! $checked ? 'checked' : '' !!}>
                                <div>
                                    <strong>{{ (string) $choice['label'] }}</strong>
                                    <p style="font-size:.85em;color:#666;margin:4px 0 0">
                                        {{ (string) $choice['description'] }}
                                        @if ($choice['status'] === 'pending')
                                            <strong>{{ t('Waiting for you to confirm: check your inbox for the link.') }}</strong>
                                        @endif
                                    </p>
                                </div>
                            </label>
                        </div>
                        @endforeach

                        <button type="submit" class="btn btn-primary">{{ t('Save Preferences') }}</button>
                        {{-- Browser notifications, outside the form on purpose: this is not a
preference the server can store. Permission and the subscription live
in the browser, and a checkbox here would tick and do nothing. --}}
                        <hr style="margin:18px 0">
                        <h3 style="margin:0 0 4px;font-size:1em">{{ t('Browser notifications') }}</h3>
                        <p style="color:#666;font-size:0.85em;margin:0 0 10px">
                            {{ t('Get told on this device when something happens on your account, even when this site is not open. This browser only.') }}
                        </p>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
                            <button type="button" class="btn btn-outline-primary" data-push-subscribe hidden>
                                {{ t('Turn on notifications') }}
                            </button>
                            <span style="color:#666;font-size:0.85em" data-push-state></span>
                        </div>
                        <script src="{!! assetUrl('assets/js/push.js') !!}"{!! \Pramnos\Push\BrowserScript::attributes() !!} defer></script>
                    </form>
                </div>
            </div>

            <p style="margin-top:16px;font-size:.85em;color:#666">
                {{ t('Under GDPR you have the right to access, rectify, and erase your data.') }}
                {!! sprintf(htmlspecialchars(t('%s or %s.'), ENT_QUOTES, 'UTF-8'), '<a href="' . sURL . $routeBase . '/exportdata">' . htmlspecialchars(t('Download your data'), ENT_QUOTES, 'UTF-8') . '</a>', '<a href="' . sURL . $routeBase . '/deleteaccount" style="color:#c00">' . htmlspecialchars(t('delete your account'), ENT_QUOTES, 'UTF-8') . '</a>') !!}
            </p>
        </div>
    </div>

</div>
