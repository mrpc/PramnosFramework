@php /**
 * "Administration screens" — which screens a user or a role is granted.
 *
 * Inserted by the user's and the role's page. Data: `$this->adminScreens` from
 * `AdminScreenGrants::adminScreensFor()` — permissionsMode, canEdit, subject, action, rows of
 * [ability, label, granted, effective (a user's page only), grantable].
 */
$_as = $this->adminScreens ?? null;
if (!is_array($_as) || empty($_as['rows'])) {
    return;
}
$_e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$_forUser = ($_as['subject'] ?? '') === 'user'; @endphp
<div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px" id="admin-screens">
    <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Administration screens</div>
    <div class="card-body" style="padding:16px">
        @if (empty($_as['permissionsMode']))
            <div role="status" style="padding:10px;background:#eef6fc;border:1px solid #bcdcf2;margin-bottom:12px">
                Screens are opened by usertype on this installation (<code>admin_access = usertype</code>), so
                these decisions take effect only once it is set to <code>mixed</code> or <code>permissions</code>.
            </div>
        @endif
        <p style="color:#777;font-size:12px">
            @if (($_as['mode'] ?? '') === 'mixed')
                <strong>Default</strong> follows the screen's usertype floor; <strong>Allow</strong> opens it below the floor, <strong>Deny</strong> closes it above. A deny from the user or any of their roles wins.
            @elseif (($_as['mode'] ?? '') === 'permissions')
                <strong>Default</strong> is closed; <strong>Allow</strong> opens the screen, <strong>Deny</strong> keeps it closed even when a role allows it.
            @endif
        </p>
        <form method="post" action="{!! $_e($_as['action']) !!}">
            {!! \Pramnos\Http\Middleware\CsrfMiddleware::tokenField() !!}
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5;text-align:left">
                    <tr>
                        <th>Screen</th>
                        <th>{!! $_forUser ? 'Decided for this user' : 'Decided for this role' !!}</th>
                        @if ($_forUser)<th>Can open</th>@endif
                    </tr>
                </thead>
                <tbody>
                @foreach ($_as['rows'] as $_row)
                    <tr>
                        <td>{!! $_e($_row['label']) !!} <code style="color:#777">{!! $_e($_row['ability']) !!}</code></td>
                        <td style="white-space:nowrap">
                            @foreach (['default' => 'Default', 'allow' => 'Allow', 'deny' => 'Deny'] as $_value => $_text)
                                <label style="margin-right:10px;white-space:nowrap">
                                    <input type="radio" name="abilities[{!! $_e($_row['ability']) !!}]"
                                           value="{!! $_value !!}"
                                           {!! ($_row['decision'] ?? 'default') === $_value ? 'checked' : '' !!}
                                           {!! (empty($_as['canEdit']) || empty($_row['grantable'])) ? 'disabled' : '' !!}>
                                    {!! $_text !!}
                                </label>
                            @endforeach
                        </td>
                        @if ($_forUser)
                            <td>{!! !empty($_row['effective']) ? '<strong style="color:#2e7d32">yes</strong>' : '<span style="color:#777">no</span>' !!}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($_forUser)
                <p style="color:#777;font-size:12px">"Can open" also counts the user's roles and the superuser usertype.</p>
            @endif
            @if (!empty($_as['canEdit']))
                <button type="submit" class="btn">Save</button>
            @endif
        </form>
    </div>
</div>
