<?php
/**
 * Invitations (Bootstrap theme).
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
    'waiting'  => 'bg-info',
    'accepted' => 'bg-success',
    'revoked'  => 'bg-secondary',
    'expired'  => 'bg-warning text-dark',
];
?>
<div class="container py-4">
    <h2 class="mb-4">Invitations</h2>

    <?php if (!empty($this->freshLink['link'])): ?>
        <div role="status" class="alert alert-success mb-3">
            <div class="font-semibold">The link<?php echo $this->freshLink['email'] !== '' ? ' for ' . $_e($this->freshLink['email']) : ''; ?> — shown this once:</div>
            <code class="d-block text-break small"><?php echo $_e($this->freshLink['link']); ?></code>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Invite somebody</div>
        <div class="card-body">
            <form method="post" action="<?php echo adminUrl('Invitations/invite'); ?>" class="row g-3">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <div class="col-md-6">
                    <label for="inv-email" class="form-label">Email address</label>
                    <input type="email" name="email" id="inv-email" class="form-control form-control-sm" required autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label for="inv-org" class="form-label">Organisation</label>
                    <select name="organization_id" id="inv-org" class="form-select form-select-sm">
                        <option value="0">None</option>
                        <?php foreach ($_orgs as $_id => $_name): ?>
                            <option value="<?php echo (int) $_id; ?>"><?php echo $_e($_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($this->roles)): ?>
                <div class="col-md-6">
                    <label for="inv-role" class="form-label">Role</label>
                    <select name="role_id" id="inv-role" class="form-select form-select-sm">
                        <option value="0">None</option>
                        <?php foreach ($this->roles as $_id => $_name): ?>
                            <option value="<?php echo (int) $_id; ?>"><?php echo $_e($_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label for="inv-note" class="form-label">Note <span class="text-muted">(in the mail)</span></label>
                    <input type="text" name="note" id="inv-note" class="form-control form-control-sm" maxlength="255">
                </div>
                <div class="col-12 d-flex align-items-center gap-3">
                    <button type="submit" class="btn btn-primary btn-sm">Send invitation</button>
                    <span class="small text-muted">The link works once, for this address only, for <?php echo (int) ($this->ttlHours ?? 48); ?> hours.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">Sent</div>
        <div class="card-body table-responsive">
            <table class="table table-sm">
                <thead class="table-light">
                    <tr><th>Email</th><th>Organisation</th><th>State</th><th>Sent</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->invitations ?? []) as $_row): ?>
                    <tr>
                        <td><?php echo $_e($_row['email']); ?><?php if (!empty($_row['note'])): ?><div class="small text-muted"><?php echo $_e($_row['note']); ?></div><?php endif; ?></td>
                        <td><?php echo $_e($_orgs[(int) ($_row['organization_id'] ?? 0)] ?? '—'); ?></td>
                        <td><span class="badge <?php echo $_badge[$_row['state']] ?? ''; ?>"><?php echo $_e($_row['state']); ?></span></td>
                        <td><?php echo $_when($_row['created_at'] ?? 0); ?></td>
                        <td><?php echo $_when($_row['expires_at'] ?? 0); ?></td>
                        <td class="text-nowrap">
                            <?php if (in_array($_row['state'], ['waiting', 'expired'], true)): ?>
                                <form method="post" action="<?php echo adminUrl('Invitations/resend'); ?>" class="d-inline">
                                    <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                                    <input type="hidden" name="invitation_id" value="<?php echo (int) $_row['invitation_id']; ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0">Resend</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($_row['state'] === 'waiting'): ?>
                                <form method="post" action="<?php echo adminUrl('Invitations/revoke'); ?>" class="d-inline">
                                    <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                                    <input type="hidden" name="invitation_id" value="<?php echo (int) $_row['invitation_id']; ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0 ms-2">Withdraw</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->invitations)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nobody has been invited yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
