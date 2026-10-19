<?php
/**
 * Auth server home/landing page (plain-CSS theme).
 *
 * Variables:
 *   $this->serviceInfo — array{service_name, endpoints[]} (optional)
 */
$serviceName = htmlspecialchars($this->serviceInfo['service_name'] ?? t('OAuth2 Authentication Server'), ENT_QUOTES, 'UTF-8');
?>
<div class="page-section">

    <div style="text-align:center;padding:40px 0 32px">
        <h1 style="font-size:2rem;margin:0 0 8px"><?php echo $serviceName; ?></h1>
        <p style="color:#666;font-size:1.1rem;margin:0 0 20px"><?php echo htmlspecialchars(t('Secure, standards-compliant OAuth2 authentication and single sign-on.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <a href="<?php echo sURL; ?>login" class="btn" style="margin-right:10px"><?php echo htmlspecialchars(t('Sign In'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a href="<?php echo sURL; ?>register" class="btn" style="background:#f0f0f0;color:#333"><?php echo htmlspecialchars(t('Create Account'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:40px">
        <?php
        $features = [
            ['icon' => '&#128274;', 'title' => t('Authorization Code Flow'), 'desc' => t('Standard three-legged OAuth2 with PKCE support for public clients.')],
            ['icon' => '&#128241;', 'title' => t('Device Authorization (RFC 8628)'), 'desc' => t('Authorize input-constrained devices from a secondary browser.')],
            ['icon' => '&#128273;', 'title' => t('JWT Client Assertion (RFC 7523)'), 'desc' => t('High-security client authentication using signed JWT assertions.')],
            ['icon' => '&#128257;', 'title' => t('Token Refresh & Exchange'), 'desc' => t('Seamless token renewal and long-lived exchange flows.')],
            ['icon' => '&#127760;', 'title' => t('Single Sign-On'), 'desc' => t('Sign in once and access all connected applications.')],
            ['icon' => '&#128203;', 'title' => t('Scope Management'), 'desc' => t('Fine-grained access control with customizable scope definitions.')],
        ];
        foreach ($features as $f): ?>
        <div style="display:flex;align-items:flex-start;gap:14px">
            <div style="font-size:2rem;line-height:1"><?php echo $f['icon']; ?></div>
            <div>
                <h4 style="margin:0 0 4px;font-size:1rem"><?php echo htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <p style="margin:0;color:#666;font-size:13px"><?php echo htmlspecialchars($f['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($this->serviceInfo['endpoints'])): ?>
    <div class="card">
        <div class="card-header"><strong><?php echo htmlspecialchars(t('OAuth2 Endpoints'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="card-body" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <?php foreach ($this->serviceInfo['endpoints'] as $name => $url): ?>
                <tr style="border-bottom:1px solid #f0f0f0">
                    <td style="padding:8px 16px;font-weight:500;white-space:nowrap;width:180px"><?php echo htmlspecialchars(ucfirst($name)); ?></td>
                    <td style="padding:8px 16px"><code style="font-size:13px"><?php echo htmlspecialchars($url); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>
