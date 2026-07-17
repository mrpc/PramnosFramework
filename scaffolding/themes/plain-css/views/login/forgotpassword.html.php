<?php
/**
 * Forgot-password form (plain-CSS theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderForgot):
 *   $this->routeBase — controller route base (form action prefix)
 *   $this->brand     — [name, logo, primary_color, footer]
 *   $this->error     — optional error key
 *   $this->message   — optional message key ('sent')
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'invalid_token' => 'Your session expired. Please try again.',
    'invalid_email' => 'Please enter a valid email address.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
$sent      = (($this->message ?? '') === 'sent');
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header">
            <h2 style="margin:0;font-size:1.25rem">Forgot your password?</h2>
            <p style="margin:4px 0 0;font-size:13px;color:#666">Enter your email and we'll send you a reset link.</p>
        </div>
        <div class="card-body" style="padding:24px">
            <?php if ($sent): ?>
                <div class="alert alert-info">If an account exists for that email, a password-reset link is on its way. Check your inbox.</div>
                <div style="text-align:center;margin-top:12px">
                    <a href="<?php echo $base; ?>/login" style="font-size:13px">&larr; Back to login</a>
                </div>
            <?php else: ?>
                <?php if ($errorText !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                <?php endif; ?>
                <form method="POST" action="<?php echo $base; ?>/forgotpassword">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <div style="margin-bottom:20px">
                        <label for="email" style="display:block;margin-bottom:4px;font-weight:500">Email</label>
                        <input type="email" name="email" id="email" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px" value="<?php echo htmlspecialchars((string) ($this->email ?? '')); ?>" required autofocus autocomplete="email">
                    </div>
                    <button type="submit" class="btn" style="width:100%;background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Send reset link</button>
                </form>
                <div style="text-align:center;margin-top:12px">
                    <a href="<?php echo $base; ?>/login" style="font-size:13px">&larr; Back to login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
