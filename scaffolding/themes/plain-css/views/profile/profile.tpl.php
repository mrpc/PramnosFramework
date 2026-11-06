@php /**
 * User profile page (plain-CSS theme) — editable.
 *
 * Matches the fields the Account::profile() POST handler saves
 * (firstname / lastname / email / phone). Username and account dates are shown
 * read-only. Uses the shared account sidebar for consistent navigation.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::profile):
 *   $this->routeBase — Account controller route base
 *   $this->user      — User object; `avatarurl` and `photo` drive the picture card,
 *                      whose form posts to Account::profilephoto(); the picture is
 *                      cropped and sized in the browser first (Html\ImageUpload)
 */
$routeBase = $this->routeBase ?? 'Account';
$u         = $this->user;
$this->activeNav = 'profile'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2 style="margin-bottom:16px">{{ t('My Profile') }}</h2>

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <div class="card" style="margin-bottom:20px">
                <div class="card-header"><strong>{{ t('Profile Picture') }}</strong></div>
                <div class="card-body" style="padding:20px;display:flex;gap:20px;align-items:center;flex-wrap:wrap">
                    <img id="profile-photo-preview"{!! empty($u->avatarurl) ? ' hidden' : ' src="' . htmlspecialchars((string) $u->avatarurl) . '"' !!} alt="{{ t('Your profile picture') }}" width="96" height="96" style="border-radius:50%">
                    <form method="post" action="{!! sURL . $routeBase !!}/profilephoto" enctype="multipart/form-data">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <label for="photo">{{ t('Choose a picture') }}</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp"{!! \Pramnos\User\ProfilePhoto::uploadAttributes() !!}>
                        <button type="submit" class="btn">{{ t('Upload') }}</button>
                        @if (\Pramnos\User\ProfilePhoto::gravatarAllowed())
                            <button type="submit" name="gravatar" value="1" class="btn">{{ t('Use my Gravatar') }}</button>
                        @endif
                        @if ((int) ($u->photo ?? 0) > 0)
                            <button type="submit" name="remove" value="1" class="btn">{{ t('Remove') }}</button>
                        @endif
                        @if (\Pramnos\User\ProfilePhoto::gravatarAllowed())
                            <p style="margin:6px 0 0;font-size:0.9em;opacity:.8">{{ t('Use my Gravatar asks Gravatar for the picture of your email address, once, and keeps a copy here.') }}</p>
                        @endif
                    </form>
                    {!! \Pramnos\Html\ImageUpload::assets() !!}
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>{{ t('Personal Information') }}</strong></div>
                <div class="card-body" style="padding:20px">
                    <form method="post" action="{!! sURL . $routeBase !!}/profile">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}

                        <div style="margin-bottom:16px">
                            <label style="display:block;margin-bottom:4px;font-weight:500">{{ t('Username') }}</label>
                            <input type="text" value="{{ (string) ($u->username ?? '') }}"
                                   style="width:100%;padding:8px 12px;border:1px solid #eee;border-radius:4px;box-sizing:border-box;background:#f8f8f8;color:#888" disabled>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                            <div>
                                <label for="firstname" style="display:block;margin-bottom:4px;font-weight:500">{{ t('First Name') }}</label>
                                <input type="text" id="firstname" name="firstname" value="{{ (string) ($u->firstname ?? '') }}"
                                       style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                            </div>
                            <div>
                                <label for="lastname" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Last Name') }}</label>
                                <input type="text" id="lastname" name="lastname" value="{{ (string) ($u->lastname ?? '') }}"
                                       style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                            </div>
                        </div>

                        <div style="margin-bottom:16px">
                            <label for="email" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Email') }}</label>
                            <input type="email" id="email" name="email" value="{{ (string) ($u->email ?? '') }}" required
                                   style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                        </div>

                        <div style="margin-bottom:20px">
                            <label for="phone" style="display:block;margin-bottom:4px;font-weight:500">{{ t('Phone') }}</label>
                            <input type="text" id="phone" name="phone" value="{{ (string) ($u->phone ?? '') }}"
                                   style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
                        </div>

                        <button type="submit" class="btn">{{ t('Save Changes') }}</button>
                    </form>
                </div>
            </div>

            @if (!empty($u->regdate) || !empty($u->lastlogin))
            <div class="card" style="margin-top:16px">
                <div class="card-header"><strong>{{ t('Account') }}</strong></div>
                <div class="card-body" style="padding:0">
                    <table style="width:100%;border-collapse:collapse;font-size:.9em">
                        @if (!empty($u->regdate))
                        <tr style="border-bottom:1px solid #f0f0f0">
                            <th style="text-align:left;padding:10px 16px;width:160px;color:#444">{{ t('Member Since') }}</th>
                            <td style="padding:10px 16px;color:#666">{{ localDate(is_numeric($u->regdate) ? (int) $u->regdate : (int) strtotime((string) $u->regdate)) }}</td>
                        </tr>
                        @endif
                        @if (!empty($u->lastlogin))
                        <tr>
                            <th style="text-align:left;padding:10px 16px;color:#444">{{ t('Last Login') }}</th>
                            <td style="padding:10px 16px;color:#666">{{ localDateTime(is_numeric($u->lastlogin) ? (int) $u->lastlogin : (int) strtotime((string) $u->lastlogin)) }}</td>
                        </tr>
                        @endif
                    </table>
                </div>
            </div>
            @endif
        </div>
    </div>

</div>
