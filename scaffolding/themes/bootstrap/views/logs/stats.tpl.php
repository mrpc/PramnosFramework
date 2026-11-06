@php /**
 * Log statistics (Bootstrap theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->logsRoute    — the address the log screens answer under (`Logs`)
 *   $this->stats          — array[] {name, size_formatted, lines, json_percentage,
 *                                    level_distribution[level=>count], modified_formatted, size}
 *   $this->totalSizeHuman — string  formatted total size
 *   $this->totalLines     — int
 *   $this->totalFiles     — int
 *   $this->jsonPercent     — float   average JSON percentage
 */
// The area's own base — every link on this screen is another admin screen.
$sURL = adminUrl(); @endphp
<div class="container py-4">
    <h2 class="mb-4">Log Statistics</h2>
    @include('_toolbar')

    <div class="card shadow-sm">
        <div class="card-body">
            @if (empty($this->stats))
                <div role="status" class="alert alert-info mb-0">No log files found.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Size</th>
                                <th>Lines</th>
                                <th>Structured JSON</th>
                                <th>Last Modified</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->stats as $stat)
                                <tr>
                                    <td>
                                        <a href="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($stat['name']))) !!}">
                                            {{ $stat['name'] }}
                                        </a>
                                    </td>
                                    <td>{{ $stat['size_formatted'] }}</td>
                                    <td>{!! number_format($stat['lines']) !!}</td>
                                    <td>
                                        {!! $stat['json_percentage'] !!}%
                                        @if (!empty($stat['level_distribution']))
                                            <div class="small text-muted">
                                                @php $levels = [];
                                                foreach ($stat['level_distribution'] as $level => $count) {
                                                    $levels[] = ucfirst($level) . ': ' . $count;
                                                }
                                                echo htmlspecialchars(implode(', ', $levels)); @endphp
                                            </div>
                                        @endif
                                    </td>
                                    <td>{{ $stat['modified_formatted'] }}</td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($stat['name']))) !!}"
                                               class="btn btn-sm btn-info" title="View">View</a>
                                            <form method="post" action="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/clearFile/' . (htmlspecialchars($stat['name']))) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                                               class="btn btn-sm btn-danger" title="Clear"
                                               data-confirm="Are you sure you want to clear this log?">Clear</button></form>
                                            <a href="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/raw') !!}?file={{ $stat['name'] }}"
                                               class="btn btn-sm btn-secondary" title="Raw View" target="_blank">Raw</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Summary cards -->
                <div class="row g-4 mt-2">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">Total Size</div>
                                <div class="fs-4 fw-bold mt-1">{{ $this->totalSizeHuman }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">Total Lines</div>
                                <div class="fs-4 fw-bold mt-1">{!! number_format($this->totalLines) !!}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">JSON Entries</div>
                                <div class="fs-4 fw-bold mt-1">{!! $this->jsonPercent !!}%</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">Log Files</div>
                                <div class="fs-4 fw-bold mt-1">{!! (int) $this->totalFiles !!}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
