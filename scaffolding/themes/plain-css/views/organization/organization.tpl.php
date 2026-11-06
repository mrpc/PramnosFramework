@php /**
 * The organisations the signed-in person manages (plain CSS theme).
 *
 * Variables: $this->organizations — id => name
 */
$orgs = is_array($this->organizations ?? null) ? $this->organizations : [];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Inside the account area: the same breadcrumb, sidebar and messages as every account page.
$this->activeNav = 'organization'; @endphp
<div class="page-section">

    @include('../partials/account_breadcrumb')

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="account-grid">

        @include('../partials/account_sidebar')

        <div>
            <h2 class="mb-3">{{ t('Your organisations') }}</h2>
            @if ($orgs === [])
                <p class="pf-muted small">{{ t('You do not manage any organisation. An administrator makes you a manager of one.') }}</p>
            @else
                <ul class="">
                    @foreach ($orgs as $id => $name)
                        <li><a href="{!! sURL . 'organization/view/' . (int) $id !!}">{{ $name }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
