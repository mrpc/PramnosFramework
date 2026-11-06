@php /**
 * One help page (plain-css theme).
 *
 * Variables:
 *   $this->page    — slug, title, section, updated
 *   $this->html    — the page, drawn by Help::pageHtml(), which escapes first
 *   $this->listUrl — every page
 */
$c = ['wrap' => 'pf-container', 'card' => 'pf-card', 'muted' => 'pf-muted', 'input' => '', 'btn' => 'btn', 'h1' => '', 'h2' => ''];
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
