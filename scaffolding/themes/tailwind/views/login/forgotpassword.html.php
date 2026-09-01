<?php
/**
 * Forgot-password form (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderForgot):
 *   $this->routeBase, $this->brand, $this->error, $this->message
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'human_check' => 'The security check did not complete. Reload the page and try again — it needs a modern browser with JavaScript enabled.',
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
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm" style="--color-primary:<?php echo $primary; ?>">
        <h1 class="text-2xl font-semibold mb-1">Forgot your password?</h1>
        <p class="text-sm text-base-content/70 mb-6">Enter your email and we'll send you a reset link.</p>

        <?php if ($sent): ?>
            <div role="status" class="alert alert-info mb-4">If an account exists for that email, a password-reset link is on its way. Check your inbox.</div>
        <?php else: ?>
            <?php if ($errorText !== ''): ?>
                <div role="alert" id="form-error" class="alert alert-error mb-4"><?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>
            <form data-pf-progress method="POST" action="<?php echo $base; ?>/forgotpassword" class="space-y-4">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php /* The human check's fields, when the application asks for one. Renders
                     nothing otherwise, so the insert is unconditional. */ ?>
            <?php echo humanCheckField($this->humanCheck ?? null); ?>
                <div>
                    <label for="email" class="block text-sm font-medium text-base-content mb-1">Email</label>
                    <input type="email" name="email" id="email" class="input w-full" value="<?php echo htmlspecialchars((string) ($this->email ?? '')); ?>" required autofocus autocomplete="email"<?php echo $errorFieldAttributes; ?> enterkeyhint="go">
                </div>
                <button type="submit" class="btn btn-primary w-full">Send reset link</button>
            </form>
        <?php endif; ?>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-primary hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
