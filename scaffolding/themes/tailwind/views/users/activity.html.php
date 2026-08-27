<?php
/**
 * One user's whole activity log (Tailwind theme).
 *
 * Variables:
 *   $this->user      — ['userid' => int, 'username' => string]
 *   $this->datatable — \Pramnos\Html\Datatable, server-side
 *
 * The user's own screen shows the last ten, which answers "what happened recently" and
 * not "what happened". This is the rest of it, through the same paging pipeline every
 * other list uses — an account with four thousand sign-ins is exactly the one somebody is
 * investigating.
 */
$user = is_array($this->user ?? null) ? $this->user : [];
$uid  = (int) ($user['userid'] ?? 0);
$name = htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'users_activity'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 class="text-lg font-semibold">Activity — <?php echo $name; ?></h2>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs p-4">
        <?php echo $this->datatable->render(); ?>
    </div>
</div>
