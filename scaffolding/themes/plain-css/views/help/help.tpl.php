@php /**
 * Help — the installation's pages by section, and a search (plain-css theme).
 *
 * Variables:
 *   $this->bySection — section => list of pages (slug, title, questions, framework)
 *   $this->query     — what was searched for, or ''
 *   $this->found     — the pages that answer it, best first, or null when nothing was asked
 *   $this->pageUrl   — Closure(slug): the page's address
 *   $this->listUrl   — this page's address
 */
$c = ['wrap' => 'pf-container', 'card' => 'pf-card', 'muted' => 'pf-muted', 'input' => '', 'btn' => 'btn', 'h1' => '', 'h2' => ''];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$link = $this->pageUrl;
$list = static function (array $pages) use ($e, $link, $c): void {
    echo '<ul>';
    foreach ($pages as $page) {
        echo '<li><a href="' . $e($link($page['slug'])) . '">' . $e($page['title']) . '</a>';
        if ($page['questions'] !== []) {
            echo ' <span class="' . $c['muted'] . '">— ' . $e(implode(' · ', array_slice($page['questions'], 0, 2))) . '</span>';
        }
        echo '</li>';
    }
    echo '</ul>';
}; @endphp
<div class="{!! $c['wrap'] !!}">
    <h1 class="{!! $c['h1'] !!}">{{ t('Help') }}</h1>
    <form method="get" data-pf-tool="search_help" data-pf-tool-description="Search this application's help pages for how to do something. Answers with the matching pages." action="{{ $this->listUrl }}" role="search" class="{!! $c['card'] !!}" style="display:flex;gap:8px;align-items:center">
        <label for="help-q" class="pf-sr-only">{{ t('Search the help') }}</label>
        <input id="help-q" type="search" name="q" value="{{ $this->query }}" class="{!! $c['input'] !!}" style="flex:1" placeholder="{{ t('What do you want to do?') }}">
        <button type="submit" class="{!! $c['btn'] !!}">{{ t('Search') }}</button>
    </form>
    @if (is_array($this->found))
        <h2 class="{!! $c['h2'] !!}">{{ sprintf(t('Pages for “%s”'), $this->query) }}</h2>
        @if ($this->found === [])
            <p class="{!! $c['muted'] !!}">{{ t('No page answers that. Try other words.') }}</p>
        @php else: $list($this->found); endif; @endphp
    @endif
    @foreach ($this->bySection as $section => $pages)
        <h2 class="{!! $c['h2'] !!}">{{ t($section) }}</h2>
        @php $list($pages); @endphp
    @endforeach
    @if ($this->bySection === [])
        <p class="{!! $c['muted'] !!}">{{ t('There are no help pages yet.') }}</p>
    @endif
</div>
