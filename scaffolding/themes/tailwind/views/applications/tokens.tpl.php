{{-- Tokens for an application (Tailwind theme).

Variables:
  $this->app    — application row array
  $this->tokens — iterable token rows --}}
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-4">
        <a href="{!! adminUrl('Applications') !!}" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 >Tokens — {{ $this->app['name'] ?? '' }}</h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Token ID</th><th>User ID</th><th>Scope</th><th>Last Used</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->tokens ?? []) as $tok)
                    <tr>
                        <td>{!! (int)$tok['tokenid'] !!}</td>
                        <td>{!! (int)($tok['userid'] ?? 0) !!}</td>
                        <td>{{ $tok['scope'] ?? '' }}</td>
                        <td class="text-base-content/60 text-xs">{{ $tok['lastused'] ?? '' }}</td>
                        <td class="text-base-content/60 text-xs">{!! !empty($tok['expires']) ? htmlspecialchars($tok['expires']) : '—' !!}</td>
                        <td class="text-right">
                            <form method="post" action="{!! adminUrl('Tokens' . '/revoke/' . ((int)$tok['tokenid'])) !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="Revoke token?">Revoke</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->tokens))
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">No active tokens.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
