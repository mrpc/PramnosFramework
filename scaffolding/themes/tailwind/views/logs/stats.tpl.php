@php /**
 * Log statistics (Tailwind theme).
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
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-base-content mb-4">Log Statistics</h2>
    @include('_toolbar')

    <div class="card bg-base-100 border border-base-300 shadow-sm p-5">
        @if (empty($this->stats))
            <div class="rounded-md bg-info/10 text-info px-4 py-3 text-sm">No log files found.</div>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-base-content/70 border-b border-base-300">
                            <th class="px-3 py-2 font-medium">File Name</th>
                            <th class="px-3 py-2 font-medium">Size</th>
                            <th class="px-3 py-2 font-medium">Lines</th>
                            <th class="px-3 py-2 font-medium">Structured JSON</th>
                            <th class="px-3 py-2 font-medium">Last Modified</th>
                            <th class="px-3 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-300">
                        @foreach ($this->stats as $stat)
                            <tr class="hover:bg-base-200">
                                <td class="px-3 py-2">
                                    <a class="text-primary hover:underline" href="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($stat['name']))) !!}">
                                        {{ $stat['name'] }}
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-base-content">{{ $stat['size_formatted'] }}</td>
                                <td class="px-3 py-2 text-base-content">{!! number_format($stat['lines']) !!}</td>
                                <td class="px-3 py-2 text-base-content">
                                    {!! $stat['json_percentage'] !!}%
                                    @if (!empty($stat['level_distribution']))
                                        <div class="text-xs text-base-content/60">
                                            @php $levels = [];
                                            foreach ($stat['level_distribution'] as $level => $count) {
                                                $levels[] = ucfirst($level) . ': ' . $count;
                                            }
                                            echo htmlspecialchars(implode(', ', $levels)); @endphp
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-base-content">{{ $stat['modified_formatted'] }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex gap-1">
                                        <a href="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($stat['name']))) !!}"
                                           class="btn btn-info btn-xs" title="View">View</a>
                                        <form method="post" action="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/clearFile/' . (htmlspecialchars($stat['name']))) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit"
                                           class="btn btn-error btn-xs" title="Clear"
                                           data-confirm="Are you sure you want to clear this log?">Clear</button></form>
                                        <a href="{!! adminUrl(($this->logsRoute ?? 'Logs') . '/raw') !!}?file={{ $stat['name'] }}"
                                           class="btn btn-neutral btn-xs" title="Raw View" target="_blank">Raw</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                <div class="rounded-lg border border-base-300 p-4">
                    <div class="text-xs text-base-content/70">Total Size</div>
                    <div class="text-xl font-bold text-base-content mt-1">{{ $this->totalSizeHuman }}</div>
                </div>
                <div class="rounded-lg border border-base-300 p-4">
                    <div class="text-xs text-base-content/70">Total Lines</div>
                    <div class="text-xl font-bold text-base-content mt-1">{!! number_format($this->totalLines) !!}</div>
                </div>
                <div class="rounded-lg border border-base-300 p-4">
                    <div class="text-xs text-base-content/70">JSON Entries</div>
                    <div class="text-xl font-bold text-base-content mt-1">{!! $this->jsonPercent !!}%</div>
                </div>
                <div class="rounded-lg border border-base-300 p-4">
                    <div class="text-xs text-base-content/70">Log Files</div>
                    <div class="text-xl font-bold text-base-content mt-1">{!! (int) $this->totalFiles !!}</div>
                </div>
            </div>
        @endif
    </div>
</div>
