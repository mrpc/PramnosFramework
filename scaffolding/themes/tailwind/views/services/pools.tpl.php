@php /**
 * Worker pools (Tailwind / daisyUI theme) — each pool's settings, on a card of its own.
 *
 * Variables:
 *   $this->pools     — pools as the supervisor last saw them, plus saved rows it has not seen
 *   $this->taskTypes — the task types to tick: those with a handler, those in the queue, those a pool names
 *
 * Every field says what it does, under it. A pool declared in code shows its declared values as
 * placeholders, and a field left blank keeps them. The last card adds a pool. Every change is a
 * POST with the session's token, applied by the supervisor on its next cycle.
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$pools  = $this->pools ?? [];
$known  = (array) ($this->taskTypes ?? []);
$fields = [
    'floor'        => [t('Minimum workers'), t('Always keep at least this many workers running.')],
    'ceiling'      => [t('Maximum workers'), t('Never run more than this many workers.')],
    'grow_above'   => [t('Grow above'), t('Start another worker when more than this many tasks are waiting.')],
    'shrink_below' => [t('Shrink below'), t('Stop a worker when fewer than this many tasks are waiting. Keep it below Grow above, so the pool settles.')],
    'load_percent' => [t('CPU limit (%)'), t("Add no workers while the server's CPU load is above this share of its capacity.")],
    'cooldown'     => [t('Cooldown'), t('Supervisor cycles to wait after a change before resizing again.')],
]; @endphp
<div class="px-4 py-6">
    {!! \Pramnos\Html\Breadcrumb::admin([[$e(t('Services')), adminUrl('Services')], [$e(t('Worker pools')), '']]) !!}
    <div class="flex justify-between items-center mb-2">
        <h2 class="mb-0">{{ t('Worker pools') }}</h2>
        <a href="{{ adminUrl('Services') }}" class="btn btn-outline btn-sm">{{ t('Back to services') }}</a>
    </div>
    <p class="text-sm text-base-content/60 mb-6">{{ t('A pool runs workers for its task types, as many as its backlog needs within its limits. The supervisor reads these settings on its next cycle.') }}</p>

    @foreach (array_merge($pools, [null]) as $i => $pool)
        @php // The last entry is the empty card that adds a pool.
        $adding = $pool === null;
        $name   = $adding ? '' : (string) $pool['name'];
        $code   = !$adding && ($pool['source'] ?? '') === 'code';
        $row    = $adding ? null : ($pool['row'] ?? null);
        $config = $adding ? [] : (array) ($pool['config'] ?? []);
        // Ticked: what was saved here; for a code pool nothing saved means its declared types.
        $ticked = $row !== null && ($row['types'] ?? null) !== null
            ? explode(',', (string) $row['types'])
            : ($code || $adding ? [] : (array) ($pool['types'] ?? []));
        $id = 'pool-' . $i; @endphp
        <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"{!! $adding ? ' data-pool-new' : ' data-pool-card="' . $e($name) . '"' !!}>
            <div class="px-4 py-3 border-b border-base-300 flex flex-wrap gap-2 justify-between items-center">
                @if ($adding)
                    <strong>{{ t('Add a pool') }}</strong>
                @else
                    <div class="flex flex-wrap gap-2 items-center">
                        <strong>{{ $name }}</strong>
                        <span class="badge {!! $code ? 'badge-ghost' : 'badge-info' !!}">{{ $code ? t('declared in code') : t('added here') }}</span>
                        @if (empty($pool['enabled']))
                            <span class="badge badge-neutral">{{ t('Stopped') }}</span>
                        @else
                            <span class="badge badge-success">{{ t('Running') }}</span>
                        @endif
                        @if (!empty($pool['waiting']))
                            <span class="badge badge-warning">{{ t('waiting for supervisor') }}</span>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        @if (empty($pool['enabled']))
                            <form method="post" action="{{ adminUrl('Services/poolstart/') . urlencode($name) }}" class="m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="back" value="pools"><button type="submit" class="btn btn-outline btn-success btn-xs">{{ t('Start') }}</button></form>
                        @else
                            <form method="post" action="{{ adminUrl('Services/poolstop/') . urlencode($name) }}" class="m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<input type="hidden" name="back" value="pools"><button type="submit" class="btn btn-outline btn-warning btn-xs">{{ t('Stop') }}</button></form>
                        @endif
                        @if ($row !== null)
                            <form method="post" action="{{ adminUrl('Services/pooldelete/') . urlencode($name) }}" class="m-0">
                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                <button type="submit" class="btn btn-outline btn-error btn-xs"
                                        data-confirm="{{ $code ? t('Go back to the limits declared in code?') : t('Remove this pool? Its workers are stopped.') }}">{{ $code ? t('Reset to code') : t('Remove') }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>
            <form method="post" action="{{ adminUrl('Services/poolsave') }}" class="p-4">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                @if ($adding)
                    <div class="mb-4 max-w-xs">
                        <label class="block text-sm font-semibold mb-1" for="{!! $id !!}-name">{{ t('Name') }}</label>
                        <input class="input input-bordered input-sm w-full" id="{!! $id !!}-name" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,59}" placeholder="mail">
                        <p class="text-xs text-base-content/60 mt-1">{{ t('Letters, digits, dots, dashes and underscores.') }}</p>
                    </div>
                @else
                    <input type="hidden" name="name" value="{{ $name }}">
                @endif

                <fieldset class="mb-4">
                    <legend class="block text-sm font-semibold mb-1">{{ t('Task types') }}</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach ($known as $type)
                            <label class="flex items-center gap-1 text-sm">
                                <input type="checkbox" class="checkbox checkbox-sm" name="types[]" value="{{ $type }}"{!! in_array($type, $ticked, true) ? ' checked' : '' !!}>
                                {{ $type }}
                            </label>
                        @endforeach
                    </div>
                    <input class="input input-bordered input-sm w-full max-w-md mt-2" name="types_other" aria-label="{{ t('Other task types') }}" placeholder="{{ t('Other task types, comma-separated') }}">
                    <p class="text-xs text-base-content/60 mt-1">{{ $code ? sprintf(t('None ticked keeps the types declared in code: %s.'), implode(', ', (array) ($pool['types'] ?? [])) ?: t('every type')) : t('None ticked: the pool takes every type.') }}</p>
                </fieldset>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($fields as $field => [$label, $help])
                        @php $declared = $field === 'load_percent'
                            ? (isset($config['load_ceiling']) ? (string) round((float) $config['load_ceiling'] * 100) : '')
                            : (string) ($config[$field] ?? ''); @endphp
                        <div>
                            <label class="block text-sm font-semibold mb-1" for="{!! $id . '-' . $field !!}">{{ $label }}</label>
                            <input class="input input-bordered input-sm w-full" id="{!! $id . '-' . $field !!}" name="{{ $field }}" inputmode="numeric"
                                   value="{{ $row[$field] ?? '' }}" placeholder="{{ $declared }}">
                            <p class="text-xs text-base-content/60 mt-1">{{ $help }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($code)
                    <p class="text-xs text-base-content/60 mt-3">{{ t('A blank field keeps the value declared in code, shown in grey.') }}</p>
                @endif
                <div class="mt-4"><button type="submit" class="btn btn-primary btn-sm">{{ $adding ? t('Add pool') : t('Save') }}</button></div>
            </form>
        </div>
    @endforeach
</div>
