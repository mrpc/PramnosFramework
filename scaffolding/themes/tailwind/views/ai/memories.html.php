<?php
/**
 * Everybody's Ask memory (Tailwind theme) — `Ai/memories`, for whoever reads everybody's Ask
 * conversations: who has memories, how many and when the latest changed; with `?person=` one
 * person's (0: what an organisation shares), each to correct or delete. Each reading is in the
 * audit log (Pramnos\Llm\Chat\MemoryAdmin).
 *
 * Variables:
 *   $this->people   — userid, username, organization_id, space, count, latest; newest first
 *   $this->person   — the person open, or null; $this->org, $this->space — whose and where
 *   $this->memories — theirs: memory_id, kind (fact|preference), content, source (model|learned|null), at
 *   $this->urls     — page, correct, delete, chat
 */
$c     = ['page' => 'max-w-4xl mx-auto px-4 py-6 flex flex-col gap-4', 'card' => 'card bg-base-100 border border-base-300 p-4', 'muted' => 'text-sm opacity-70', 'bad' => 'text-sm text-error', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'danger' => 'btn btn-sm btn-ghost text-error', 'input' => 'input input-sm input-bordered', 'select' => 'select select-sm select-bordered', 'textarea' => 'textarea textarea-bordered w-full', 'badge' => 'badge badge-ghost badge-sm', 'row' => 'flex flex-wrap items-center gap-2', 'field' => 'flex flex-col gap-1 text-sm', 'answer' => 'prose max-w-none', 'h1' => 'text-2xl font-semibold', 'h2' => 'text-lg font-semibold'];
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t     = static fn (string $words, mixed ...$args): string => vsprintf(function_exists('t') ? (string) t($words) : $words, $args);
$token = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$u     = (array) ($this->urls ?? []);
$query = static fn (array $who): string => '?' . http_build_query(['person' => $who['userid'], 'org' => $who['organization_id'] ?? '', 'space' => $who['space']]);
$whose = static fn (array $who): string => $who['userid'] === 0
    ? $t('Shared by organisation %s', (string) ($who['organization_id'] ?? '—'))
    : (string) $who['username'];
$open  = null;
foreach ((array) ($this->people ?? []) as $who) {
    if ($this->person === $who['userid'] && $this->org === $who['organization_id'] && $this->space === $who['space']) {
        $open = $who;
    }
}
$split = 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:12px';
?>
<div class="<?php echo $c['page']; ?>" data-pf-memories>
    <div style="<?php echo $split; ?>;align-items:center">
        <h1 class="<?php echo $c['h1']; ?>" style="margin:0"><?php echo $e($t('Everybody\'s Ask memory')); ?></h1>
        <a href="<?php echo $e($u['chat'] ?? ''); ?>" class="<?php echo $c['btnGhost']; ?>">&larr; <?php echo $e($t('Back to the conversations')); ?></a>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="<?php echo $c['card']; ?>" style="flex:0 0 16rem" aria-label="<?php echo $e($t('People')); ?>">
            <?php if (empty($this->people)): ?><p class="<?php echo $c['muted']; ?>"><?php echo $e($t('Nobody has memories yet.')); ?></p><?php endif; ?>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:4px">
            <?php foreach ((array) ($this->people ?? []) as $who): ?>
                <li><a href="<?php echo $e(($u['page'] ?? '') . $query($who)); ?>"<?php echo $who === $open ? ' aria-current="page" style="font-weight:600"' : ''; ?>>
                    <?php echo $e($whose($who)); ?></a>
                    <span class="<?php echo $c['muted']; ?>"><?php echo $e($t('%s · %s', (string) $who['count'], substr((string) $who['latest'], 0, 10))); ?><?php echo $who['space'] !== 'chat' ? ' · ' . $e($who['space']) : ''; ?></span></li>
            <?php endforeach; ?>
            </ul>
        </nav>
        <main style="flex:1 1 24rem;min-width:0;display:flex;flex-direction:column;gap:12px">
        <?php if ($open === null): ?>
            <p class="<?php echo $c['muted']; ?>"><?php echo $e($t('Choose somebody to see what Ask remembers about them.')); ?></p>
        <?php else: ?>
            <h2 class="<?php echo $c['h2']; ?>"><?php echo $e($whose($open)); ?></h2>
            <?php foreach ((array) ($this->memories ?? []) as $memory): ?>
            <div class="<?php echo $c['card']; ?>" data-pf-memory="<?php echo (int) $memory['memory_id']; ?>">
                <div class="<?php echo $c['row']; ?>"><span class="<?php echo $c['badge']; ?>"><?php echo $e($t($memory['kind'] === 'preference' ? 'Preference' : 'Fact')); ?></span>
                    <span class="<?php echo $c['muted']; ?>"><?php echo $e($memory['source'] === 'learned' ? $t('Learned') : $t('Asked to remember')); ?> · <?php echo $e($memory['at']); ?></span></div>
                <form method="post" action="<?php echo $e($u['correct'] ?? ''); ?>" style="display:flex;flex-direction:column;gap:8px;margin-top:8px">
                    <?php echo $token(); ?>
                    <input type="hidden" name="memory_id" value="<?php echo (int) $memory['memory_id']; ?>">
                    <input type="hidden" name="back" value="<?php echo $e($query($open)); ?>">
                    <textarea name="content" rows="2" class="<?php echo $c['textarea']; ?>" style="width:100%" aria-label="<?php echo $e($t('What it remembers')); ?>"><?php echo $e($memory['content']); ?></textarea>
                    <div class="<?php echo $c['row']; ?>">
                        <button class="<?php echo $c['btn']; ?>"><?php echo $e($t('Save')); ?></button>
                        <button class="<?php echo $c['danger']; ?>" formaction="<?php echo $e($u['delete'] ?? ''); ?>"><?php echo $e($t('Delete')); ?></button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </main>
    </div>
</div>
