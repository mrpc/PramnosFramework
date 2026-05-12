<?php
/**
 * User profile page (plain-CSS theme).
 *
 * Variables:
 *   $this->title — Page title
 *   $this->user  — User object (username, email, regdate, last_login)
 */
?>
<div class="page-section">
    <h2 style="margin-bottom:20px"><?php echo htmlspecialchars($this->title ?? 'My Profile'); ?></h2>
    <div class="card">
        <div class="card-body" style="padding:20px">
            <table style="width:100%;border-collapse:collapse">
                <tr style="border-bottom:1px solid #f0f0f0">
                    <th style="text-align:left;padding:10px 16px;width:160px;font-weight:600;color:#444">Username</th>
                    <td style="padding:10px 16px"><?php echo htmlspecialchars($this->user->username ?? ''); ?></td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0">
                    <th style="text-align:left;padding:10px 16px;font-weight:600;color:#444">Email</th>
                    <td style="padding:10px 16px"><?php echo htmlspecialchars($this->user->email ?? ''); ?></td>
                </tr>
                <?php if (!empty($this->user->regdate)): ?>
                <tr style="border-bottom:1px solid #f0f0f0">
                    <th style="text-align:left;padding:10px 16px;font-weight:600;color:#444">Member Since</th>
                    <td style="padding:10px 16px"><?php echo htmlspecialchars(date('Y-m-d', is_numeric($this->user->regdate) ? $this->user->regdate : strtotime($this->user->regdate))); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($this->user->last_login)): ?>
                <tr>
                    <th style="text-align:left;padding:10px 16px;font-weight:600;color:#444">Last Login</th>
                    <td style="padding:10px 16px"><?php echo htmlspecialchars($this->user->last_login); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <div style="margin-top:16px">
        <a href="<?php echo sURL; ?>Dashboard" class="btn btn-sm">&larr; Back to Dashboard</a>
    </div>
</div>
