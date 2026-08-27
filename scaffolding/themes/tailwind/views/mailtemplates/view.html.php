<?php
/**
 * One template, read-only, with a test send (Tailwind theme).
 *
 * Variables:
 *   $this->template     — the row
 *   $this->types        — channel number => label
 *   $this->placeholders — the {names} this template actually uses
 *
 * The body is printed into a `<pre>`, escaped. It is markup — an email template is — and
 * this screen is for reading it, not for rendering somebody's HTML inside the admin.
 */
$t     = is_array($this->template ?? null) ? $this->template : [];
$types = is_array($this->types ?? null) ? $this->types : [];
$holds = is_array($this->placeholders ?? null) ? $this->placeholders : [];
$id    = (int) ($t['templateid'] ?? 0);
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'mailtemplates_view'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('MailTemplates'); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 class="text-lg font-semibold"><?php echo $e($t['title'] ?? ''); ?></h2>
        <span class="badge badge-ghost badge-sm"><?php echo $e($types[(int) ($t['type'] ?? 0)] ?? ''); ?></span>
        <span class="badge badge-ghost badge-sm"><?php echo $e($t['language'] ?? ''); ?></span>
        <a href="<?php echo adminUrl('MailTemplates/edit/') . $id; ?>" class="btn btn-primary btn-sm ms-auto gap-2">
            <?php echo \Pramnos\Html\Icon::svg('edit'); ?> Edit
        </a>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
            <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">
                Subject &amp; body
            </div>
            <div class="p-5 space-y-3">
                <div>
                    <div class="text-xs uppercase tracking-wide text-base-content/60 mb-1">Subject</div>
                    <div class="text-sm"><?php echo $e($t['defaultsubject'] ?? ''); ?></div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-base-content/60 mb-1">Body</div>
                    <pre class="text-xs bg-base-200 rounded-box p-3 overflow-x-auto whitespace-pre-wrap"><?php
                        echo $e($t['defaulttext'] ?? '');
                    ?></pre>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Placeholders</div>
                <div class="p-4">
                    <?php if ($holds === []): ?>
                    <p class="text-sm text-base-content/60 mb-0">
                        This template has none — it says the same thing to everybody.
                    </p>
                    <?php else: ?>
                    <div class="flex flex-wrap gap-1">
                        <?php foreach ($holds as $placeholder): ?>
                        <code class="badge badge-ghost badge-sm">{<?php echo $e($placeholder); ?>}</code>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-base-content/60 mt-2 mb-0">
                        Read from the template itself, so this cannot go stale.
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Send a test</div>
                <form method="post" action="<?php echo adminUrl('MailTemplates/test/') . $id; ?>" class="p-4">
                    <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                    <label class="block text-xs font-medium mb-1" for="pf-test-address">Address</label>
                    <input type="email" id="pf-test-address" name="address" class="input input-sm w-full mb-2" required
                           placeholder="you@example.com">
                    <button type="submit" class="btn btn-outline btn-sm w-full gap-2">
                        <?php echo \Pramnos\Html\Icon::svg('send'); ?> Send test
                    </button>
                    <p class="text-xs text-base-content/60 mt-2 mb-0">
                        Placeholders arrive as <code>[name]</code>, so you can see where each
                        one lands without inventing data that would hide a missing one.
                    </p>
                </form>
            </div>

            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Lookup key</div>
                <div class="p-4 text-sm space-y-1">
                    <div><span class="text-xs uppercase text-base-content/60">Category</span>
                        <code class="ms-1"><?php echo $e($t['category'] ?? ''); ?></code></div>
                    <div><span class="text-xs uppercase text-base-content/60">Language</span>
                        <code class="ms-1"><?php echo $e($t['language'] ?? ''); ?></code></div>
                    <div><span class="text-xs uppercase text-base-content/60">Type</span>
                        <code class="ms-1"><?php echo (int) ($t['type'] ?? 0); ?></code></div>
                    <p class="text-xs text-base-content/60 mt-2 mb-0">
                        The three the framework looks a template up by.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
