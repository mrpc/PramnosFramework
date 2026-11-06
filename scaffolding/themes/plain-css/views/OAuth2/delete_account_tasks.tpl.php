@php /**
 * The scheduled AI tasks a leaving person has, organisation by organisation, and who takes them
 * over (plain CSS theme) — inside the delete-account form, so the choice is sent with it.
 *
 * Variables: `$this->taskHandover` — from Routines::handoverChoices(): organization_id, name,
 * shared and personal (tasks: routine_id, title), successors (userid => name). Nothing is drawn
 * without any.
 *
 * Shared tasks — reports an organisation receives — are proposed for a successor; one's own go
 * with them only if ticked; with nobody to take them, they are deleted with the account.
 */
$c = ['box' => 'card card-body small', 'h' => '', 'muted' => 'pf-muted', 'select' => 'form-control', 'check' => ''];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$handover = is_array($this->taskHandover ?? null) ? $this->taskHandover : []; @endphp
@php foreach ($handover as $one):
    $key = $one['organization_id'] === null ? 'none' : (string) $one['organization_id']; @endphp
<fieldset class="{!! $c['box'] !!}" data-pf-task-handover="{{ $key }}">
    <legend class="{!! $c['h'] !!}">{{ $one['organization_id'] === null ? $t('Your scheduled tasks') : sprintf($t('Your scheduled tasks in %s'), (string) ($one['name'] ?? '')) }}</legend>
    <p class="{!! $c['muted'] !!}">{{ implode(', ', array_map(static fn (array $task): string => (string) $task['title'], [...$one['shared'], ...$one['personal']])) }}</p>
    @if ($one['successors'] === [])
    <p>{{ $t('Nobody else can take them over: they are deleted with your account.') }}</p>
    @else
    <label>{{ $t('Hand them to') }}
        <select class="{!! $c['select'] !!}" name="tasks[{{ $key }}][to]">
            @foreach ($one['successors'] as $userId => $name)<option value="{!! (int) $userId !!}">{{ $name }}</option>@endforeach
            <option value=""{!! $one['shared'] === [] ? ' selected' : '' !!}>{{ $t('Nobody — delete them') }}</option>
        </select>
    </label>
    @if ($one['personal'] !== [])
    <label><input type="checkbox" class="{!! $c['check'] !!}" name="tasks[{{ $key }}][personal]" value="1"> {{ $t('My own tasks too, not only the ones shared') }}</label>
    @endif
    @endif
</fieldset>
@endforeach
