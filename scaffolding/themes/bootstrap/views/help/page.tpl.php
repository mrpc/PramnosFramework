@php /**
 * One help page (bootstrap theme).
 *
 * Variables:
 *   $this->page    — slug, title, section, updated
 *   $this->html    — the page, drawn by Help::pageHtml(), which escapes first
 *   $this->listUrl — every page
 */
$c = ['wrap' => 'container-fluid py-4', 'card' => 'card card-body mb-3', 'muted' => 'text-muted small', 'input' => 'form-control form-control-sm', 'btn' => 'btn btn-sm btn-primary', 'h1' => 'h3', 'h2' => 'h5 mt-3'];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); @endphp
<div class="{!! $c['wrap'] !!}">
    <p class="{!! $c['muted'] !!}"><a href="{{ $this->listUrl }}">← {{ t('Help') }}</a>{!! $this->page['section'] !== '' ? ' · ' . $e(t($this->page['section'])) : '' !!}</p>
    <article class="pf-help-page">
        {!! $this->html !!}
    </article>
    @if ($this->page['updated'] !== '')
        <p class="{!! $c['muted'] !!}">{{ sprintf(t('Updated %s'), $this->page['updated']) }}</p>
    @endif
</div>
