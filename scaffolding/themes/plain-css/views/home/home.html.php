<?php
/**
 * Auth server home/landing page (plain-CSS theme).
 *
 * Variables:
 *   $this->serviceInfo — array{service_name, endpoints[]} (optional)
 */
$serviceName = htmlspecialchars($this->serviceInfo['service_name'] ?? 'OAuth2 Authentication Server');
?>
<div class="page-section">

    <div style="text-align:center;padding:40px 0 32px">
        <h1 style="font-size:2rem;margin:0 0 8px"><?php echo $serviceName; ?></h1>
        <p style="color:#666;font-size:1.1rem;margin:0 0 20px">Secure, standards-compliant OAuth2 authentication and single sign-on.</p>
        <a href="<?php echo sURL; ?>Home/login" class="btn" style="margin-right:10px">Sign In</a>
        <a href="<?php echo sURL; ?>Home/register" class="btn" style="background:#f0f0f0;color:#333">Create Account</a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:40px">
        <?php
        $features = [
            ['icon' => '&#128274;', 'title' => 'Authorization Code Flow', 'desc' => 'Standard three-legged OAuth2 with PKCE support for public clients.'],
            ['icon' => '&#128241;', 'title' => 'Device Authorization (RFC 8628)', 'desc' => 'Authorize input-constrained devices from a secondary browser.'],
            ['icon' => '&#128273;', 'title' => 'JWT Client Assertion (RFC 7523)', 'desc' => 'High-security client authentication using signed JWT assertions.'],
            ['icon' => '&#128257;', 'title' => 'Token Refresh & Exchange', 'desc' => 'Seamless token renewal and long-lived exchange flows.'],
            ['icon' => '&#127760;', 'title' => 'Single Sign-On', 'desc' => 'Sign in once and access all connected applications.'],
            ['icon' => '&#128203;', 'title' => 'Scope Management', 'desc' => 'Fine-grained access control with customizable scope definitions.'],
        ];
        foreach ($features as $f): ?>
        <div style="display:flex;align-items:flex-start;gap:14px">
            <div style="font-size:2rem;line-height:1"><?php echo $f['icon']; ?></div>
            <div>
                <h4 style="margin:0 0 4px;font-size:1rem"><?php echo $f['title']; ?></h4>
                <p style="margin:0;color:#666;font-size:13px"><?php echo $f['desc']; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($this->serviceInfo['endpoints'])): ?>
    <div class="card">
        <div class="card-header"><strong>OAuth2 Endpoints</strong></div>
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
