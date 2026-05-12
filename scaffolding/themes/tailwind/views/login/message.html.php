<?php
/**
 * Generic login-flow message page (Tailwind theme).
 *
 * Variables:
 *   $this->title   — Optional page title override
 *   $this->message — Optional plain-text message body
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-4"><?php echo htmlspecialchars($this->title ?? 'Notice'); ?></h1>

        <?php if ($this->hasErrors()): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>
        <?php if ($this->hasMessages()): ?>
            <div class="bg-blue-100 border border-blue-300 text-blue-800 rounded p-3 mb-4"><?php echo $this->_printMessages(); ?></div>
        <?php endif; ?>

        <?php if (!empty($this->message)): ?>
            <p class="text-gray-700"><?php echo htmlspecialchars($this->message); ?></p>
        <?php else: ?>
            <p class="text-gray-500">Your request has been submitted. If an account exists, you will receive instructions by email.</p>
        <?php endif; ?>

        <div class="text-center mt-6">
            <a href="<?php echo sURL; ?>Home/login" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-md transition-colors">Back to Login</a>
        </div>
    </div>
</div>
