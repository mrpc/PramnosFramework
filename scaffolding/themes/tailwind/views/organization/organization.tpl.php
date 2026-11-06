@php /**
 * The organisations the signed-in person manages (Tailwind theme).
 *
 * Variables: $this->organizations — id => name
 */
$orgs = is_array($this->organizations ?? null) ? $this->organizations : [];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Inside the account area: the same breadcrumb, sidebar and messages as every account page.
$this->activeNav = 'organization'; @endphp
<div class="container mx-auto px-4 py-8">

    @include('../partials/account_breadcrumb')

    @if ($this->hasMessages())
        <div role="status" class="alert alert-success mb-4">{!! $this->_printMessages() !!}</div>
    @endif
    @if ($this->hasErrors())
        <div role="alert" class="alert alert-error mb-4">{!! $this->_printErrors() !!}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        @include('../partials/account_sidebar')

        <div class="md:col-span-3 min-w-0">
            <h2 class="text-lg font-semibold mb-4">{{ t('Your organisations') }}</h2>
            @if ($orgs === [])
                <p class="text-sm opacity-70">{{ t('You do not manage any organisation. An administrator makes you a manager of one.') }}</p>
            @else
                <ul class="list-disc pl-6">
                    @foreach ($orgs as $id => $name)
                        <li><a href="{!! sURL . 'organization/view/' . (int) $id !!}">{{ $name }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
