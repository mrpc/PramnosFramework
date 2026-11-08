@php /**
 * Skills (Tailwind theme) — `chat/skills`: this person's own skills (Pramnos\Llm\Chat\StoredSkills),
 * the drafts Ask proposed from a conversation, the skills they offered to a role, and — for those who
 * manage them — every role's skill and the offers waiting for approval. With `?skill=`, one open to edit.
 *
 * Variables:
 *   $this->enabled     — whether the application turned stored skills on
 *   $this->mine        — this person's own: id, name, description, tools (list), body, status (on|off|draft)
 *   $this->offered     — the ones they offered to a role: the same, with role, status pending or on
 *   $this->roles       — every role's skill, pending first (empty unless $this->manage)
 *   $this->manage      — whether this person writes a role's skills and approves offers
 *   $this->skill       — the one open to edit, or null
 *   $this->roleChoices — usertype => label: the roles that reach this Ask, a skill is for from one up
 *   $this->tools       — name => what it does: the tools this person's Ask has, to choose from
 *   $this->urls        — page, action (with %s for the action's name), chat
 */
$c      = ['page' => 'max-w-4xl mx-auto px-4 py-6 flex flex-col gap-4', 'card' => 'card bg-base-100 border border-base-300 p-4', 'muted' => 'text-sm opacity-70', 'bad' => 'text-sm text-error', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'danger' => 'btn btn-sm btn-ghost text-error', 'input' => 'input input-sm input-bordered', 'select' => 'select select-sm select-bordered', 'textarea' => 'textarea textarea-bordered w-full', 'badge' => 'badge badge-ghost badge-sm', 'row' => 'flex flex-wrap items-center gap-2', 'field' => 'flex flex-col gap-1 text-sm', 'answer' => 'prose max-w-none', 'h1' => 'text-2xl font-semibold', 'h2' => 'text-lg font-semibold'];
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t      = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token  = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$u      = (is_array($this->urls ?? null) ? $this->urls : []) + ['page' => sURL . 'chat/skills', 'action' => sURL . 'chat/%s', 'chat' => sURL . 'chat'];
$act    = static fn (string $action): string => str_replace('%s', $action, (string) $u['action']);
$roles  = (array) ($this->roleChoices ?? []);
$tools  = (array) ($this->tools ?? []);
$open   = is_array($this->skill ?? null) ? $this->skill : null;
$states = ['on' => 'On', 'off' => 'Off', 'draft' => 'Draft', 'pending' => 'Waiting for approval'];
$roleOf = static fn (?string $role): string => $role === null ? '' : (ctype_digit($role) && isset($roles[(int) $role]) ? $t($roles[(int) $role]) : $role);
$grid   = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:12px';
$field  = 'display:flex;flex-direction:column;gap:6px';
$full   = 'width:100%;max-width:none';
$split  = 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:12px';
$post   = static fn (string $action, int $id, string $label, string $class, string $extra = ''): string =>
    '<form method="post" action="' . $e($act($action)) . '" style="display:inline">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '">' . $extra
    . '<button class="' . $class . '">' . $e($label) . '</button></form>';
