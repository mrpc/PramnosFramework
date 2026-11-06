@php /**
 * Filter log entries (plain-CSS theme).
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
        case 'error':   return 'badge badge-danger';
        case 'warning': return 'badge badge-warning';
        case 'notice':
        case 'info':    return 'badge badge-info';
        default:        return 'badge badge-secondary';
    }
}; @endphp
<div class="page-section">
    <h2 style="margin:0 0 16px">Filter Log Files</h2>
    @include('_toolbar')

    <div class="card">
        <div class="card-body">
        <form method="post">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px">
                <div>
                    <label for="file" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Select Log File:</label>
                    <select name="file" id="file"
                            style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                        <option value="">-- Select Log File --</option>
                        @foreach ($this->whitelist as $log)
                            <option value="{{ $log }}" {!! $this->file === $log ? 'selected' : '' !!}>
                                {{ $log }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="limit" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Maximum Results:</label>
                    <select name="limit" id="limit"
                            style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                        @foreach ([100, 250, 500, 1000] as $opt)
                            <option value="{!! $opt !!}" {!! $this->limit === $opt ? 'selected' : '' !!}>{!! $opt !!} entries</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px">
                <div>
                    <label for="start_date" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" value="{{ $this->startDate }}"
                           style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                </div>
                <div>
                    <label for="end_date" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">End Date:</label>
                    <input type="date" id="end_date" name="end_date" value="{{ $this->endDate }}"
                           style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                </div>
            </div>

            <div style="margin-bottom:16px">
                <span style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Log Levels:</span>
                <div style="display:flex;flex-wrap:wrap;gap:12px">
                    @foreach ($this->availableLevels as $level => $label)
                        <label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;color:#555">
                            <input type="checkbox" name="levels[]" value="{!! $level !!}"
                                   {!! in_array($level, $this->levels) ? 'checked' : '' !!}>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p style="font-size:12px;color:#aaa;margin:4px 0 0">Leave empty to include all levels</p>
            </div>

            <div style="margin-bottom:16px">
                <label for="query" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Search Query:</label>
                <input type="text" id="query" name="query" value="{{ $this->query }}" placeholder="Search in log messages"
                       style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
            </div>

            <button type="submit" class="btn" style="background:#2563eb;color:#fff">Apply Filters</button>
        </form>

        @if ($this->hasResults)
            <hr style="margin:24px 0;border:none;border-top:1px solid #eee">
            <h3 style="font-size:18px;font-weight:600;color:#333;margin:0 0 12px">
                Filter Results <span style="font-size:14px;font-weight:normal;color:#aaa">{!! count($this->results) !!} entries found</span>
            </h3>

            @if (empty($this->results))
                <div class="badge badge-info" style="display:block;padding:12px 16px">No log entries match the specified filters.</div>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:14px">
                        <thead>
                            <tr style="text-align:left;color:#888;border-bottom:1px solid #eee">
                                <th style="padding:8px 12px;width:176px">Timestamp</th>
                                <th style="padding:8px 12px;width:96px">Level</th>
                                <th style="padding:8px 12px">Message</th>
                                <th style="padding:8px 12px;width:96px">Context</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->results as $entry)
                                <tr style="border-bottom:1px solid #f0f0f0;vertical-align:top">
                                    <td style="padding:8px 12px;color:#666;font-family:monospace;font-size:12px">{{ $entry['timestamp'] ?? '' }}</td>
                                    <td style="padding:8px 12px">
                                        <span class="{!! $levelBadge($entry['level'] ?? '') !!}">
                                            {{ ucfirst($entry['level'] ?? 'info') }}
                                        </span>
                                    </td>
                                    <td style="padding:8px 12px;color:#555">{{ $entry['message'] ?? '' }}</td>
                                    <td style="padding:8px 12px">
                                        @if (!empty($entry['context']))
                                            <details>
                                                <summary style="cursor:pointer;color:#2563eb;font-size:12px">View</summary>
                                                <pre style="margin-top:8px;background:#f7f7f7;border:1px solid #eee;border-radius:4px;padding:8px;font-size:12px;overflow-x:auto">{{ json_encode($entry['context'], JSON_PRETTY_PRINT) }}</pre>
                                            </details>
                                        @else
                                            <span style="color:#aaa;font-size:12px">None</span>
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
