<?php
/**
 * Authorized Applications page (Tailwind theme).
 *
 * Variables:
 *   $this->authorizedApps — array[] {appid, name, apikey, description, last_used, token_count}
 */
?>
<div class="max-w-2xl mx-auto px-4 py-8">

    <p class="text-sm mb-4"><a href="<?php echo sURL; ?>Dashboard" class="text-blue-600 hover:underline">← Back to Dashboard</a></p>
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Authorized Applications</h2>

    <?php if (!empty($_GET['error'])): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded">
            <?php echo htmlspecialchars(urldecode($_GET['error'])); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($this->authorizedApps)): ?>
        <div class="px-4 py-8 bg-blue-50 border border-blue-100 rounded text-blue-700 text-center">
            You have no authorized applications.
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow divide-y divide-gray-100">
            <?php foreach ($this->authorizedApps as $app): ?>
                <div class="flex items-center justify-between px-4 py-4">
                    <div>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($app['name']); ?></p>
                        <?php if (!empty($app['description'])): ?>
                            <p class="text-sm text-gray-400 mt-0.5"><?php echo htmlspecialchars($app['description']); ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-400 mt-1">
                            <?php echo (int) $app['token_count']; ?> active token<?php echo $app['token_count'] != 1 ? 's' : ''; ?>
                            <?php if (!empty($app['last_used'])): ?>
                                &middot; Last used <?php echo htmlspecialchars(date('d M Y', (int) $app['last_used'])); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <form method="post" action="<?php echo sURL; ?>Dashboard/revokeapplication"
                          onsubmit="return confirm('Revoke access for <?php echo htmlspecialchars(addslashes($app['name'])); ?>?')">
                        <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($app['apikey']); ?>">
                        <button type="submit"
                                class="px-3 py-1 text-sm text-red-600 border border-red-300 rounded hover:bg-red-50 transition-colors">
                            Revoke
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
