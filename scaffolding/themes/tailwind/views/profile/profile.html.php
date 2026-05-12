<?php
/**
 * User profile page (Tailwind theme).
 *
 * Variables:
 *   $this->title — Page title
 *   $this->user  — User object (username, email, regdate, last_login)
 */
?>
<div class="container mx-auto py-8 px-4 max-w-2xl">
    <h2 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars($this->title ?? 'My Profile'); ?></h2>
    <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100">
        <div class="flex px-6 py-4">
            <span class="w-40 font-semibold text-gray-600 text-sm">Username</span>
            <span class="text-gray-900 text-sm"><?php echo htmlspecialchars($this->user->username ?? ''); ?></span>
        </div>
        <div class="flex px-6 py-4">
            <span class="w-40 font-semibold text-gray-600 text-sm">Email</span>
            <span class="text-gray-900 text-sm"><?php echo htmlspecialchars($this->user->email ?? ''); ?></span>
        </div>
        <?php if (!empty($this->user->regdate)): ?>
        <div class="flex px-6 py-4">
            <span class="w-40 font-semibold text-gray-600 text-sm">Member Since</span>
            <span class="text-gray-900 text-sm"><?php echo htmlspecialchars(date('Y-m-d', is_numeric($this->user->regdate) ? $this->user->regdate : strtotime($this->user->regdate))); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($this->user->last_login)): ?>
        <div class="flex px-6 py-4">
            <span class="w-40 font-semibold text-gray-600 text-sm">Last Login</span>
            <span class="text-gray-900 text-sm"><?php echo htmlspecialchars($this->user->last_login); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <div class="mt-4">
        <a href="<?php echo sURL; ?>Dashboard" class="text-sm text-blue-600 hover:underline">&larr; Back to Dashboard</a>
    </div>
</div>