/** One skill: what it is and its state on the left, what may be done with it on the right, its method under. */
$card   = static function (array $skill, bool $offer, bool $decide) use ($c, $e, $t, $token, $act, $u, $roles, $states, $roleOf, $split, $post): string {
    $id      = (int) $skill['id'];
    $actions = '';
    if ($decide) {
        $actions .= $post('skillapprove', $id, $t('Approve'), $c['btn']) . $post('skillreject', $id, $t('Turn down'), $c['danger']);
    } elseif ($skill['status'] !== 'pending') {
        $on       = $skill['status'] === 'on';
        $actions .= $post('skillturn', $id, $t($on ? 'Turn off' : ($skill['status'] === 'draft' ? 'Keep and turn on' : 'Turn on')), $on ? $c['btnGhost'] : $c['btn'], $on ? '' : '<input type="hidden" name="on" value="1">')
            . '<a class="' . $c['btnGhost'] . '" href="' . $e($u['page'] . '?skill=' . $id) . '">' . $e($t('Edit')) . '</a>';
    }
    if ($offer && $roles !== []) {
        $actions .= '<form method="post" action="' . $e($act('skillshare')) . '" style="display:inline-flex;gap:6px">' . $token() . '<input type="hidden" name="skill_id" value="' . $id . '">'
            . '<select name="role" class="' . $c['select'] . '" aria-label="' . $e($t('Offer to a role')) . '">';
        foreach ($roles as $level => $label) {
            $actions .= '<option value="' . (int) $level . '">' . $e($t($label)) . '</option>';
        }
        $actions .= '</select><button class="' . $c['btnGhost'] . '">' . $e($t('Offer to a role')) . '</button></form>';
    }
    $actions .= $post('skilldelete', $id, $t('Delete'), $c['danger']);

    return '<div class="' . $c['card'] . '" data-pf-skill="' . $id . '" data-pf-skill-status="' . $e($skill['status']) . '">'
        . '<div style="' . $split . '"><div style="min-width:0;flex:1 1 16rem">'
        . '<div class="' . $c['row'] . '"><strong>' . $e($skill['name']) . '</strong> <span class="' . $c['badge'] . '">' . $e($t($states[$skill['status']] ?? $skill['status'])) . '</span>'
        . ($skill['role'] !== null ? ' <span class="' . $c['muted'] . '">' . $e($t('For %s and up', $roleOf($skill['role']))) . '</span>' : '') . '</div>'
        . '<div class="' . $c['muted'] . '">' . $e($skill['description']) . '</div>'
        . ($skill['tools'] !== [] ? '<div class="' . $c['muted'] . '">' . $e($t('Tools: %s', implode(', ', $skill['tools']))) . '</div>' : '')
        . '</div><div class="' . $c['row'] . '" style="justify-content:flex-end">' . $actions . '</div></div>'
        . '<details style="margin-top:8px"><summary class="' . $c['muted'] . '">' . $e($t('The method')) . '</summary><pre style="white-space:pre-wrap;margin:8px 0 0">' . $e($skill['body']) . '</pre></details>'
        . '</div>';
};
$chosen = array_flip($open['tools'] ?? []); @endphp
<div class="{!! $c['page'] !!}" data-pf-skills>
    <div style="{!! $split !!};align-items:center">
        <h1 class="{!! $c['h1'] !!}" style="margin:0">{{ $t('My skills') }}</h1>
        <a href="{{ $u['chat'] }}" class="{!! $c['btnGhost'] !!}">&larr; {{ $t('Back to the conversations') }}</a>
    </div>
@if (empty($this->enabled))
    <p class="{!! $c['muted'] !!}">{{ $t('Skills are not turned on here.') }}</p>
