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
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="card-body" style="padding:28px">

            <div style="text-align:center;margin-bottom:16px">
                <?php if (!empty($this->application->logourl)): ?>
                    <img src="<?php echo htmlspecialchars($this->application->logourl); ?>"
                         alt="<?php echo htmlspecialchars($this->application->name ?? ''); ?>"
                         style="max-height:56px;max-width:56px;border-radius:8px;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto">
                <?php endif; ?>
                <h2 style="margin:0;font-size:1.3rem"><?php echo htmlspecialchars(t('Authorize %s', (string) ($this->application->name ?? ($_GET['client_id'] ?? t('Application'))))); ?></h2>
            </div>

            <div style="display:flex;align-items:center;border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:16px">
                <?php /*
                     The account's own avatar, or nothing.
                
                     This used to fall back to Gravatar, which sent `md5(email)` of the person signing
                     in to a third party on every render of the consent screen — from the one page in
                     the flow where they are deciding what to disclose, and to a party they were never
                     asked about. An md5 of an address is not anonymous; it is the address, hashed.
                */ ?>
                <?php if (!empty($this->user->avatar)): ?>
                <img src="<?php echo htmlspecialchars($this->user->avatar); ?>"
                     alt="<?php echo htmlspecialchars(t('Avatar')); ?>" style="width:36px;height:36px;border-radius:50%;margin-right:10px">
                <?php endif; ?>
                <div>
                    <div style="font-weight:500;font-size:14px"><?php echo htmlspecialchars($this->user->username ?? ''); ?></div>
                    <div style="color:#666;font-size:12px"><?php echo htmlspecialchars($this->user->email ?? ''); ?></div>
                </div>
            </div>

            <div style="background:#f8f8f8;border-radius:6px;padding:14px;margin-bottom:16px">
                <p style="font-weight:600;font-size:13px;margin:0 0 10px"><?php echo htmlspecialchars(t('This application will be able to:')); ?></p>
                <?php foreach ($this->allScopes as $category => $scopesInCategory): ?>
                    <?php $requested = array_intersect(array_keys($scopesInCategory), $this->requestedScopes); ?>
                    <?php if (empty($requested)) continue; ?>
                    <details open style="margin-bottom:8px">
                        <summary style="cursor:pointer;font-size:13px;font-weight:500">
                            <span style="color:#28a745;margin-right:4px">&#10003;</span><?php echo htmlspecialchars(t((string) $category)); ?>
                        </summary>
                        <ul style="margin:6px 0 0 20px;padding:0;list-style:disc">
                            <?php foreach ($requested as $scope): ?>
                                <li style="font-size:12px;color:#555;margin-bottom:4px">
                                    <?php $locked = in_array($scope, (array) ($this->requiredScopes ?? []), true); ?>
                                    <label style="cursor:<?php echo $locked ? 'default' : 'pointer'; ?>">
                                        <input type="checkbox" form="oauth-consent" name="scopes[]" value="<?php echo htmlspecialchars((string) $scope); ?>" checked<?php echo $locked ? ' disabled' : ''; ?>>
                                        <?php echo htmlspecialchars(t((string) $scopesInCategory[$scope]['description'])); ?>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>

            <form method="POST" id="oauth-consent">
                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($_GET['client_id'] ?? ''); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?>">
                <input type="hidden" name="response_type" value="<?php echo htmlspecialchars($_GET['response_type'] ?? ''); ?>">
                <input type="hidden" name="state" value="<?php echo htmlspecialchars($_GET['state'] ?? ''); ?>">
                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($_GET['scope'] ?? ''); ?>">
                <?php /* The boxes are a choice: without this, a theme's older form that has none grants everything. */ ?>
                <input type="hidden" name="scope_choice" value="1">
                <div style="display:flex;gap:10px">
                    <button type="submit" name="authorize" value="no" class="btn" style="flex:1;background:#f0f0f0;color:#333"><?php echo htmlspecialchars(t('Deny')); ?></button>
                    <button type="submit" name="authorize" value="yes" class="btn" style="flex:1"><?php echo htmlspecialchars(t('Allow')); ?></button>
                </div>
            </form>

            <p style="font-size:11px;color:#888;text-align:center;margin:12px 0 0">
                <?php if (!empty($this->application->termsurl) || !empty($this->application->privacyurl)): ?>
                    <?php
                    // One sentence per case, so a translation can reorder it; the links go in as %s.
                    $consentLinks = [];
                    if (!empty($this->application->termsurl)) {
                        $consentLinks[] = '<a href="' . htmlspecialchars($this->application->termsurl, ENT_QUOTES, 'UTF-8') . '" target="_blank">'
                            . htmlspecialchars(t('Terms of Service'), ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    if (!empty($this->application->privacyurl)) {
                        $consentLinks[] = '<a href="' . htmlspecialchars($this->application->privacyurl, ENT_QUOTES, 'UTF-8') . '" target="_blank">'
                            . htmlspecialchars(t('Privacy Policy'), ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    $consentSentence = count($consentLinks) === 2
                        ? t('By allowing you agree to the %s and the %s.')
                        : t('By allowing you agree to the %s.');
                    echo vsprintf(htmlspecialchars($consentSentence, ENT_QUOTES, 'UTF-8'), $consentLinks);
                    ?>
                <?php else: ?>
                    <?php echo htmlspecialchars(t('By allowing, you grant this application access to your data.')); ?>
                <?php endif; ?>
            </p>
            <p style="font-size:11px;color:#888;text-align:center;margin:4px 0 0">
                <?php echo htmlspecialchars(t('Redirect:')); ?> <code style="font-size:10px;word-break:break-all"><?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?></code>
            </p>
            <p style="text-align:center;margin:8px 0 0">
                <a href="<?php echo sURL; ?>login/logout?redirect_uri=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="font-size:12px"><?php echo htmlspecialchars(t('Use a different account')); ?></a>
            </p>
        </div>
    </div>
</div>
