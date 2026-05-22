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
        <p class="text-gray-500">No OAuth applications registered yet.</p>
    <?php else: ?>
        <div class="overflow-x-auto rounded shadow">
            <table class="min-w-full bg-white">
                <thead class="bg-gray-100 text-gray-700 text-sm uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left">Name</th>
                        <th class="px-4 py-3 text-left">Client ID</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 text-sm">
                    <?php foreach ($this->apps as $app): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="font-medium"><?php echo htmlspecialchars((string) $app['name']); ?></span>
                                <?php if (!empty($app['description'])): ?>
                                    <p class="text-gray-500 text-xs mt-0.5"><?php echo htmlspecialchars((string) $app['description']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs"><?php echo htmlspecialchars((string) $app['apikey']); ?></td>
                            <td class="px-4 py-3">
                                <?php if ((int) $app['status'] === 1): ?>
                                    <span class="inline-block px-2 py-0.5 rounded text-xs bg-green-100 text-green-800">Active</span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-600">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars(date('d M Y', (int) $app['created'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>
