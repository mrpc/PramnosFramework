<?php
/**
 * OAuth2 authorization consent screen (plain-CSS theme).
 *
 * Variables:
 *   $this->application     — Application object (name, logourl, termsurl, privacyurl)
 *   $this->user            — Current user (username, email, avatar)
 *   $this->allScopes       — array<category, array<scope, {description, is_default, inherits}>>
 *   $this->requestedScopes — string[] of requested scope identifiers
 * Query params:
 *   client_id, redirect_uri, response_type, state, scope
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:80vh;padding:20px">
    <div class="card" style="width:100%;max-width:480px">
        <div class="card-body" style="padding:28px">

            <div style="text-align:center;margin-bottom:16px">
                <?php if (!empty($this->application->logourl)): ?>
                    <img src="<?php echo htmlspecialchars($this->application->logourl); ?>"
                         alt="<?php echo htmlspecialchars($this->application->name ?? ''); ?>"
                         style="max-height:56px;max-width:56px;border-radius:8px;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto">
                <?php endif; ?>
                <h2 style="margin:0;font-size:1.3rem">Authorize <?php echo htmlspecialchars($this->application->name ?? ($_GET['client_id'] ?? 'Application')); ?></h2>
            </div>

            <div style="display:flex;align-items:center;border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:16px">
                <img src="<?php echo htmlspecialchars($this->user->avatar ?? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($this->user->email ?? '')))); ?>"
                     alt="Avatar" style="width:36px;height:36px;border-radius:50%;margin-right:10px">
                <div>
                    <div style="font-weight:500;font-size:14px"><?php echo htmlspecialchars($this->user->username ?? ''); ?></div>
                    <div style="color:#666;font-size:12px"><?php echo htmlspecialchars($this->user->email ?? ''); ?></div>
                </div>
            </div>

            <div style="background:#f8f8f8;border-radius:6px;padding:14px;margin-bottom:16px">
                <p style="font-weight:600;font-size:13px;margin:0 0 10px">This application will be able to:</p>
                <?php foreach ($this->allScopes as $category => $scopesInCategory): ?>
                    <?php $requested = array_intersect(array_keys($scopesInCategory), $this->requestedScopes); ?>
                    <?php if (empty($requested)) continue; ?>
                    <details style="margin-bottom:8px">
                        <summary style="cursor:pointer;font-size:13px;font-weight:500">
                            <span style="color:#28a745;margin-right:4px">&#10003;</span><?php echo htmlspecialchars($category); ?>
                        </summary>
                        <ul style="margin:6px 0 0 20px;padding:0;list-style:disc">
                            <?php foreach ($requested as $scope): ?>
                                <li style="font-size:12px;color:#555;margin-bottom:4px"><?php echo htmlspecialchars($scopesInCategory[$scope]['description']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($_GET['client_id'] ?? ''); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?>">
                <input type="hidden" name="response_type" value="<?php echo htmlspecialchars($_GET['response_type'] ?? ''); ?>">
                <input type="hidden" name="state" value="<?php echo htmlspecialchars($_GET['state'] ?? ''); ?>">
                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($_GET['scope'] ?? ''); ?>">
                <div style="display:flex;gap:10px">
                    <button type="submit" name="authorize" value="no" class="btn" style="flex:1;background:#f0f0f0;color:#333">Deny</button>
                    <button type="submit" name="authorize" value="yes" class="btn" style="flex:1">Allow</button>
                </div>
            </form>

            <p style="font-size:11px;color:#888;text-align:center;margin:12px 0 0">
                <?php if (!empty($this->application->termsurl) || !empty($this->application->privacyurl)): ?>
                    By allowing you agree to the
                    <?php if (!empty($this->application->termsurl)): ?>
                        <a href="<?php echo htmlspecialchars($this->application->termsurl); ?>" target="_blank">Terms of Service</a>
                    <?php endif; ?>
                    <?php if (!empty($this->application->termsurl) && !empty($this->application->privacyurl)): ?> and <?php endif; ?>
                    <?php if (!empty($this->application->privacyurl)): ?>
                        <a href="<?php echo htmlspecialchars($this->application->privacyurl); ?>" target="_blank">Privacy Policy</a>
                    <?php endif; ?>.
                <?php else: ?>
                    By allowing, you grant this application access to your data.
                <?php endif; ?>
            </p>
            <p style="font-size:11px;color:#888;text-align:center;margin:4px 0 0">
                Redirect: <code style="font-size:10px;word-break:break-all"><?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?></code>
            </p>
            <p style="text-align:center;margin:8px 0 0">
                <a href="<?php echo sURL; ?>logout?redirect_uri=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="font-size:12px">Use a different account</a>
            </p>
        </div>
    </div>
</div>
