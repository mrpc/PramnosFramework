<?php
/**
 * Help — the installation's pages by section, and a search (tailwind theme).
 *
 * Variables:
 *   $this->bySection — section => list of pages (slug, title, questions, framework)
 *   $this->query     — what was searched for, or ''
 *   $this->found     — the pages that answer it, best first, or null when nothing was asked
 *   $this->pageUrl   — Closure(slug): the page's address
 *   $this->listUrl   — this page's address
 */
$c = ['wrap' => 'px-4 py-6', 'card' => 'card bg-base-100 border border-base-300 p-4 mb-4', 'muted' => 'text-sm opacity-70', 'input' => 'input input-sm input-bordered', 'btn' => 'btn btn-sm btn-primary', 'h1' => 'text-2xl font-semibold mb-3', 'h2' => 'text-lg font-semibold mt-4'];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$link = $this->pageUrl;
$list = static function (array $pages) use ($e, $link, $c): void {
    echo '<ul>';
    foreach ($pages as $page) {
        echo '<li><a href="' . $e($link($page['slug'])) . '">' . $e($page['title']) . '</a>';
        if ($page['questions'] !== []) {
            echo ' <span class="' . $c['muted'] . '">— ' . $e(implode(' · ', array_slice($page['questions'], 0, 2))) . '</span>';
        }
        echo '</li>';
    }
    echo '</ul>';
};
?>
<div class="<?php echo $c['wrap']; ?>">
    <h1 class="<?php echo $c['h1']; ?>"><?php echo $e(t('Help')); ?></h1>
    <form method="get" data-pf-tool="search_help" data-pf-tool-description="Search this application's help pages for how to do something. Answers with the matching pages." action="<?php echo $e($this->listUrl); ?>" role="search" class="<?php echo $c['card']; ?>" style="display:flex;gap:8px;align-items:center">
        <label for="help-q" class="pf-sr-only"><?php echo $e(t('Search the help')); ?></label>
        <input id="help-q" type="search" name="q" value="<?php echo $e($this->query); ?>" class="<?php echo $c['input']; ?>" style="flex:1" placeholder="<?php echo $e(t('What do you want to do?')); ?>">
        <button type="submit" class="<?php echo $c['btn']; ?>"><?php echo $e(t('Search')); ?></button>
    </form>
    <?php if (is_array($this->found)): ?>
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e(sprintf(t('Pages for “%s”'), $this->query)); ?></h2>
        <?php if ($this->found === []): ?>
            <p class="<?php echo $c['muted']; ?>"><?php echo $e(t('No page answers that. Try other words.')); ?></p>
        <?php else: $list($this->found); endif; ?>
    <?php endif; ?>
    <?php foreach ($this->bySection as $section => $pages): ?>
        <h2 class="<?php echo $c['h2']; ?>"><?php echo $e(t($section)); ?></h2>
        <?php $list($pages); ?>
    <?php endforeach; ?>
    <?php if ($this->bySection === []): ?>
        <p class="<?php echo $c['muted']; ?>"><?php echo $e(t('There are no help pages yet.')); ?></p>
    <?php endif; ?>
</div>
