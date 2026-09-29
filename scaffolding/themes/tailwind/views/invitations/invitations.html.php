<?php
/**
 * Invitations (Tailwind theme).
 *
 * Variables:
 *   $this->invitations   — rows from Invitations::all(), each with `state`
 *   $this->organizations — organization_id => name
 *   $this->roles         — roleid => name; empty when the operator may not give roles
 *   $this->freshLink     — [email, link, sent] for the invitation just made, once; or null
 *   $this->ttlHours      — how long a new link lasts
 */
$_e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$_when = static fn ($t): string => $t ? date('Y-m-d H:i', (int) $t) : '—';
$_orgs = $this->organizations ?? [];
$_badge = [
    'waiting'  => 'badge-info',
    'accepted' => 'badge-success',
    'revoked'  => 'badge-ghost',
    'expired'  => 'badge-warning',
];
?>
<div class="max-w-5xl mx-auto py-6 px-4">
    <h2 class="mb-6">Invitations</h2>

    <?php if (!empty($this->freshLink['link'])): ?>
        <div role="status" class="alert alert-success mb-4 flex-col items-start">
            <div class="font-semibold">The link<?php echo $this->freshLink['email'] !== '' ? ' for ' . $_e($this->freshLink['email']) : ''; ?> — shown this once:</div>
            <code class="break-all select-all text-sm"><?php echo $_e($this->freshLink['link']); ?></code>
        </div>
    <?php endif; ?>

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Invite somebody</div>
        <div class="p-5">
            <form method="post" action="<?php echo adminUrl('Invitations/invite'); ?>" class="grid gap-4 md:grid-cols-2">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <div>
                    <label for="inv-email" class="block text-sm font-medium mb-1">Email address</label>
                    <input type="email" name="email" id="inv-email" class="input input-sm w-full" required autocomplete="off">
                </div>
                <div>
                    <label for="inv-org" class="block text-sm font-medium mb-1">Organisation</label>
                    <select name="organization_id" id="inv-org" class="select select-sm w-full">
                        <option value="0">None</option>
                        <?php foreach ($_orgs as $_id => $_name): ?>
                            <option value="<?php echo (int) $_id; ?>"><?php echo $_e($_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($this->roles)): ?>
                <div>
                    <label for="inv-role" class="block text-sm font-medium mb-1">Role</label>
                    <select name="role_id" id="inv-role" class="select select-sm w-full">
                        <option value="0">None</option>
                        <?php foreach ($this->roles as $_id => $_name): ?>
                            <option value="<?php echo (int) $_id; ?>"><?php echo $_e($_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label for="inv-note" class="block text-sm font-medium mb-1">Note <span class="text-base-content/60">(in the mail)</span></label>
                    <input type="text" name="note" id="inv-note" class="input input-sm w-full" maxlength="255">
                </div>
                <div class="md:col-span-2 flex items-center gap-3">
                    <button type="submit" class="btn btn-primary btn-sm">Send invitation</button>
                    <span class="text-xs text-base-content/60">The link works once, for this address only, for <?php echo (int) ($this->ttlHours ?? 48); ?> hours.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Sent</div>
        <div class="p-5 overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Email</th><th>Organisation</th><th>State</th><th>Sent</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->invitations ?? []) as $_row): ?>
                    <tr>
                        <td><?php echo $_e($_row['email']); ?><?php if (!empty($_row['note'])): ?><div class="text-xs text-base-content/60"><?php echo $_e($_row['note']); ?></div><?php endif; ?></td>
                        <td><?php echo $_e($_orgs[(int) ($_row['organization_id'] ?? 0)] ?? '—'); ?></td>
                        <td><span class="badge badge-sm <?php echo $_badge[$_row['state']] ?? ''; ?>"><?php echo $_e($_row['state']); ?></span></td>
                        <td><?php echo $_when($_row['created_at'] ?? 0); ?></td>
                        <td><?php echo $_when($_row['expires_at'] ?? 0); ?></td>
                        <td class="whitespace-nowrap">
                            <?php if (in_array($_row['state'], ['waiting', 'expired'], true)): ?>
                                <form method="post" action="<?php echo adminUrl('Invitations/resend'); ?>" class="inline">
                                    <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                                    <input type="hidden" name="invitation_id" value="<?php echo (int) $_row['invitation_id']; ?>">
                                    <button type="submit" class="btn btn-ghost btn-xs">Resend</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($_row['state'] === 'waiting'): ?>
                                <form method="post" action="<?php echo adminUrl('Invitations/revoke'); ?>" class="inline">
                                    <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                                    <input type="hidden" name="invitation_id" value="<?php echo (int) $_row['invitation_id']; ?>">
                                    <button type="submit" class="btn btn-ghost btn-xs text-error">Withdraw</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->invitations)): ?>
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">Nobody has been invited yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
