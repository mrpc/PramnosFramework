@php /**
 * Browser errors (Bootstrap theme): what the application's pages hit in the browser, one row per
 * distinct error, most recently seen first.
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->logsRoute — the address the log screens answer under (`Logs`)
 *   $this->jsErrors    — array[] {idhash, error, message, kind, source, line, col, stack, page,
 *                                agent, release, seentimes, firstseen, lastseen}
 *   $this->total, $this->page, $this->pages — paging
 *   $this->notice    — string|null  why there is nothing to list
 */
$route  = $this->logsRoute ?? 'Logs';
$when   = static fn ($t): string => $t ? date('d/m/Y H:i', (int) $t) : '';
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); @endphp
<div class="container py-4">
    <h2 class="mb-4">Browser Errors</h2>
    @include('_toolbar')

    @if ($this->notice)
        <div role="status" class="alert alert-info">{{ $this->notice }}</div>
    @elseif (empty($this->jsErrors))
        <p class="text-body-secondary">No errors reported. Pages report them when their head has <code>&lt;meta name="pf-client-errors"&gt;</code>.</p>
    @else
        <div class="d-flex align-items-center justify-content-between mb-3">
            <span class="small text-body-secondary">{!! (int) $this->total !!} distinct errors</span>
            {!! \Pramnos\Html\Icon::postControl(adminUrl($route . '/clearjserrors'), 'Clear all', ['class' => 'btn btn-danger btn-sm', 'data-confirm' => 'Forget every browser error?']) !!}
        </div>
        <div class="card shadow-sm table-responsive">
            <table class="table table-sm align-top mb-0">
                <thead><tr><th>Error</th><th class="text-end">Seen</th><th>First</th><th>Last</th><th></th></tr></thead>
                <tbody>
                @foreach ($this->jsErrors as $row)
                    <tr>
                        <td>
                            <div class="font-monospace small text-break">{{ $row['message'] ?? '' ?: $row['error'] }}</div>
                            <div class="small text-body-secondary text-break">
                                {{ trim(($row['source'] ?? '') . (isset($row['line']) && $row['line'] !== null ? ':' . $row['line'] : '')) }}
                                @if (!empty($row['page'])) · {{ $row['page'] }}@endif
                                @if (!empty($row['kind'])) · {{ $row['kind'] }}@endif
                            </div>
                            @if (!empty($row['stack']))
                                <details class="mt-1"><summary class="small">Stack</summary><pre class="small mb-0">{{ $row['stack'] }}</pre></details>
                            @endif
                            <div class="small text-body-tertiary text-break">{{ $row['agent'] }}</div>
                        </td>
                        <td class="text-end font-monospace">{!! (int) $row['seentimes'] !!}</td>
                        <td class="text-nowrap small">{{ $when($row['firstseen']) }}</td>
                        <td class="text-nowrap small">{{ $when($row['lastseen']) }}</td>
                        <td>{!! \Pramnos\Html\Icon::postControl(adminUrl($route . '/deletejserror/' . rawurlencode((string) $row['idhash'])), 'Delete', ['class' => 'btn btn-link btn-sm text-danger']) !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($this->pages > 1)
            <nav class="mt-3"><ul class="pagination pagination-sm">
                @for ($p = 1; $p <= $this->pages; $p++)
                    <li class="page-item{!! $p === (int) $this->page ? ' active' : '' !!}"><a class="page-link" href="{{ adminUrl($route . '/jserrors') . '?page=' . $p }}">{!! $p !!}</a></li>
                @endfor
            </ul></nav>
        @endif
    @endif
</div>
