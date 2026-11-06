@php /**
 * What each user type is, and what it may do (Bootstrap theme).
 *
 * Variables:
 *   $this->types        — floor => label, from UserTypes::labels()
 *   $this->tones        — floor => tone
 *   $this->capabilities — floor => the capabilities declared *at* that floor
 *   $this->resolved     — floor => everything that floor ends up with
 *   $this->areaFloor    — the administration area's own usertype floor
 *
 * Rendered from the registry, so it cannot fall out of step with the behaviour: it reads
 * exactly what the guards read. An application that declared its own types, tones or
 * capabilities sees its own answer here.
 */
$types   = is_array($this->types ?? null) ? $this->types : [];
$tones   = is_array($this->tones ?? null) ? $this->tones : [];
$declared = is_array($this->capabilities ?? null) ? $this->capabilities : [];
$resolved = is_array($this->resolved ?? null) ? $this->resolved : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$toneCls = ['danger' => 'bg-danger text-white', 'warning' => 'bg-warning', 'neutral' => 'bg-secondary text-white', 'primary' => 'bg-info text-white']; @endphp
<div class="container-fluid py-4">
    @php $this->activeNav = 'users_types'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="{!! adminUrl('Users') !!}" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">User types</h2>
    </div>

    <div class="card mb-3">
        <div class="overflow-x-auto">
            <table class="table table-sm mb-0">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th class="w-20">Value</th>
                        <th>Type</th>
                        <th>Declared at this level</th>
                        <th>Ends up with</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($types as $floor => $label)
                    @php $tone = $tones[$floor] ?? 'primary';
                    $own  = $declared[$floor] ?? [];
                    $all  = $resolved[$floor] ?? []; @endphp
                    <tr>
                        <td class="font-mono text-xs">{!! (int) $floor !!}</td>
                        <td>
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $toneCls[$tone] ?? $toneCls['primary'] }}">
                                {{ $label }}
                            </span>
                        </td>
                        <td class="text-xs">
                            @if ($own === [])
                            <span class="text-muted">—</span>
                            @else
                                @foreach ($own as $capability)
                                <code class="badge bg-light text-dark">{{ $capability }}</code>
                                @endforeach
                            @endif
                        </td>
                        <td class="text-xs">
                            @if (in_array('*', $all, true))
                            <span class="badge bg-danger">everything</span>
                            @else
                            <span class="text-muted">{!! count($all) !!} capabilit{!! count($all) === 1 ? 'y' : 'ies' !!}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6"><div class="card card-body small>
            <h3 class="font-semibold">How a value becomes a type</h3>
            <p class="text-muted mb-0">
                A usertype is an <strong>integer read as a threshold</strong>: a value falls
                into the highest type at or below it, so <code>95</code> is
                {{ \Pramnos\User\UserTypes::label(95) }} and capabilities
                accumulate downwards.
            </p>
            <p class="text-muted mb-0">
                <code>1</code> is the exception — the machine account a Client Credentials
                grant authenticates as. It is matched <em>exactly</em> and inherits nothing,
                because it is not a very senior person.
            </p>
        </div>

        <div class="col-md-6"><div class="card card-body small>
            <h3 class="font-semibold">Two different questions</h3>
            <p class="text-muted mb-0">
                A <strong>capability</strong> answers "may this kind of account reach this
                kind of screen". A <strong>permission</strong> answers "may this account
                touch this record", and lives per user — see a user's own screen.
            </p>
            <p class="text-muted mb-0">
                The administration area also applies its own floor, currently
                <code>{!! (int) ($this->areaFloor ?? 0) !!}</code>, which is a
                separate decision from any type's capabilities: it is what stops the area
                being browsable at all.
            </p>
        </div>
    </div>
</div>
