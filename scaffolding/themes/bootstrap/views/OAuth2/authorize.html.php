<?php
/**
 * OAuth2 authorization consent screen (Bootstrap theme).
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <?php $this->insert('../partials/auth_brand'); ?>

                    <div class="text-center mb-3">
                        <?php if (!empty($this->application->logourl)): ?>
                            <img src="<?php echo htmlspecialchars($this->application->logourl); ?>"
                                 alt="<?php echo htmlspecialchars($this->application->name ?? ''); ?>"
                                 style="max-height:64px;max-width:64px;border-radius:8px" class="mb-2">
                        <?php endif; ?>
                        <h1 class="h4 mb-0"><?php echo htmlspecialchars(t('Authorize %s', (string) ($this->application->name ?? ($_GET['client_id'] ?? t('Application'))))); ?></h1>
                    </div>

                    <div class="d-flex align-items-center border rounded p-2 mb-3">
                        <?php /*
                             The account's own avatar, or nothing.
                        
                             This used to fall back to Gravatar, which sent `md5(email)` of the person signing
                             in to a third party on every render of the consent screen — from the one page in
                             the flow where they are deciding what to disclose, and to a party they were never
                             asked about. An md5 of an address is not anonymous; it is the address, hashed.
                        */ ?>
                        <?php if (!empty($this->user->avatar)): ?>
                        <img src="<?php echo htmlspecialchars($this->user->avatar); ?>"
                             alt="<?php echo htmlspecialchars(t('Avatar')); ?>" class="rounded-circle me-2" width="36" height="36">
                        <?php endif; ?>
                        <div>
                            <div class="fw-semibold small"><?php echo htmlspecialchars($this->user->username ?? ''); ?></div>
                            <div class="text-muted small"><?php echo htmlspecialchars($this->user->email ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="bg-light rounded p-3 mb-3">
                        <p class="fw-semibold small mb-2"><?php echo htmlspecialchars(t('This application will be able to:')); ?></p>
                        <?php foreach ($this->allScopes as $category => $scopesInCategory): ?>
                            <?php $requested = array_intersect(array_keys($scopesInCategory), $this->requestedScopes); ?>
                            <?php if (empty($requested)) continue; ?>
                            <details open class="mb-2">
                                <summary class="small fw-medium" style="cursor:pointer">
                                    <span class="text-success me-1">&#10003;</span><?php echo htmlspecialchars(t((string) $category)); ?>
                                </summary>
                                <ul class="list-unstyled ps-3 mt-1 mb-0">
                                    <?php foreach ($requested as $scope): ?>
                                        <li class="small text-muted">
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
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" name="authorize" value="no" class="btn btn-outline-secondary flex-fill"><?php echo htmlspecialchars(t('Deny')); ?></button>
                            <button type="submit" name="authorize" value="yes" class="btn btn-primary flex-fill"><?php echo htmlspecialchars(t('Allow')); ?></button>
                        </div>
                    </form>

                    <p class="text-muted small text-center mt-3 mb-0">
                        <?php if (!empty($this->application->termsurl) || !empty($this->application->privacyurl)): ?>
                            <?php
                            // One sentence per combination, so a translation never stitches fragments.
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
                                ? t('By allowing you agree to the %s and %s.')
                                : t('By allowing you agree to the %s.');
                            echo vsprintf(htmlspecialchars($consentSentence, ENT_QUOTES, 'UTF-8'), $consentLinks);
                            ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars(t('By allowing, you grant this application access to your data.')); ?>
                        <?php endif; ?>
                    </p>
                    <p class="text-muted small text-center mt-2 mb-0">
                        <?php echo htmlspecialchars(t('Redirecting to:')); ?> <code class="small"><?php echo htmlspecialchars($_GET['redirect_uri'] ?? ''); ?></code>
                    </p>
                    <p class="text-center mt-2 mb-0">
                        <a href="<?php echo sURL; ?>login/logout?redirect_uri=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="small"><?php echo htmlspecialchars(t('Use a different account')); ?></a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
