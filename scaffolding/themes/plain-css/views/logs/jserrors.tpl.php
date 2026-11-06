@php /**
 * Browser errors (plain-CSS theme): what the application's pages hit in the browser, one row per
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
<div class="container">
    <h2>Browser Errors</h2>
    @include('_toolbar')

    @if ($this->notice)
        <div role="status" class="alert alert-info">{{ $this->notice }}</div>
    @elseif (empty($this->jsErrors))
        <p class="pf-muted">No errors reported. Pages report them when their head has <code>&lt;meta name="pf-client-errors"&gt;</code>.</p>
    @else
        <div class="pf-row-split">
            <span class="pf-muted">{!! (int) $this->total !!} distinct errors</span>
            {!! \Pramnos\Html\Icon::postControl(adminUrl($route . '/clearjserrors'), 'Clear all', ['class' => 'btn btn-danger btn-sm', 'data-confirm' => 'Forget every browser error?']) !!}
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Error</th><th class="text-right">Seen</th><th>First</th><th>Last</th><th></th></tr></thead>
                <tbody>
                @foreach ($this->jsErrors as $row)
                    <tr>
                        <td>
                            <div><code>{{ $row['message'] ?? '' ?: $row['error'] }}</code></div>
                            <div class="pf-muted">
                                {{ trim(($row['source'] ?? '') . (isset($row['line']) && $row['line'] !== null ? ':' . $row['line'] : '')) }}
                                @if (!empty($row['page'])) · {{ $row['page'] }}@endif
                                @if (!empty($row['kind'])) · {{ $row['kind'] }}@endif
                            </div>
                            @if (!empty($row['stack']))
                                <details><summary>Stack</summary><pre>{{ $row['stack'] }}</pre></details>
                            @endif
                            <div class="pf-muted">{{ $row['agent'] }}</div>
                        </td>
                        <td class="text-right">{!! (int) $row['seentimes'] !!}</td>
                        <td>{{ $when($row['firstseen']) }}</td>
                        <td>{{ $when($row['lastseen']) }}</td>
                        <td>{!! \Pramnos\Html\Icon::postControl(adminUrl($route . '/deletejserror/' . rawurlencode((string) $row['idhash'])), 'Delete', ['class' => 'btn btn-link btn-sm']) !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($this->pages > 1)
            <nav class="pf-pagination">
                @for ($p = 1; $p <= $this->pages; $p++)
                    @if ($p === (int) $this->page)<span aria-current="page">{!! $p !!}</span>
                    @else<a href="{{ adminUrl($route . '/jserrors') . '?page=' . $p }}">{!! $p !!}</a>@endif
                @endfor
            </nav>
        @endif
    @endif
</div>