@else
    <p class="{!! $c['muted'] !!}">{{ $t('A skill is a way of working the assistant is handed when a question needs it. It opens nothing you may not already use.') }}</p>

    @php // Open already (editing, or nothing yet): a heading. Folded: a button of the theme, its own width.; @endphp
    @php $startsOpen = $open !== null || empty($this->mine); @endphp
    <details class="{!! $c['card'] !!}"{!! $startsOpen ? ' open' : '' !!} data-pf-skill-editor>
        <summary class="{!! $startsOpen ? $c['h2'] : $c['btn'] !!}" style="display:inline-flex;width:auto;max-width:max-content;cursor:pointer;list-style:none">{{ $t($open !== null ? 'Edit a skill' : 'A new skill') }}</summary>
        <form method="post" action="{{ $act('skillsave') }}" data-pf-skill-form style="display:flex;flex-direction:column;gap:16px;margin-top:16px">
            {!! $token() !!}
            @if ($open !== null)<input type="hidden" name="skill_id" value="{!! (int) $open['id'] !!}">@endif
            <div style="{!! $grid !!}">
                <label style="{!! $field !!}"><span>{{ $t('Name') }}</span>
                    <input name="name" required maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]*" class="{!! $c['input'] !!}" style="{!! $full !!}" value="{{ $open['name'] ?? '' }}" placeholder="weekly-sales">
                    <small class="{!! $c['muted'] !!}">{{ $t('Lowercase Latin letters, digits and dashes.') }}</small></label>
                <label style="{!! $field !!}"><span>{{ $t('What it is for') }}</span>
                    <input name="description" required maxlength="300" class="{!! $c['input'] !!}" style="{!! $full !!}" value="{{ $open['description'] ?? '' }}" placeholder="{{ $t('The weekly sales figures, by market') }}">
                    <small class="{!! $c['muted'] !!}">{{ $t('One line: the skill is chosen by it.') }}</small></label>
            </div>
            <fieldset style="{!! $field !!};border:0;padding:0;margin:0">
                <legend style="margin-bottom:6px">{{ $t('Tools it uses') }}</legend>
                @if ($tools === [])
                    <input name="tools" class="{!! $c['input'] !!}" style="{!! $full !!}" value="{{ implode(', ', $open['tools'] ?? []) }}">
                    <small class="{!! $c['muted'] !!}">{{ $t('Their names, separated by commas.') }}</small>
                @else
                    <input type="search" class="{!! $c['input'] !!}" style="{!! $full !!}" placeholder="{{ $t('Find a tool') }}" aria-label="{{ $t('Find a tool') }}" data-pf-skill-tool-filter>
                    <div style="max-height:16rem;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:4px 12px;padding:4px 0" data-pf-skill-tools>
                        @foreach ($tools as $name => $does)
                        <label title="{{ $does }}" style="display:flex;gap:6px;align-items:flex-start;min-width:0;margin:0;font-weight:normal" data-pf-skill-tool="{{ mb_strtolower($name . ' ' . $does) }}">
                            <input type="checkbox" name="tools[]" value="{{ $name }}"{!! isset($chosen[$name]) ? ' checked' : '' !!}>
                            <span style="min-width:0;overflow-wrap:anywhere"><code style="white-space:normal">{!! str_replace('_', '_<wbr>', $e($name)) !!}</code></span></label>
                        @endforeach
                        @foreach (array_diff(array_keys($chosen), array_keys($tools)) as $name)
                        <input type="hidden" name="tools[]" value="{{ $name }}">
                        @endforeach
                    </div>
                    <small class="{!! $c['muted'] !!}">{{ $t('Only the tools you may use: a skill opens nothing more.') }}</small>
                    <script nonce="{{ defined('PAGE_NONCE') ? PAGE_NONCE : '' }}">
                    (function () {
                        var find = document.querySelector('[data-pf-skill-tool-filter]');
                        if (!find) { return; }
                        find.addEventListener('input', function () {
                            var q = find.value.trim().toLowerCase();
                            document.querySelectorAll('[data-pf-skill-tool]').forEach(function (row) {
                                row.hidden = q !== '' && row.getAttribute('data-pf-skill-tool').indexOf(q) === -1;
                            });
                        });
                    })();
                    </script>
                @endif
            </fieldset>
            <label style="{!! $field !!}"><span>{{ $t('The method') }}</span>
                <textarea name="body" required rows="14" class="{!! $c['textarea'] !!}" style="{!! $full !!};font-family:ui-monospace,monospace" placeholder="{{ $t("1. Start from last week's figures.\n2. Compare them with the same week last year.\n3. Say what changed most, and why it may have.") }}">{{ $open['body'] ?? '' }}</textarea>
                <small class="{!! $c['muted'] !!}">{{ $t('Markdown. Step by step, as you would want to be told it.') }}</small></label>
            @if (!empty($this->manage) && ($open === null || $open['level'] === 'role'))
            <label style="{!! $field !!}"><span>{{ $t('For') }}</span>
                <select name="for" class="{!! $c['select'] !!}">
                    <option value="user">{{ $t('Me') }}</option>
                    @foreach ($roles as $level => $label)
                    <option value="role:{!! (int) $level !!}"{!! ($open['role'] ?? null) === (string) $level ? ' selected' : '' !!}>{{ $t('%s and up', $t($label)) }}</option>
                    @endforeach
                </select></label>
            @endif
            <div class="{!! $c['row'] !!}">
                <button class="{!! $c['btn'] !!}">{{ $t('Save') }}</button>
                @if ($open !== null)<a class="{!! $c['btnGhost'] !!}" href="{{ $u['page'] }}">{{ $t('Cancel') }}</a>@endif
            </div>
        </form>
    </details>

    <h2 class="{!! $c['h2'] !!}">{{ $t('Your own skills') }}</h2>
    @if (empty($this->mine))<p class="{!! $c['muted'] !!}">{{ $t('None yet. Write one above, or ask in a conversation for a way of working to be kept as a skill.') }}</p>@endif
    @php foreach ((array) ($this->mine ?? []) as $skill) { echo $card($skill, true, false); } @endphp

    @if (!empty($this->offered))
    <h2 class="{!! $c['h2'] !!}">{{ $t('Offered to a role') }}</h2>
    @php foreach ((array) $this->offered as $skill) { echo $card($skill, false, false); } @endphp
    @endif

    @if (!empty($this->manage))
    <h2 class="{!! $c['h2'] !!}">{{ $t('Roles\' skills') }}</h2>
    @if (empty($this->roles))<p class="{!! $c['muted'] !!}">{{ $t('No role skills yet.') }}</p>@endif
    @php foreach ((array) ($this->roles ?? []) as $skill) { echo $card($skill, false, $skill['status'] === 'pending'); } @endphp
    @endif
@endif
</div>
