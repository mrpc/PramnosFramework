<?php
/**
 * One language-model call in full (plain-CSS theme).
 *
 * Variables:
 *   $this->call — the row: provider, model, purpose, system_prompt, messages (decoded), reply,
 *                 usage (decoded), tokens, cost, http_status, finish_reason, error, duration_ms
 *
 * The answer to "why did that cost that", and to "why did it fail": everything that was sent
 * and everything that came back.
 */
$c    = ['card' => 'pf-card', 'muted' => 'pf-muted', 'ok' => 'pf-ok', 'bad' => 'pf-bad', 'btn' => 'btn', 'btnOutline' => 'btn', 'input' => '', 'table' => 'pf-table'];
$e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$row  = is_array($this->call ?? null) ? $this->call : [];
$json = static fn ($v): string => htmlspecialchars((string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'ai_call'; $this->insert('../partials/admin_breadcrumb'); ?>

    <h1>AI call #<?php echo (int) ($row['call_id'] ?? 0); ?></h1>
    <p><a href="<?php echo $e(adminUrl('Ai')); ?>">← All calls</a></p>

    <table class="<?php echo $c['table']; ?>">
        <tbody>
            <tr><th>When</th><td><?php echo $e($row['created_at'] ?? ''); ?></td></tr>
            <tr><th>Purpose</th><td><?php echo $e($row['purpose'] ?? ''); ?></td></tr>
            <tr><th>Provider and model</th><td><?php echo $e(($row['provider'] ?? '') . ' · ' . ($row['model'] ?? '')); ?></td></tr>
            <tr><th>Status</th><td><?php echo $e(($row['http_status'] ?? '—') . ' ' . ($row['finish_reason'] ?? '')); ?></td></tr>
            <?php if (($row['error'] ?? null) !== null): ?>
                <tr><th>Error</th><td class="<?php echo $c['bad']; ?>"><?php echo $e($row['error']); ?></td></tr>
            <?php endif; ?>
            <tr><th>Tokens</th><td><?php echo ($row['tokens'] ?? null) === null ? '—' : number_format((int) $row['tokens']); ?></td></tr>
            <tr><th>Cost</th><td><?php echo ($row['cost'] ?? null) === null ? 'not priced' : '$' . number_format((float) $row['cost'], 6); ?></td></tr>
            <tr><th>Took</th><td><?php echo (int) ($row['duration_ms'] ?? 0); ?> ms</td></tr>
        </tbody>
    </table>

    <h2>System prompt</h2>
    <pre style="white-space:pre-wrap"><?php echo $e($row['system_prompt'] ?? ''); ?></pre>
    <h2>Messages</h2>
    <pre style="white-space:pre-wrap"><?php echo $json($row['messages'] ?? []); ?></pre>
    <h2>Reply</h2>
    <pre style="white-space:pre-wrap"><?php echo $e($row['reply'] ?? ''); ?></pre>
    <h2>Usage</h2>
    <pre><?php echo $json($row['usage'] ?? null); ?></pre>
</div>
