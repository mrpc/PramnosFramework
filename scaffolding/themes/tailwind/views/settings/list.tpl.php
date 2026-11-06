{{-- Raw key-value settings DataTable (Tailwind theme).

Variables:
  $this->settings — array of ['key', 'value', 'readonly'] --}}
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h2 class="text-xl font-semibold">Raw Settings</h2>
            <p class="text-sm text-base-content/60">All key-value pairs stored in the settings table.</p>
        </div>
        <div class="flex gap-2">
            <a href="{!! adminUrl('settings/edit') !!}" class="btn btn-primary btn-sm">+ New Setting</a>
            <a href="{!! adminUrl('settings') !!}" class="btn btn-outline btn-sm">System Settings</a>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                <tr>
                    <th class="px-4 py-3 text-left">Key</th>
                    <th class="px-4 py-3 text-left">Value</th>
                    <th class="px-4 py-3 text-right w-32"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-300">
            @foreach (($this->settings ?? []) as $row)
                <tr class="hover:bg-base-200">
                    <td class="px-4 py-2"><code class="text-xs bg-base-200 px-1 py-0.5 rounded-sm">{{ $row['key'] ?? '' }}</code></td>
                    <td class="px-4 py-2 truncate max-w-xs text-base-content/80">{{ $row['value'] ?? '' }}</td>
                    <td class="px-4 py-2 text-right">
                        @if (!($row['readonly'] ?? false))
                            <a href="{!! adminUrl('settings/edit/') !!}{!! urlencode($row['key'] ?? '') !!}" class="btn btn-outline btn-xs">Edit</a>
                            <form method="post" action="{!! adminUrl('settings/delete/') !!}{!! urlencode($row['key'] ?? '') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-error btn-xs"
                               data-confirm="Delete this setting?">Delete</button></form>
                        @else
                            <span class="text-xs text-base-content/60">Read-only</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            @if (empty($this->settings))
                <tr><td colspan="3" class="px-4 py-8 text-center text-base-content/60">No settings found.</td></tr>
            @endif
            </tbody>
        </table>
    </div>
</div>
