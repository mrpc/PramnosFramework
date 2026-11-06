@php /**
 * One queue task (Tailwind theme).
 *
 * Variables:
 *   $this->task    — the row
 *   $this->fields  — [label => text]: every field it has a value for
 *   $this->payload — [key => text], each value readable JSON; `''` for a payload that is not an object
 *   $this->status  — the status badge (HTML)
 *   $this->actions — what can be done with it, as POST buttons (HTML)
 */
$e  = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$id = (int) ($this->task['taskid'] ?? 0);
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('Queue')), adminUrl('Queue')], [$e('#' . $id), '']]); @endphp
<div class="max-w-6xl mx-auto py-6 px-4">
    {!! $bc !!}
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="{!! adminUrl('Queue') !!}" class="btn btn-outline btn-xs">&larr; {{ t('Back') }}</a>
        <h2>{{ sprintf(t('Task #%d'), $id) }}</h2>
        {!! $this->status !!}
        <span class="ml-auto flex gap-1">{!! $this->actions !!}</span>
    </div>
    <div class="card bg-base-100 border border-base-300 mb-4">
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 text-sm p-5">
            @foreach ($this->fields as $label => $value)
                <div class="{!! strlen($value) > 80 ? 'sm:col-span-2 lg:col-span-3' : '' !!}">
                    <dt class="font-semibold text-base-content/60 text-xs uppercase tracking-wide">{{ $label }}</dt>
                    <dd class="break-words whitespace-pre-wrap">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
    <div class="card bg-base-100 border border-base-300">
        <div class="p-5">
            <h3 class="font-semibold mb-3">{{ t('Payload') }}</h3>
            @foreach ($this->payload as $key => $value)
                @if ($key !== '')<div class="text-xs font-semibold text-base-content/60 uppercase tracking-wide mt-2">{{ $key }}</div>@endif
                <pre class="bg-base-200 rounded p-3 text-xs overflow-x-auto whitespace-pre-wrap break-words">{{ $value }}</pre>
            @endforeach
        </div>
    </div>
</div>
