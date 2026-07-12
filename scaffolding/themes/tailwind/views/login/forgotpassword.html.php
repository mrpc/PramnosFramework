<?php
/**
 * Forgot password form (Tailwind theme).
 *
 * Variables:
 *   $this->title — Optional page title override
 *   $this->error — Optional error string
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->title ?? 'Forgot Password'); ?></h1>

        <?php if (!empty($this->error)): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo htmlspecialchars($this->error); ?></div>
        <?php endif; ?>
        <?php if ($this->hasErrors()): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>
        <?php if ($this->hasMessages()): ?>
            <div class="bg-blue-100 border border-blue-300 text-blue-800 rounded p-3 mb-4"><?php echo $this->_printMessages(); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo sURL; ?>Home/forgotpasswordsubmit" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" id="email"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                       required autocomplete="email">
                <p class="text-xs text-gray-500 mt-1">We will send a password reset link to this address.</p>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Send Reset Link</button>
        </form>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>Home/login" class="text-blue-600 hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
