<?php
/**
 * "Is it you trying to sign in?" — the page a trusted phone answers on (Bootstrap theme).
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::approve):
 *   $this->routeBase, $this->brand, $this->token,
 *   $this->approval  the ask as PushApprovals::forAnswering() gives it, or null when this
 *                    browser may not answer it (not signed in as the account, not trusted)
 *   $this->result    after a POST: approved | denied | expired | invalid
 *   $this->error     invalid_token when the form's session token failed
 *
 * Every ask shows three numbers and no Yes: the right one is on the screen of the browser
 * signing in, and picking it is the approval. A familiar-looking attempt gets no shortcut —
 * what makes an attempt look familiar is what the person holding the password chooses.
 */
$brand    = $this->brand ?? [];
$primary  = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base     = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));
$approval = $this->approval ?? null;
$result   = (string) ($this->result ?? '');
$token    = htmlspecialchars((string) ($this->token ?? ''), ENT_QUOTES);
$country  = $approval !== null ? (string) ($approval['country'] ?? '') : '';
$where    = $country !== '' ? $country : 'an unknown place';
$btnStyle = 'background-color:' . $primary . ';border-color:' . $primary;
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <?php if ($approval === null): ?>
                        <h1 class="h4 mb-2">This cannot be answered here</h1>
                        <p class="text-muted small">
                            Sign-ins are approved from a device you trust, signed in to your account. Open the
                            notification on that device, or sign in there first.
                        </p>
                    <?php elseif ($result === 'approved' || ($result === '' && ($approval['decision'] ?? null) === 'approved')): ?>
                        <h1 class="h4 mb-2">You're signing in</h1>
                        <p class="text-muted small">The other device will finish signing in now. You can close this.</p>
                    <?php elseif ($result === 'denied' || ($result === '' && ($approval['decision'] ?? null) === 'denied')): ?>
                        <h1 class="h4 mb-2">Sign-in refused</h1>
                        <p class="text-muted small mb-3">
                            Nobody got in. But whoever tried used your password, so somebody else knows it.
                        </p>
                        <a href="<?php echo $base; ?>/changepassword" class="btn btn-primary w-100" style="<?php echo $btnStyle; ?>">Change your password</a>
                    <?php elseif (empty($approval['open'])): ?>
                        <h1 class="h4 mb-2">This request has expired</h1>
                        <p class="text-muted small">If you are signing in, send a new notification from the other device.</p>
                    <?php else: ?>
                        <h1 class="h4 mb-1">Is it you trying to sign in?</h1>
                        <dl class="row small my-3">
                            <dt class="col-4 text-muted fw-normal">Device</dt><dd class="col-8 text-end"><?php echo htmlspecialchars((string) $approval['browser']); ?></dd>
                            <dt class="col-4 text-muted fw-normal">Where</dt><dd class="col-8 text-end"><?php echo htmlspecialchars($where); ?><?php if (!empty($approval['ip'])): ?> · <?php echo htmlspecialchars((string) $approval['ip']); ?><?php endif; ?></dd>
                            <dt class="col-4 text-muted fw-normal">When</dt><dd class="col-8 text-end mb-0"><?php echo date('H:i', (int) $approval['created_at']); ?></dd>
                        </dl>

                        <?php if (($this->error ?? '') === 'invalid_token'): ?>
                            <div role="alert" class="alert alert-danger">This page expired. Open the notification again.</div>
                        <?php endif; ?>

                        <p class="small mb-3">Pick the number shown on the screen you are signing in on.</p>
                        <form method="POST" action="<?php echo $base; ?>/approve" class="row g-3 mb-3">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="token" value="<?php echo $token; ?>">
                            <input type="hidden" name="decision" value="approved">
                            <?php foreach ($approval['choices'] as $choice): ?>
                                <div class="col-4">
                                    <button type="submit" name="number" value="<?php echo (int) $choice; ?>"
                                            class="btn btn-outline-secondary w-100 py-3 fs-3 fw-bold"><?php echo (int) $choice; ?></button>
                                </div>
                            <?php endforeach; ?>
                        </form>

                        <form method="POST" action="<?php echo $base; ?>/approve">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="token" value="<?php echo $token; ?>">
                            <input type="hidden" name="decision" value="denied">
                            <button type="submit" class="btn btn-link text-danger w-100">No, it's not me</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
