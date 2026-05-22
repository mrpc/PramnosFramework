<?php
/**
 * OAuth Applications admin overview (Bootstrap theme).
 *
 * Variables:
 *   $this->apps — array[] {appid, name, description, apikey, status, created}
 */
?>
<div class="container py-4" style="max-width:900px">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">OAuth Applications</h2>
    </div>

    <?php if (empty($this->apps)): ?>
        <div class="alert alert-info">No OAuth applications registered yet.</div>
    <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
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
                                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $app['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo htmlspecialchars((string) $app['apikey']); ?></code></td>
                                <td>
                                    <?php if ((int) $app['status'] === 1): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(date('d M Y', (int) $app['created'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>
