@php /**
 * Delete Account confirmation page (plain-CSS theme).
 * GDPR Article 17 — right to erasure. Reached from Privacy.
 *
 * Variables:
 *   $this->routeBase    — Account controller route base
 *   $this->taskHandover — the scheduled AI tasks to hand over or delete (delete_account_tasks)
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'deleteaccount'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Delete Account') }}</h2>

    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <div class="card" style="border-color:#c00;max-width:520px">
                <div class="card-header" style="background:#c00;color:#fff"><strong>{{ t('Delete Account') }}</strong></div>
                <div class="card-body">

                    <div role="status" class="alert alert-warning">
                        <strong>{{ t('Warning — this action is permanent.') }}</strong><br>
                        {{ t('All your personal data, authorized applications, activity history, and account information will be permanently deleted. This cannot be undone.') }}
                    </div>

                    <form method="post" action="{!! sURL . $routeBase !!}/deleteaccount">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        @include('delete_account_tasks')
                        <div class="form-group">
                            <label for="del_password">{{ t('Current Password') }}</label>
                            <input type="password" id="del_password" name="password"
                                   class="form-control" required autocomplete="current-password" enterkeyhint="go">
                            {!! \Pramnos\Html\PasswordToggle::render('del_password', '', '') !!}
                        </div>
                        <div class="form-group">
                            <label for="del_confirm">{!! sprintf(htmlspecialchars(t('Type %s to confirm'), ENT_QUOTES, 'UTF-8'), '<strong>DELETE</strong>') !!}</label>
                            <input type="text" id="del_confirm" name="confirmation"
                                   class="form-control" placeholder="DELETE" required autocomplete="off">
                        </div>
                        <button type="submit" class="btn" style="width:100%;background:#c00;color:#fff;border-color:#c00;margin-top:8px">
                            {{ t('Permanently Delete My Account') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
