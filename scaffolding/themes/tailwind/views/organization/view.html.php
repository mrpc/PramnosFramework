<?php
/**
 * One organisation, for the person who manages it (Tailwind theme).
 *
 * Variables:
 *   $this->organization_id, $this->name
 *   $this->members     — userid, username, email, name, roles[roleid, role_name, counts]
 *   $this->roles       — the organisation's roles: roleid, role_name, grantable
 *   $this->invitations — invitation_id, email, role_id, state, created_at, expires_at
 */
$orgId       = (int) ($this->organization_id ?? 0);
$members     = is_array($this->members ?? null) ? $this->members : [];
$roles       = is_array($this->roles ?? null) ? $this->roles : [];
$invitations = is_array($this->invitations ?? null) ? $this->invitations : [];
$givable     = array_values(array_filter($roles, static fn (array $r): bool => !empty($r['grantable'])));
$e           = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$post        = static fn (string $action): string => sURL . 'organization/' . $action . '/' . $orgId;
$roleSelect  = static function (string $name, string $blank) use ($givable, $e): string {
    $html = '<select name="' . $name . '" class="input input-sm"><option value="">' . $blank . '</option>';
    foreach ($givable as $r) {
        $html .= '<option value="' . (int) $r['roleid'] . '">' . $e($r['role_name']) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="px-4 py-6">
    <p><a href="<?php echo sURL; ?>organization">Your organisations</a></p>
    <h2 class="text-lg font-semibold mb-4"><?php echo $e($this->name ?? ''); ?></h2>

    <div class="card bg-base-100 border border-base-300 p-4 mb-4">
        <h3 class="font-medium mt-6 mb-2">Add somebody</h3>
        <form method="post" action="<?php echo $post('add'); ?>" class="flex flex-wrap gap-2 items-center">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="email" name="email" required placeholder="Email address" class="input input-sm" style="max-width:280px">
            <?php echo $roleSelect('roleid', 'No role'); ?>
            <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </form>
        <p class="text-sm opacity-70">An address with an account becomes a member at once; one without is sent an invitation into the organisation.</p>
    </div>

    <h3 class="font-medium mt-6 mb-2">Members</h3>
    <table class="table w-full">
        <thead><tr><th>Member</th><th>Roles</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($members as $m): $uid = (int) $m['userid']; ?>
            <tr>
                <td><?php echo $e($m['name'] !== '' ? $m['name'] : $m['username']); ?> <span class="text-sm opacity-70"><?php echo $e($m['email']); ?></span></td>
                <td>
                    <?php foreach ($m['roles'] as $r): ?>
                        <span class="badge badge-ghost mr-1"><?php echo $e($r['role_name']); ?><?php echo empty($r['counts']) ? ' (does not count)' : ''; ?></span>
                        <?php foreach ($givable as $g): if ((int) $g['roleid'] === (int) $r['roleid']): ?>
                            <form method="post" action="<?php echo $post('takerole'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><input type="hidden" name="userid" value="<?php echo $uid; ?>"><input type="hidden" name="roleid" value="<?php echo (int) $r['roleid']; ?>"><button type="submit" class="btn btn-outline btn-xs" title="Take this role away" aria-label="Take <?php echo $e($r['role_name']); ?> away">×</button></form>
                        <?php endif; endforeach; ?>
                    <?php endforeach; ?>
                    <?php if ($givable !== []): ?>
                        <form method="post" action="<?php echo $post('giverole'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><input type="hidden" name="userid" value="<?php echo $uid; ?>"><?php echo $roleSelect('roleid', 'Give a role…'); ?><button type="submit" class="btn btn-outline btn-xs">Give</button></form>
                    <?php endif; ?>
                </td>
                <td><form method="post" action="<?php echo $post('remove'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><input type="hidden" name="userid" value="<?php echo $uid; ?>"><button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="Remove <?php echo $e($m['email']); ?> from the organisation?">Remove</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($members === []): ?>
            <tr><td colspan="3" class="text-sm opacity-70">No members yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($invitations !== []): ?>
    <h3 class="font-medium mt-6 mb-2">Invitations</h3>
    <table class="table w-full">
        <thead><tr><th>Address</th><th>State</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($invitations as $i): ?>
            <tr>
                <td><?php echo $e($i['email']); ?></td>
                <td><?php echo $e($i['state']); ?></td>
                <td><?php if (($i['state'] ?? '') === 'waiting'): ?><form method="post" action="<?php echo $post('withdraw'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><input type="hidden" name="invitation" value="<?php echo (int) $i['invitation_id']; ?>"><button type="submit" class="btn btn-outline btn-xs">Withdraw</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (count($givable) < count($roles)): ?>
        <p class="text-sm opacity-70">Some of the organisation's roles allow things you are not allowed yourself, so you cannot give them.</p>
    <?php endif; ?>
</div>
