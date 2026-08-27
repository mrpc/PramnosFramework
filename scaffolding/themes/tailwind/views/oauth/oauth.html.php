<?php
/**
 * OAuth Applications admin overview (Tailwind theme).
 *
 * Variables:
 *   $this->apps — array[] {appid, name, description, apikey, status, created}
 */
?>
<div class="max-w-4xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold mb-6">OAuth Applications</h2>

    <?php if (empty($this->apps)): ?>
        <p class="text-base-content/70">No OAuth applications registered yet.</p>
    <?php else: ?>
        <div class="overflow-x-auto rounded-sm shadow-sm">
            <table class="table min-w-full bg-base-100">
                <thead class="bg-base-200 text-base-content text-sm uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left">Name</th>
                        <th class="px-4 py-3 text-left">Client ID</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-300 text-sm">
                    <?php foreach ($this->apps as $app): ?>
                        <tr class="hover:bg-base-200">
                            <td class="px-4 py-3">
                                <span class="font-medium"><?php echo htmlspecialchars((string) $app['name']); ?></span>
                                <?php if (!empty($app['description'])): ?>
                                    <p class="text-base-content/70 text-xs mt-0.5"><?php echo htmlspecialchars((string) $app['description']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs"><?php echo htmlspecialchars((string) $app['apikey']); ?></td>
                            <td class="px-4 py-3">
                                <?php if ((int) $app['status'] === 1): ?>
                                    <span class="inline-block px-2 py-0.5 rounded-sm text-xs bg-success/10 text-success">Active</span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 rounded-sm text-xs bg-base-300 text-base-content/80">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-base-content/70"><?php echo htmlspecialchars(date('d M Y', (int) $app['created'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>
