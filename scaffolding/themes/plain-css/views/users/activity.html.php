<?php
/**
 * One user's whole activity log (plain-CSS theme).
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
<div class="page-section">
    <?php $this->activeNav = 'users_activity'; $this->insert('../partials/admin_breadcrumb'); ?>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px">
        <a href="<?php echo adminUrl('users/view/' . $uid); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 style="margin:0">Activity — <?php echo $name; ?></h2>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;padding:16px">
        <?php echo $this->datatable->render(); ?>
    </div>
</div>
