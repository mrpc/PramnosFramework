@php /**
 * Export My Data — confirmation page (plain-CSS theme). GDPR Article 20.
 *
 * GET shows this confirmation; the form POSTs back to exportdata which streams
 * the JSON download.
 *
 * Variables:
 *   $this->routeBase — Account controller route base
 *   $this->exportSections  — string[] labels of what the export will contain
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'exportdata'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')
    <h2>{{ t('Export My Data') }}</h2>

    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <div class="card" style="max-width:560px">
                <div class="card-body">
                    <p style="color:#666;font-size:.9em;margin-bottom:12px">
                        {{ t('Download a copy of your personal data as a JSON file. The export includes:') }}
                    </p>
                    <ul style="margin:0 0 16px;padding-left:20px;color:#444;font-size:.9em">
                        @foreach (($this->exportSections ?? []) as $label)
                            <li>{{ $label }}</li>
                        @endforeach
                    </ul>
                    <p style="color:#888;font-size:.85em;margin-bottom:16px">
                        {{ t('Secrets (password, two-factor secret, passkey keys, security tokens) are never included.') }}
                    </p>
                    <form method="post" action="{!! sURL . $routeBase !!}/exportdata">
                        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                        <button type="submit" class="btn btn-primary">{{ t('Download my data') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
