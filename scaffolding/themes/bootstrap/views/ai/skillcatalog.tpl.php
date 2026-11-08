@php /**
 * Skills (Bootstrap theme) — `Ai/skillcatalog`: the application's skills. Its skill files, read
 * only, and those installed from the catalogues (Pramnos\Llm\Chat\CatalogSkills) — each off until
 * somebody reads it and turns it on; a search of the catalogues, a skill read before it is
 * installed, and an installed one's newer version with what it changes.
 *
 * Variables:
 *   $this->files       — the skill files, by name: description, tools, folder, enabled
 *   $this->installed   — those installed: id, name, description, tools, skill, references, evals, role, status, source, commit, licence
 *   $this->search      — null, or the search's {found: [source], errors: {catalogue: why}}
 *   $this->q           — what was searched for
 *   $this->preview     — null, or a source read before it is installed (CatalogSkills::preview())
 *   $this->error       — why a preview or a check for a newer version did not work, or null
 *   $this->open        — the installed one asked about, or null
 *   $this->upstream    — for it: {newer, commit, preview?, changes?: {file: diff}}
 *   $this->tools       — name => what it does: the tools Ask has, to say which a skill names in vain
 *   $this->roleChoices — usertype => label: the roles a skill may be kept to
 *   $this->catalogues  — the catalogues searched
 *   $this->urls        — page, action (with %s), settings
 */
