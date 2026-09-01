<?php
/**
 * Forgot-password form (Bootstrap theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderForgot):
 *   $this->routeBase, $this->brand, $this->error, $this->message
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
/*
 * The error box's id, and the attributes that point the first field at it.
 *
 * `role="alert"` on its own is unreliable for an error that is already in the document when the
 * page loads: a screen reader announces a live region when it *changes*, and this one never
 * changed. What works with no JavaScript at all is the description — the field is marked invalid
 * and described by the box, so the message is read out as part of the field the moment focus
 * lands on it, and focus lands there on load because the first field carries `autofocus`.
 *
 * The *first* field only. These errors are form-level — «wrong username or password» is about the
 * pair — and marking four fields invalid to report one failure tells a screen reader four things
 * that are not true.
 */
$errorFieldAttributes = $errorText !== ''
    ? ' aria-invalid="true" aria-describedby="form-error"'
    : '';

$sent      = (($this->message ?? '') === 'sent');
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Forgot your password?</h1>
                    <p class="text-muted small mb-3">Enter your email and we'll send you a reset link.</p>

                    <?php if ($sent): ?>
                        <div role="status" class="alert alert-info">If an account exists for that email, a password-reset link is on its way. Check your inbox.</div>
                    <?php else: ?>
                        <?php if ($errorText !== ''): ?>
                            <div role="alert" id="form-error" class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                        <?php endif; ?>
                        <form data-pf-progress method="POST" action="<?php echo $base; ?>/forgotpassword">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars((string) ($this->email ?? '')); ?>" required autofocus autocomplete="email"<?php echo $errorFieldAttributes; ?> enterkeyhint="go">
                            </div>
                            <button type="submit" class="btn btn-primary w-100" style="background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Send reset link</button>
                        </form>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="<?php echo $base; ?>/login" class="small">&larr; Back to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
