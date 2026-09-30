<?php
/**
 * "Is it you trying to sign in?" — the page a trusted phone answers on (plain-CSS theme).
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::approve):
 *   $this->routeBase, $this->brand, $this->token,
 *   $this->approval  the ask as PushApprovals::forAnswering() gives it, or null when this
 *                    browser may not answer it (not signed in as the account, not trusted)
 *   $this->result    after a POST: approved | denied | expired | invalid
 *   $this->error     invalid_token when the form's session token failed
 *
 * An ask that needs the number shows three and no Yes: the right one is on the screen of the
 * browser signing in, and picking it is the approval. A plain Yes is only for an ask that
 * needs none — a browser the account trusted before, with nothing unusual about the attempt.
 */
$brand    = $this->brand ?? [];
$primary  = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base     = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
$approval = $this->approval ?? null;
$result   = (string) ($this->result ?? '');
$token    = htmlspecialchars((string) ($this->token ?? ''), ENT_QUOTES);
$country  = $approval !== null ? (string) ($approval['country'] ?? '') : '';
$where    = $country !== '' ? $country : 'an unknown place';
$btnStyle = 'width:100%;background-color:' . $primary . ';border-color:' . $primary;
$row      = 'display:flex;justify-content:space-between;gap:16px;margin:0 0 4px';
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-body" style="padding:24px">
            <?php if ($approval === null): ?>
                <h2 style="margin:0 0 8px;font-size:1.25rem">This cannot be answered here</h2>
                <p style="font-size:14px;color:#666">
                    Sign-ins are approved from a device you trust, signed in to your account. Open the
                    notification on that device, or sign in there first.
                </p>
            <?php elseif ($result === 'approved' || ($result === '' && ($approval['decision'] ?? null) === 'approved')): ?>
                <h2 style="margin:0 0 8px;font-size:1.25rem">You're signing in</h2>
                <p style="font-size:14px;color:#666">The other device will finish signing in now. You can close this.</p>
            <?php elseif ($result === 'denied' || ($result === '' && ($approval['decision'] ?? null) === 'denied')): ?>
                <h2 style="margin:0 0 8px;font-size:1.25rem">Sign-in refused</h2>
                <p style="font-size:14px;color:#666;margin-bottom:12px">
                    Nobody got in. But whoever tried used your password, so somebody else knows it.
                </p>
                <a href="<?php echo $base; ?>/changepassword" class="btn" style="<?php echo $btnStyle; ?>;display:block;text-align:center;box-sizing:border-box">Change your password</a>
            <?php elseif (empty($approval['open'])): ?>
                <h2 style="margin:0 0 8px;font-size:1.25rem">This request has expired</h2>
                <p style="font-size:14px;color:#666">If you are signing in, send a new notification from the other device.</p>
            <?php else: ?>
                <h2 style="margin:0 0 4px;font-size:1.25rem">Is it you trying to sign in?</h2>
                <dl style="font-size:14px;margin:16px 0">
                    <div style="<?php echo $row; ?>"><dt style="color:#666">Device</dt><dd style="margin:0;text-align:right"><?php echo htmlspecialchars((string) $approval['browser']); ?></dd></div>
                    <div style="<?php echo $row; ?>"><dt style="color:#666">Where</dt><dd style="margin:0;text-align:right"><?php echo htmlspecialchars($where); ?><?php if (!empty($approval['ip'])): ?> · <?php echo htmlspecialchars((string) $approval['ip']); ?><?php endif; ?></dd></div>
                    <div style="<?php echo $row; ?>"><dt style="color:#666">When</dt><dd style="margin:0;text-align:right"><?php echo date('H:i', (int) $approval['created_at']); ?></dd></div>
                </dl>

                <?php if (($this->error ?? '') === 'invalid_token'): ?>
                    <div role="alert" class="alert alert-danger">This page expired. Open the notification again.</div>
                <?php endif; ?>

                <?php if ($approval['choices'] !== []): ?>
                    <p style="font-size:14px;margin-bottom:12px">Pick the number shown on the screen you are signing in on.</p>
                    <form method="POST" action="<?php echo $base; ?>/approve" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="token" value="<?php echo $token; ?>">
                        <input type="hidden" name="decision" value="approved">
                        <?php foreach ($approval['choices'] as $choice): ?>
                            <button type="submit" name="number" value="<?php echo (int) $choice; ?>"
                                    class="btn" style="height:64px;font-size:24px;font-weight:700;background:#fff;color:#111;border:1px solid #ccc"><?php echo (int) $choice; ?></button>
                        <?php endforeach; ?>
                    </form>
                <?php else: ?>
                    <form method="POST" action="<?php echo $base; ?>/approve" style="margin-bottom:8px">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="token" value="<?php echo $token; ?>">
                        <input type="hidden" name="decision" value="approved">
                        <button type="submit" class="btn" style="<?php echo $btnStyle; ?>">Yes, it's me</button>
                    </form>
                <?php endif; ?>

                <form method="POST" action="<?php echo $base; ?>/approve">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <input type="hidden" name="decision" value="denied">
                    <button type="submit" class="btn btn-sm" style="width:100%;background:transparent;color:#b91c1c;border-color:transparent">No, it's not me</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
