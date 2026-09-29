<?php
/**
 * Invitations (plain-CSS theme).
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
<div style="max-width:960px;margin:0 auto;padding:24px 16px">
    <h2 style="margin-bottom:16px">Invitations</h2>

    <?php if (!empty($this->freshLink['link'])): ?>
        <div role="status" style="padding:12px;background:#e8f5e9;border:1px solid #a5d6a7;margin-bottom:16px">
            <div style="font-weight:600">The link<?php echo $this->freshLink['email'] !== '' ? ' for ' . $_e($this->freshLink['email']) : ''; ?> — shown this once:</div>
            <code style="word-break:break-all"><?php echo $_e($this->freshLink['link']); ?></code>
        </div>
    <?php endif; ?>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Invite somebody</div>
        <div style="padding:16px">
            <form method="post" action="<?php echo adminUrl('Invitations/invite'); ?>">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <div style="margin-bottom:12px">
                    <label for="inv-email" style="display:block;margin-bottom:4px">Email address</label>
                    <input type="email" name="email" id="inv-email" style="width:100%" required autocomplete="off">
                </div>
                <div style="margin-bottom:12px">
                    <label for="inv-org" style="display:block;margin-bottom:4px">Organisation</label>
                    <select name="organization_id" id="inv-org" style="width:100%">
                        <option value="0">None</option>
                        <?php foreach ($_orgs as $_id => $_name): ?>
                            <option value="<?php echo (int) $_id; ?>"><?php echo $_e($_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($this->roles)): ?>
                <div style="margin-bottom:12px">
                    <label for="inv-role" style="display:block;margin-bottom:4px">Role</label>
                    <select name="role_id" id="inv-role" style="width:100%">
                        <option value="0">None</option>
                        <?php foreach ($this->roles as $_id => $_name): ?>
                            <option value="<?php echo (int) $_id; ?>"><?php echo $_e($_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div style="margin-bottom:12px">
                    <label for="inv-note" style="display:block;margin-bottom:4px">Note <span style="color:#777">(in the mail)</span></label>
                    <input type="text" name="note" id="inv-note" style="width:100%" maxlength="255">
                </div>
                <div>
                    <button type="submit" class="btn">Send invitation</button>
                    <span style="color:#777;font-size:12px">The link works once, for this address only, for <?php echo (int) ($this->ttlHours ?? 48); ?> hours.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Sent</div>
        <div style="padding:16px;overflow-x:auto">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5;text-align:left">
                    <tr><th>Email</th><th>Organisation</th><th>State</th><th>Sent</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->invitations ?? []) as $_row): ?>
                    <tr>
                        <td><?php echo $_e($_row['email']); ?><?php if (!empty($_row['note'])): ?><div style="color:#777;font-size:12px"><?php echo $_e($_row['note']); ?></div><?php endif; ?></td>
                        <td><?php echo $_e($_orgs[(int) ($_row['organization_id'] ?? 0)] ?? '—'); ?></td>
                        <td><span><?php echo $_e($_row['state']); ?></span></td>
                        <td><?php echo $_when($_row['created_at'] ?? 0); ?></td>
                        <td><?php echo $_when($_row['expires_at'] ?? 0); ?></td>
                        <td style="white-space:nowrap">
                            <?php if (in_array($_row['state'], ['waiting', 'expired'], true)): ?>
                                <form method="post" action="<?php echo adminUrl('Invitations/resend'); ?>" style="display:inline">
                                    <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                                    <input type="hidden" name="invitation_id" value="<?php echo (int) $_row['invitation_id']; ?>">
                                    <button type="submit">Resend</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($_row['state'] === 'waiting'): ?>
                                <form method="post" action="<?php echo adminUrl('Invitations/revoke'); ?>" style="display:inline">
                                    <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                                    <input type="hidden" name="invitation_id" value="<?php echo (int) $_row['invitation_id']; ?>">
                                    <button type="submit" style="color:#c62828">Withdraw</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->invitations)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#777;padding:24px">Nobody has been invited yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