$c      = ['page' => 'pf-l-page', 'card' => 'card pf-l-card', 'muted' => 'text-muted small', 'bad' => 'text-danger small', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-default btn-outline-secondary', 'danger' => 'btn btn-sm btn-default btn-outline-danger text-danger', 'input' => 'form-control form-control-sm input-sm', 'select' => 'form-control input-sm form-select form-select-sm pf-l-select', 'textarea' => 'form-control', 'badge' => 'badge text-bg-secondary', 'row' => 'pf-l-row', 'field' => 'pf-l-field small', 'answer' => '', 'h1' => 'h3', 'h2' => 'h5'];
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t      = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token  = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$u      = (is_array($this->urls ?? null) ? $this->urls : []) + ['page' => adminUrl('Ai/skillcatalog'), 'action' => adminUrl('Ai/%s'), 'settings' => adminUrl('Ai/settings')];
$act    = static fn (string $action): string => str_replace('%s', $action, (string) $u['action']);
$known  = (array) ($this->tools ?? []);
$roles  = (array) ($this->roleChoices ?? []);
$split  = 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:12px';
$pre    = 'white-space:pre-wrap;margin:8px 0 0;max-height:28rem;overflow:auto';
$post   = static fn (string $action, int $id, string $label, string $class, string $extra = ''): string =>
    '<form method="post" action="' . $e($act($action)) . '" style="display:inline">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '">' . $extra
    . '<button class="' . $class . '">' . $e($label) . '</button></form>';
// The tools a skill names that Ask does not have: said, never silently dropped.
$unknown = static fn (array $tools): array => $known === [] ? [] : \Pramnos\Llm\Chat\CatalogSkills::unknownTools($tools, $known);
$toolLine = static function (array $tools) use ($c, $e, $t, $unknown): string {
    if ($tools === []) {
        return '';
    }
    $missing = $unknown($tools);

    return '<div class="' . $c['muted'] . '">' . $e($t('Tools: %s', implode(', ', $tools))) . '</div>'
        . ($missing !== [] ? '<div class="' . $c['bad'] . '">' . $e($t('Ask has no tool called %s: the skill works without it.', implode(', ', $missing))) . '</div>' : '');
};
$roleSelect = static function (?string $current) use ($c, $e, $t, $roles): string {
    $html = '<select name="role" class="' . $c['select'] . '" aria-label="' . $e($t('For whom')) . '"><option value="">' . $e($t('Everybody who has Ask')) . '</option>';
    foreach ($roles as $level => $label) {
        $html .= '<option value="' . (int) $level . '"' . ((string) $level === $current ? ' selected' : '') . '>' . $e($t('%s and up', $t($label))) . '</option>';
    }

    return $html . '</select>';
};
$installed = (array) ($this->installed ?? []);
$files     = (array) ($this->files ?? []);
$preview   = is_array($this->preview ?? null) ? $this->preview : null;
$open      = is_array($this->open ?? null) ? $this->open : null;
$upstream  = is_array($this->upstream ?? null) ? $this->upstream : null; @endphp
{!! \Pramnos\Theme\NeutralLayout::style() !!}
<div class="{!! $c['page'] !!}" data-pf-skill-catalog>
    <div style="{!! $split !!};align-items:center">
        <h1 class="{!! $c['h1'] !!}" style="margin:0">{{ $t('Skills') }}</h1>
        <a href="{{ $u['settings'] }}" class="{!! $c['btnGhost'] !!}">&larr; {{ $t('AI settings') }}</a>
    </div>
    <p class="{!! $c['muted'] !!}">{{ $t('The application\'s skills: a specialist\'s method Ask is handed when a question needs it. One installed from a catalogue is kept here, for everybody who has Ask, and is off until somebody reads it and turns it on. It opens nothing a reader may not already use; scripts are never taken.') }}</p>
@if (!empty($this->error))
    <p class="{!! $c['bad'] !!}">{{ $this->error }}</p>
@endif

@if ($open !== null && $upstream !== null)
    <section class="{!! $c['card'] !!}" data-pf-skill-upstream>
        <h2 class="{!! $c['h2'] !!}">{{ $t('%s and its catalogue', $open['name']) }}</h2>
    @if (empty($upstream['newer']))
        <p class="{!! $c['muted'] !!}">{{ $t('It is up to date: %s.', substr((string) $open['commit'], 0, 7)) }}</p>
    @else
        <p>{{ $t('A newer version: %s, installed %s. Taken, it is off until it is read again.', substr((string) $upstream['commit'], 0, 7), substr((string) $open['commit'], 0, 7)) }}</p>
        @foreach ((array) ($upstream['changes'] ?? []) as $file => $diff)
            <details open><summary><code>{{ $file }}</code></summary><pre style="{!! $pre !!}">{{ $diff }}</pre></details>
        @endforeach
        {!! $post('skillcatalogupdate', (int) $open['id'], $t('Take the newer version'), $c['btn']) !!}
    @endif
    </section>
@endif

    <section class="{!! $c['card'] !!}">
        <h2 class="{!! $c['h2'] !!}">{{ $t('Installed from a catalogue') }}</h2>
    @if ($installed === [])
        <p class="{!! $c['muted'] !!}">{{ $t('None yet. Search the catalogues below.') }}</p>
    @endif
    @foreach ($installed as $skill)
        @php $on = $skill['status'] === 'on'; @endphp
        <div style="border-top:1px solid rgba(127,127,127,.25);padding-top:10px;margin-top:10px" data-pf-skill="{{ $skill['id'] }}" data-pf-skill-status="{{ $skill['status'] }}">
            <div style="{!! $split !!}">
                <div style="min-width:0;flex:1 1 16rem">
                    <div class="{!! $c['row'] !!}"><strong>catalog:{{ $skill['name'] }}</strong>
                        <span class="{!! $c['badge'] !!}">{{ $t($on ? 'On' : 'Off — not read yet') }}</span></div>
                    <div class="{!! $c['muted'] !!}">{{ $skill['description'] }}</div>
                    <div class="{!! $c['muted'] !!}">{{ $skill['source'] }} @ {{ substr((string) $skill['commit'], 0, 7) }} · {{ $skill['licence'] !== '' ? $skill['licence'] : $t('no licence named') }}</div>
                    {!! $toolLine($skill['tools']) !!}
                </div>
                <div class="{!! $c['row'] !!}" style="justify-content:flex-end">
                    {!! $post('skillcatalogturn', (int) $skill['id'], $t($on ? 'Turn off' : 'I read it — turn on'), $on ? $c['btnGhost'] : $c['btn'], $on ? '' : '<input type="hidden" name="on" value="1">') !!}
                    <form method="post" action="{{ $act('skillcatalogrole') }}" style="display:inline-flex;gap:6px">{!! $token() !!}<input type="hidden" name="skill_id" value="{{ $skill['id'] }}">{!! $roleSelect($skill['role']) !!}<button class="{!! $c['btnGhost'] !!}">{{ $t('Keep to') }}</button></form>
                    <a class="{!! $c['btnGhost'] !!}" href="{{ $u['page'] . '?skill=' . $skill['id'] }}">{{ $t('Newer version?') }}</a>
                    {!! $post('skillcatalogdelete', (int) $skill['id'], $t('Delete'), $c['danger']) !!}
                </div>
            </div>
            <details style="margin-top:8px"><summary class="{!! $c['muted'] !!}">{{ $t('The method, and %d references', count($skill['references'])) }}</summary>
                <pre style="{!! $pre !!}">{{ $skill['skill'] }}</pre>
                @foreach ($skill['references'] as $file => $text)
                    <details><summary><code>{{ $file }}</code></summary><pre style="{!! $pre !!}">{{ $text }}</pre></details>
                @endforeach
            </details>
        </div>
    @endforeach
    </section>

    <section class="{!! $c['card'] !!}">
        <h2 class="{!! $c['h2'] !!}">{{ $t('Search the catalogues') }}</h2>
        <p class="{!! $c['muted'] !!}">{{ implode(', ', (array) ($this->catalogues ?? [])) }}</p>
        <form method="get" action="{{ $u['page'] }}" class="{!! $c['row'] !!}">
            <input type="search" name="q" value="{{ $this->q ?? '' }}" class="{!! $c['input'] !!}" placeholder="{{ $t('seo, pricing, support…') }}" aria-label="{{ $t('Search the catalogues') }}">
            <button class="{!! $c['btn'] !!}">{{ $t('Search') }}</button>
        </form>
    @if (is_array($this->search ?? null))
        @foreach ((array) ($this->search['errors'] ?? []) as $catalogue => $why)
            <p class="{!! $c['bad'] !!}">{{ $catalogue }}: {{ $why }}</p>
        @endforeach
        @if (($this->search['found'] ?? []) === [])
            <p class="{!! $c['muted'] !!}">{{ $t('Nothing found.') }}</p>
        @endif
        <ul>
        @foreach ((array) ($this->search['found'] ?? []) as $source)
            <li><a href="{{ $u['page'] . '?q=' . rawurlencode((string) ($this->q ?? '')) . '&source=' . rawurlencode((string) $source) }}">{{ $source }}</a></li>
        @endforeach
        </ul>
    @endif
    </section>

@if ($preview !== null)
    <section class="{!! $c['card'] !!}" data-pf-skill-preview>
        <h2 class="{!! $c['h2'] !!}">{{ $preview['name'] }}</h2>
        <div class="{!! $c['muted'] !!}">{{ $preview['description'] }}</div>
        <div class="{!! $c['muted'] !!}">{{ $preview['source'] }} @ {{ substr($preview['commit'], 0, 7) }} · {{ $preview['licence'] !== '' ? $preview['licence'] : $t('no licence named') }}</div>
        {!! $toolLine($preview['tools']) !!}
    @if ($preview['left_out'] !== [])
        <div class="{!! $c['muted'] !!}">{{ $t('Left out, never taken: %s', implode(', ', $preview['left_out'])) }}</div>
    @endif
        <details open style="margin-top:8px"><summary><code>SKILL.md</code></summary><pre style="{!! $pre !!}">{{ $preview['body'] }}</pre></details>
    @foreach ($preview['references'] as $file => $text)
        <details><summary><code>references/{{ $file }}</code></summary><pre style="{!! $pre !!}">{{ $text }}</pre></details>
    @endforeach
    @if ($preview['evals'] !== [])
        <div class="{!! $c['muted'] !!}">{{ $t('Evals: %s', implode(', ', array_keys($preview['evals']))) }}</div>
    @endif
        <form method="post" action="{{ $act('skillinstall') }}" class="{!! $c['row'] !!}" style="margin-top:10px">{!! $token() !!}
            <input type="hidden" name="source" value="{{ $preview['source'] . '@' . $preview['commit'] }}">
            <input name="as" class="{!! $c['input'] !!}" placeholder="{{ $preview['name'] }}" aria-label="{{ $t('Its name here') }}">
            {!! $roleSelect(null) !!}
            <button class="{!! $c['btn'] !!}">{{ $t('Install — off until read') }}</button>
        </form>
    </section>
@endif

    <section class="{!! $c['card'] !!}">
        <h2 class="{!! $c['h2'] !!}">{{ $t('From the application\'s files') }}</h2>
        <p class="{!! $c['muted'] !!}">{{ $t('Kept in the repository and changed there, with the skill:* commands. A skill installed here with the same name gives way to one of these.') }}</p>
    @if ($files === [])
        <p class="{!! $c['muted'] !!}">{{ $t('None.') }}</p>
    @endif
        <ul>
    @foreach ($files as $name => $skill)
            <li><strong>{{ $name }}</strong>{{ empty($skill['enabled']) ? ' — ' . $t('off (not read yet)') : '' }} <span class="{!! $c['muted'] !!}">{{ $skill['description'] }}</span>{!! $toolLine((array) $skill['tools']) !!}</li>
    @endforeach
        </ul>
    </section>
</div>
