<?php
/**
 * Generic login-flow message page (Tailwind theme).
 *
 * Variables:
 *   $this->title   — Optional page title override
 *   $this->message — Optional plain-text message body
 */
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm">
        <h1 class="text-2xl font-semibold mb-4"><?php echo htmlspecialchars($this->title ?? 'Notice'); ?></h1>

        <?php if ($this->hasErrors()): ?>
            <div role="alert" class="alert alert-error mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>
        <?php if ($this->hasMessages()): ?>
            <div role="status" class="alert alert-info mb-4"><?php echo $this->_printMessages(); ?></div>
        <?php endif; ?>

        <?php if (!empty($this->message)): ?>
            <p class="text-base-content"><?php echo htmlspecialchars($this->message); ?></p>
        <?php else: ?>
            <p class="text-base-content/70">Your request has been submitted. If an account exists, you will receive instructions by email.</p>
        <?php endif; ?>

        <div class="text-center mt-6">
            <a href="<?php echo sURL; ?>login" class="btn btn-primary">Back to Login</a>
        </div>
    </div>
</div>
