<?php
/**
 * The conversations' files: the space they take, the largest file allowed, removing old ones in
 * bulk with a report, and the files by conversation and month, the largest and the oldest — each
 * removable. The same markup in every theme: it is dressed by the `pf-*` rules in components.css.
 *
 * Variables:
 *   $this->usage         — Files::usage(): total {files, bytes}, byConversation, largest, oldest
 *   $this->conversations — conversation id => its name (#channel, or its people)
 *   $this->people        — account id => name, for who sent each file
 *   $this->limit         — the largest file, in MB
 */
use Pramnos\Application\Controllers\ConversationFiles;

$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$usage   = (array) ($this->usage ?? []);
$names   = (array) ($this->conversations ?? []);
$people  = (array) ($this->people ?? []);
$csrf   = \Pramnos\Http\Session::getInstance()->getTokenField();
$total   = (array) ($usage['total'] ?? ['files' => 0, 'bytes' => 0]);
$when    = static fn (int $at): string => date('d/m/Y', $at);
$files   = static function (array $rows) use ($e, $names, $people, $csrf, $when): string {
    if ($rows === []) {
        return '<div class="pf-empty"><p class="pf-empty-text">' . $e(t('No files are kept.')) . '</p></div>';
    }
    $html = '<table class="pf-table"><thead><tr><th>' . $e(t('File')) . '</th><th>' . $e(t('Conversation')) . '</th><th>' . $e(t('Sent by')) . '</th><th>'
        . $e(t('Sent on')) . '</th><th class="pf-num">' . $e(t('Size')) . '</th><th></th></tr></thead><tbody>';
    foreach ($rows as $file) {
        $html .= '<tr><td>' . $e($file['name']) . '</td><td>' . $e($names[$file['conversationId']] ?? '#' . $file['conversationId']) . '</td><td>'
            . $e($people[$file['uploadedBy']] ?? '—') . '</td><td>' . $e($when((int) $file['createdAt'])) . '</td><td class="pf-num">'
            . $e(ConversationFiles::bytes((int) $file['size'])) . '</td><td class="pf-num"><form method="post" class="pf-inline-form" action="'
            . $e(adminUrl('ConversationFiles/remove/') . $file['id']) . '" data-confirm="' . $e(t('Remove this file? Its message will say it was removed.')) . '">'
            . $csrf . '<button type="submit" class="pf-btn pf-btn-sm">' . $e(t('Remove')) . '</button></form></td></tr>';
    }

    return $html . '</tbody></table>';
};
?>
<div class="pf-ui">
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e(t('Conversation files')); ?></h1>
            <p class="pf-hint"><?php echo $e(t('Removing a file frees its space; its message says it was removed, and when.')); ?></p>
        </div>
        <div class="pf-page-actions">
            <a href="<?php echo $e(adminUrl('Conversations')); ?>" class="pf-btn"><?php echo $e(t('Conversations')); ?></a>
        </div>
    </header>

    <div class="pf-stat-grid">
        <div class="pf-stat-tile"><span class="pf-stat-tile__label"><?php echo $e(t('Files kept')); ?></span><span class="pf-stat-tile__value"><?php echo $e(number_format((int) $total['files'], 0, ',', '.')); ?></span></div>
        <div class="pf-stat-tile"><span class="pf-stat-tile__label"><?php echo $e(t('Space they take')); ?></span><span class="pf-stat-tile__value"><?php echo $e(ConversationFiles::bytes((int) $total['bytes'])); ?></span></div>
        <div class="pf-stat-tile"><span class="pf-stat-tile__label"><?php echo $e(t('Largest file allowed')); ?></span><span class="pf-stat-tile__value"><?php echo $e((int) $this->limit); ?> MB</span></div>
    </div>

    <div class="pf-grid-2">
        <form method="post" action="<?php echo $e(adminUrl('ConversationFiles/prune')); ?>" class="pf-card" data-confirm="<?php echo $e(t('Remove these files? This cannot be undone.')); ?>">
            <?php echo $csrf; ?>
            <div class="pf-card-head"><h2 class="pf-section-title"><?php echo $e(t('Free space')); ?></h2></div>
            <div class="pf-card-body pf-grid-2">
                <label class="pf-field"><span class="pf-label"><?php echo $e(t('Remove the files older than (days)')); ?></span>
                    <input type="number" name="days" min="0" value="365" class="pf-input" required></label>
                <label class="pf-field"><span class="pf-label"><?php echo $e(t('From which conversations')); ?></span>
                    <select name="conversation" class="pf-select">
                        <option value="0"><?php echo $e(t('every conversation')); ?></option>
                        <?php foreach ($names as $id => $name): ?><option value="<?php echo (int) $id; ?>"><?php echo $e($name); ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <div class="pf-card-body"><button type="submit" class="pf-btn pf-btn-danger"><?php echo $e(t('Remove and free the space')); ?></button>
                <p class="pf-hint"><?php echo $e(t('Their messages stay, saying a file was removed and when. 0 days removes every one.')); ?></p></div>
        </form>
        <form method="post" action="<?php echo $e(adminUrl('ConversationFiles/limit')); ?>" class="pf-card">
            <?php echo $csrf; ?>
            <div class="pf-card-head"><h2 class="pf-section-title"><?php echo $e(t('Largest file allowed')); ?></h2></div>
            <div class="pf-card-body">
                <label class="pf-field"><span class="pf-label"><?php echo $e(t('In MB, for every conversation')); ?></span>
                    <input type="number" name="mb" min="1" max="1024" value="<?php echo (int) $this->limit; ?>" class="pf-input" required></label>
            </div>
            <div class="pf-card-body"><button type="submit" class="pf-btn pf-btn-primary"><?php echo $e(t('Save')); ?></button></div>
        </form>
    </div>

    <?php if ((int) $total['files'] === 0): ?>
        <div class="pf-card"><div class="pf-empty">
            <p class="pf-empty-title"><?php echo $e(t('No files are kept.')); ?></p>
            <p class="pf-empty-text"><?php echo $e(t('The files sent in the conversations will be listed here, with the space they take.')); ?></p>
        </div></div>
    <?php else: ?>
    <div class="pf-card pf-card-flush">
        <div class="pf-card-head"><h2 class="pf-section-title"><?php echo $e(t('By conversation and month')); ?></h2></div>
            <table class="pf-table">
                <thead><tr><th><?php echo $e(t('Conversation')); ?></th><th><?php echo $e(t('Month')); ?></th><th class="pf-num"><?php echo $e(t('Files')); ?></th><th class="pf-num"><?php echo $e(t('Size')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($usage['byConversation'] as $row): ?>
                    <tr><td><?php echo $e($names[$row['conversationId']] ?? '#' . $row['conversationId']); ?></td><td><?php echo $e($row['month']); ?></td>
                        <td class="pf-num"><?php echo (int) $row['files']; ?></td><td class="pf-num"><?php echo $e(ConversationFiles::bytes((int) $row['bytes'])); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
    </div>

    <div class="pf-card pf-card-flush">
        <div class="pf-card-head"><h2 class="pf-section-title"><?php echo $e(t('The largest')); ?></h2></div>
        <?php echo $files((array) ($usage['largest'] ?? [])); ?>
    </div>
    <div class="pf-card pf-card-flush">
        <div class="pf-card-head"><h2 class="pf-section-title"><?php echo $e(t('The oldest')); ?></h2></div>
        <?php echo $files((array) ($usage['oldest'] ?? [])); ?>
    </div>
    <?php endif; ?>
</div>
