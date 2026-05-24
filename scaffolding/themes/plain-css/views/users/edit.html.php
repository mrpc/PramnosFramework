<?php
/**
 * User create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->user   — user row array (null when creating)
 *   $this->isNew  — bool
 *   $this->error  — string error message
 */
$u = $this->user ?? [];
?>
<div class="page-section"max-width:640px">
    <h2 style="margin-bottom:16px"><?php echo $this->isNew ? 'New User' : 'Edit User'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert" style="background:#fde8e8;border:1px solid #f5c6cb;padding:12px 16px;border-radius:4px;margin-bottom:12px"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo sURL; ?>Users/save">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$this->isNew): ?>
                    <input type="hidden" name="userid" value="<?php echo (int)($u['userid'] ?? 0); ?>">
                <?php endif; ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Username</label>
                    <input type="text" name="username" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required value="<?php echo htmlspecialchars($u['username'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Email</label>
                    <input type="email" name="email" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>">
                </div>
                <?php if ($this->isNew): ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Password</label>
                    <input type="password" name="password" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required>
                </div>
                <?php endif; ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">First Name</label>
                    <input type="text" name="firstname" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($u['firstname'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Last Name</label>
                    <input type="text" name="lastname" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($u['lastname'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">User Type</label>
                    <select name="usertype" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
                        <?php $maxType = $this->currentUserType ?? 100; $curType = $u['usertype'] ?? 1; ?>
                        <option value="1" <?php echo $curType == 1 ? 'selected' : ''; ?>>User (1)</option>
                        <?php if ($maxType >= 50): ?><option value="50" <?php echo $curType == 50 ? 'selected' : ''; ?>>Editor (50)</option><?php endif; ?>
                        <?php if ($maxType >= 80): ?><option value="80" <?php echo $curType == 80 ? 'selected' : ''; ?>>Manager (80)</option><?php endif; ?>
                        <?php if ($maxType >= 90): ?><option value="90" <?php echo $curType == 90 ? 'selected' : ''; ?>>Admin (90)</option><?php endif; ?>
                        <?php if ($maxType >= 100): ?><option value="100" <?php echo $curType == 100 ? 'selected' : ''; ?>>Super Admin (100)</option><?php endif; ?>
                    </select>
                </div>
                <div style="margin-bottom:12px;display:flex;gap:20px">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:normal">
                        <input type="checkbox" name="active" value="1" <?php echo ($u['active'] ?? 1) ? 'checked' : ''; ?>>
                        Active
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:normal">
                        <input type="checkbox" name="validated" value="1" <?php echo ($u['validated'] ?? 1) ? 'checked' : ''; ?>>
                        Validated
                    </label>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Users" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
