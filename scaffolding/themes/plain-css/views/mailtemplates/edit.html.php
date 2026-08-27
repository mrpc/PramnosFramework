<?php
/**
 * Template editor (plain-CSS theme).
 *
 * Variables:
 *   $this->template     — the row (empty when creating)
 *   $this->isNew        — bool
 *   $this->types        — channel number => label
 *   $this->placeholders — the {names} this template already uses
 *
 * The three lookup fields — category, language, type — are together and explained,
 * because they are not descriptive: the framework *finds* a template by them, so a typo in
 * `category` is a notification that silently falls back to whatever the code does when no
 * template matches.
 */
$t      = is_array($this->template ?? null) ? $this->template : [];
$types  = is_array($this->types ?? null) ? $this->types : [];
$holds  = is_array($this->placeholders ?? null) ? $this->placeholders : [];
$id     = (int) ($t['templateid'] ?? 0);
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="page-section">
    <?php $this->activeNav = 'mailtemplates_edit'; $this->insert('../partials/admin_breadcrumb'); ?>

    <h2 style="margin:0 0 14px"><?php echo $this->isNew ? 'New template' : 'Edit template'; ?></h2>

    <form method="post" action="<?php echo adminUrl('MailTemplates/save'); ?>"
          class="card" style="border:1px solid #ddd;border-radius:4px">
        <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
        <input type="hidden" name="templateid" value="<?php echo $id; ?>">

        <div class="p-5 grid gap-4">
            <div>
                <label class="block text-sm font-medium mb-1" for="pf-tpl-title">Title</label>
                <input type="text" id="pf-tpl-title" name="title" style="width:100%;padding:6px 8px" required
                       value="<?php echo $e($t['title'] ?? ''); ?>">
                <p class="text-xs text-base-content/60 mt-1">What an operator calls it. Not sent to anybody.</p>
            </div>

            <fieldset class="border border-base-300 rounded-box p-4">
                <legend class="text-xs uppercase tracking-wide text-base-content/60 px-1">Lookup key</legend>
                <div class="grid gap-3 md:grid-cols-3">
                    <div>
                        <label class="block text-xs font-medium mb-1" for="pf-tpl-category">Category</label>
                        <input type="text" id="pf-tpl-category" name="category" style="width:100%;padding:6px 8px" required
                               placeholder="auth.passwordreset" value="<?php echo $e($t['category'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="pf-tpl-language">Language</label>
                        <input type="text" id="pf-tpl-language" name="language" style="width:100%;padding:6px 8px"
                               placeholder="en" value="<?php echo $e($t['language'] ?? 'en'); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="pf-tpl-type">Channel</label>
                        <select id="pf-tpl-type" name="type" style="width:100%;padding:6px 8px">
                            <?php foreach ($types as $value => $label): ?>
                            <option value="<?php echo (int) $value; ?>"
                                <?php echo (int) ($t['type'] ?? 0) === (int) $value ? 'selected' : ''; ?>>
                                <?php echo $e($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="text-xs text-base-content/60 mt-2 mb-0">
                    The framework <em>finds</em> a template by these three, so a typo here is a
                    notification that silently falls back to whatever the code does when nothing
                    matches. One row per language of the same category.
                </p>
            </fieldset>

            <div>
                <label class="block text-sm font-medium mb-1" for="pf-tpl-subject">Subject</label>
                <input type="text" id="pf-tpl-subject" name="defaultsubject" style="width:100%;padding:6px 8px"
                       value="<?php echo $e($t['defaultsubject'] ?? ''); ?>">
                <p class="text-xs text-base-content/60 mt-1">Also the title of a push notification.</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="pf-tpl-body">Body</label>
                <textarea id="pf-tpl-body" name="defaulttext" style="width:100%;padding:6px 8px;font-family:monospace;font-size:0.8em" rows="14"
                          spellcheck="false"><?php echo $e($t['defaulttext'] ?? ''); ?></textarea>
                <p class="text-xs text-base-content/60 mt-1">
                    Markup is kept — an email template is markup. Placeholders are
                    <code>{name}</code>; the ones already here:
                    <?php if ($holds === []): ?>
                    <span class="text-base-content/50">none yet</span>
                    <?php else: ?>
                        <?php foreach ($holds as $placeholder): ?>
                        <code class="badge badge-ghost badge-xs">{<?php echo $e($placeholder); ?>}</code>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </p>
            </div>

            <details class="border border-base-300 rounded-box p-4">
                <summary class="text-sm cursor-pointer">Delivery details</summary>
                <div class="grid gap-3 md:grid-cols-3 mt-3">
                    <div>
                        <label class="block text-xs font-medium mb-1" for="pf-tpl-wrapper">HTML wrapper</label>
                        <input type="text" id="pf-tpl-wrapper" name="emailtemplate" style="width:100%;padding:6px 8px"
                               placeholder="default" value="<?php echo $e($t['emailtemplate'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="pf-tpl-method">Send method</label>
                        <select id="pf-tpl-method" name="sendmethod" style="width:100%;padding:6px 8px">
                            <option value="0" <?php echo (int) ($t['sendmethod'] ?? 0) === 0 ? 'selected' : ''; ?>>Default (SMTP)</option>
                            <option value="1" <?php echo (int) ($t['sendmethod'] ?? 0) === 1 ? 'selected' : ''; ?>>Amazon SES API</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="pf-tpl-sound">Push sound</label>
                        <input type="text" id="pf-tpl-sound" name="sound" style="width:100%;padding:6px 8px"
                               value="<?php echo $e($t['sound'] ?? ''); ?>">
                    </div>
                </div>
            </details>
        </div>

        <div class="px-5 py-4 border-t border-base-300 flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Save template</button>
            <a href="<?php echo adminUrl('MailTemplates'); ?>" class="btn btn-outline btn-sm">Cancel</a>
            <?php if (!$this->isNew): ?>
            <a href="<?php echo adminUrl('MailTemplates/view/') . $id; ?>" class="btn btn-sm" style="margin-left:auto">
                Preview &amp; test
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>
