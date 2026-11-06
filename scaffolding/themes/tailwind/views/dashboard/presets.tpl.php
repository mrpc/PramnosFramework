@php /**
 * Every saved dashboard preset (tailwind theme), for an administrator: which board, who saved it,
 * who sees it, whose default it is — and the forms to change the default or delete it.
 *
 * Variables: $this->presets (rows of pramnos.dashboard_presets), $this->owners (userid => username),
 * $this->roles (usertype => label), $this->csrfField, $this->urls ['delete', 'default'].
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$shown = ['private' => $t('Only me'), 'role' => $t('From a user type up'), 'organization' => $t('An organisation'), 'everyone' => $t('Everyone')];
$field = (string) ($this->csrfField ?? ''); @endphp
<div class="px-4 py-6">
    <h2>{{ $t('Dashboard presets') }}</h2>
    @if (empty($this->presets))
    <p class="opacity-70">{{ $t('No presets yet.') }}</p>
    @else
    <table class="table table-sm">
        <thead><tr>
            <th>{{ $t('Board') }}</th><th>{{ $t('Name') }}</th><th>{{ $t('Saved by') }}</th>
            <th>{{ $t('Who sees it') }}</th><th>{{ $t('Default for a user type') }}</th><th></th>
        </tr></thead>
        <tbody>
        @foreach ($this->presets as $row)
        <tr>
            <td>{{ $row['board'] }}</td>
            <td>{{ $row['name'] }}</td>
            <td>{{ $this->owners[(int) $row['ownerid']] ?? ('#' . (int) $row['ownerid']) }}</td>
            <td>{{ $shown[$row['visibility']] ?? $row['visibility'] }}{!! ($row['audience'] ?? null) !== null ? ' (' . $e($row['audience']) . ')' : '' !!}</td>
            <td>
                <form method="post" action="{{ $this->urls['default'] . (str_contains($this->urls['default'], '?') ? '&' : '?') . 'board=' . rawurlencode((string) $row['board']) }}" style="display:inline-flex;gap:.25rem">{!! $field !!}
                    <input type="hidden" name="preset" value="{!! (int) $row['presetid'] !!}"><input type="hidden" name="back" value="list">
                    <select name="role" class="select select-sm select-bordered" style="width:auto" aria-label="{{ $t('Default for a user type') }}">
                        <option value="">{{ $t('No user type') }}</option>
                        @foreach ((array) $this->roles as $level => $label)
                        <option value="{!! (int) $level !!}"{!! (string) ($row['default_for_role'] ?? '') === (string) $level ? ' selected' : '' !!}>{{ $t((string) $label) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-ghost">{{ $t('Set as default') }}</button>
                </form>
            </td>
            <td>
                <form method="post" action="{{ $this->urls['delete'] }}" style="display:inline">{!! $field !!}
                    <input type="hidden" name="preset" value="{!! (int) $row['presetid'] !!}"><input type="hidden" name="back" value="list">
                    <button type="submit" class="btn btn-sm btn-error btn-outline" data-confirm="{{ $t('Delete this preset?') }}">{{ $t('Delete') }}</button>
                </form>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
