<?php
/**
 * OAuth Applications admin overview (plain-CSS theme).
 *
 * Variables:
 *   $this->apps — array[] {appid, name, description, apikey, status, created}
 */
?>
<div class="container">
    <h2>OAuth Applications</h2>

    <?php if (empty($this->apps)): ?>
        <p>No OAuth applications registered yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Client ID</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->apps as $app): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars((string) $app['name']); ?></strong>
                            <?php if (!empty($app['description'])): ?>
                                <br><small><?php echo htmlspecialchars((string) $app['description']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo htmlspecialchars((string) $app['apikey']); ?></code></td>
                        <td><?php echo (int) $app['status'] === 1 ? 'Active' : 'Inactive'; ?></td>
                        <td><?php echo htmlspecialchars(date('d M Y', (int) $app['created'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
