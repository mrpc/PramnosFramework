@php /**
 * One help page (tailwind theme).
 *
 * Variables:
 *   $this->page    — slug, title, section, updated
 *   $this->html    — the page, drawn by Help::pageHtml(), which escapes first
 *   $this->listUrl — every page
 */
$c = ['wrap' => 'px-4 py-6', 'card' => 'card bg-base-100 border border-base-300 p-4 mb-4', 'muted' => 'text-sm opacity-70', 'input' => 'input input-sm input-bordered', 'btn' => 'btn btn-sm btn-primary', 'h1' => 'text-2xl font-semibold mb-3', 'h2' => 'text-lg font-semibold mt-4'];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); @endphp
<div class="{!! $c['wrap'] !!}">
    <p class="{!! $c['muted'] !!}"><a href="{{ $this->listUrl }}">← {{ t('Help') }}</a>{!! $this->page['section'] !== '' ? ' · ' . $e(t($this->page['section'])) : '' !!}</p>
    <article class="pf-help-page prose max-w-none">
        {!! $this->html !!}
    </article>
    @if ($this->page['updated'] !== '')
        <p class="{!! $c['muted'] !!}">{{ sprintf(t('Updated %s'), $this->page['updated']) }}</p>
    @endif
</div>
