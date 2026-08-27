<?php
/**
 * Message templates, grouped by notification (Tailwind theme).
 *
 * Variables:
 *   $this->groups — [category|type => rows], one group per notification
 *   $this->types  — channel number => label
 *
 * Grouped rather than listed flat: one notification is several rows — same category and
 * type, one per language — and eighty flat rows cannot answer "is the password-reset email
 * translated into Greek", which is what somebody opens this screen to find out.
 */
$groups = is_array($this->groups ?? null) ? $this->groups : [];
$types  = is_array($this->types ?? null) ? $this->types : [];
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'mailtemplates'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h2 class="text-lg font-semibold">Message templates</h2>
        <span class="badge badge-neutral badge-sm"><?php echo count($groups); ?></span>
        <a href="<?php echo adminUrl('MailTemplates/edit'); ?>" class="btn btn-primary btn-sm ms-auto gap-2">
            <?php echo \Pramnos\Html\Icon::svg('edit'); ?> New template
        </a>
    </div>

    <?php if ($groups === []): ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs p-6 text-sm text-base-content/60">
        No templates yet. A template is the wording of one notification, in one language —
        create one per language of the same category and the framework picks the right one
        by <code>(category, language, type)</code>.
    </div>
    <?php else: ?>
    <div class="grid gap-3">
        <?php foreach ($groups as $key => $rows): ?>
            <?php
            $first    = (array) ($rows[0] ?? []);
            $category = (string) ($first['category'] ?? '');
            $type     = (int) ($first['type'] ?? 0);
            ?>
            <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
                <div class="px-5 py-3 bg-base-200 border-b border-base-300 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm"><?php echo $e($category); ?></span>
                    <span class="badge badge-ghost badge-sm"><?php echo $e($types[$type] ?? $type); ?></span>
                    <span class="ms-auto text-xs text-base-content/60">
                        <?php echo count($rows); ?> language<?php echo count($rows) === 1 ? '' : 's'; ?>
                    </span>
                </div>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-100 text-xs uppercase text-base-content/60">
                        <tr><th>Language</th><th>Title</th><th>Subject</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $row = (array) $row; $id = (int) ($row['templateid'] ?? 0); ?>
                        <tr>
                            <td class="font-mono text-xs"><?php echo $e($row['language'] ?? ''); ?></td>
                            <td>
                                <a class="link link-primary" href="<?php echo adminUrl('MailTemplates/view/') . $id; ?>">
                                    <?php echo $e($row['title'] ?? ''); ?>
                                </a>
                            </td>
                            <td class="text-xs text-base-content/70"><?php echo $e($row['defaultsubject'] ?? ''); ?></td>
                            <td class="text-end whitespace-nowrap">
                                <?php
                                echo \Pramnos\Html\Icon::link(adminUrl('MailTemplates/view/') . $id, 'view', 'Open this template');
                                echo \Pramnos\Html\Icon::link(adminUrl('MailTemplates/edit/') . $id, 'edit', 'Edit this template');
                                echo \Pramnos\Html\Icon::link(
                                    adminUrl('MailTemplates/delete/') . $id,
                                    'delete',
                                    'Delete this template',
                                    ['data-confirm' => 'Delete this template?', 'class' => 'pf-action-danger']
                                );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
