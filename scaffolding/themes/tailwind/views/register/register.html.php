<?php
/**
 * User registration form (Tailwind theme).
 *
 * Variables:
 *   $this->header   — Page heading
 *   $this->error    — Optional error string
 *   $this->formData — array of previously submitted field values (username, email)
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4 py-8">
    <div class="w-full max-w-md bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->header ?? 'Create Account'); ?></h1>

        <?php if (!empty($this->error)): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo htmlspecialchars($this->error); ?></div>
        <?php endif; ?>
        <?php if ($this->hasErrors()): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo sURL; ?>Home/register" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="username" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                       required autocomplete="username" value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" id="email" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                       required autocomplete="email" value="<?php echo htmlspecialchars($this->formData['email'] ?? ''); ?>">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                       required minlength="6" autocomplete="new-password">
                <p class="text-xs text-gray-500 mt-1">At least 6 characters.</p>
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                       required autocomplete="new-password">
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Create Account</button>
        </form>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>Home/login" class="text-blue-600 hover:underline">Already have an account? Sign in</a>
        </p>
    </div>
</div>
