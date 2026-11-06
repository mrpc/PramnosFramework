@php /**
 * Filter log entries (Bootstrap theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->whitelist       — string[] available log files
 *   $this->availableLevels — array level=>Label
 *   $this->file, $this->startDate, $this->endDate, $this->query — string
 *   $this->levels          — string[] selected levels
 *   $this->limit           — int
 *   $this->results         — array[] {id, timestamp, level, message, context}
 *   $this->hasResults      — bool  whether a filter run happened
 */
$levelBadge = static function (string $level): string {
    switch (strtolower($level)) {
        case 'emergency':
        case 'alert':
        case 'critical':
        case 'error':   return 'bg-danger';
        case 'warning': return 'bg-warning text-dark';
        case 'notice':
        case 'info':    return 'bg-info';
        default:        return 'bg-secondary';
    }
}; @endphp
<div class="container py-4">
    <h2 class="mb-4">Filter Log Files</h2>
    @include('_toolbar')

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="file" class="form-label">Select Log File:</label>
                        <select name="file" id="file" class="form-select">
                            <option value="">-- Select Log File --</option>
                            @foreach ($this->whitelist as $log)
                                <option value="{{ $log }}" {!! $this->file === $log ? 'selected' : '' !!}>
                                    {{ $log }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="limit" class="form-label">Maximum Results:</label>
                        <select name="limit" id="limit" class="form-select">
                            @foreach ([100, 250, 500, 1000] as $opt)
                                <option value="{!! $opt !!}" {!! $this->limit === $opt ? 'selected' : '' !!}>{!! $opt !!} entries</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="start_date" class="form-label">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" value="{{ $this->startDate }}"
                               class="form-control">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="end_date" class="form-label">End Date:</label>
                        <input type="date" id="end_date" name="end_date" value="{{ $this->endDate }}"
                               class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <span class="form-label d-block">Log Levels:</span>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ($this->availableLevels as $level => $label)
                            <div class="form-check">
                                <input type="checkbox" id="level_{{ $level }}" name="levels[]" value="{!! $level !!}"
                                       {!! in_array($level, $this->levels) ? 'checked' : '' !!}
                                       class="form-check-input">
                                <label class="form-check-label" for="level_{{ $level }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <p class="form-text">Leave empty to include all levels</p>
                </div>

                <div class="mb-3">
                    <label for="query" class="form-label">Search Query:</label>
                    <input type="text" id="query" name="query" value="{{ $this->query }}" placeholder="Search in log messages"
                           class="form-control">
                </div>

                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </form>

            @if ($this->hasResults)
                <hr class="my-4">
                <h3 class="h5 mb-3">
                    Filter Results <span class="small fw-normal text-muted">{!! count($this->results) !!} entries found</span>
                </h3>

                @if (empty($this->results))
                    <div role="status" class="alert alert-info">No log entries match the specified filters.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped align-top">
                            <thead>
                                <tr>
                                    <th style="width:11rem;">Timestamp</th>
                                    <th style="width:6rem;">Level</th>
                                    <th>Message</th>
                                    <th style="width:6rem;">Context</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->results as $entry)
                                    <tr>
                                        <td class="text-muted font-monospace small">{{ $entry['timestamp'] ?? '' }}</td>
                                        <td>
                                            <span class="badge rounded-pill {!! $levelBadge($entry['level'] ?? '') !!}">
                                                {{ ucfirst($entry['level'] ?? 'info') }}
                                            </span>
                                        </td>
                                        <td>{{ $entry['message'] ?? '' }}</td>
                                        <td>
                                            @if (!empty($entry['context']))
                                                <details>
                                                    <summary class="text-primary small" style="cursor:pointer;">View</summary>
                                                    <pre class="mt-2 bg-light border rounded p-2 small">{{ json_encode($entry['context'], JSON_PRETTY_PRINT) }}</pre>
                                                </details>
                                            @else
                                                <span class="text-muted small">None</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
