<?php
/**
 * Auth server home/landing page (Tailwind theme).
 *
 * Variables:
 *   $this->serviceInfo — array{service_name, endpoints[]} (optional)
 */
$serviceName = htmlspecialchars($this->serviceInfo['service_name'] ?? 'OAuth2 Authentication Server');
?>
<div class="container mx-auto px-4 py-10 max-w-4xl">

    <div class="text-center mb-12">
        <h1 class="text-4xl font-bold mb-3"><?php echo $serviceName; ?></h1>
        <p class="text-base-content/70 text-lg mb-6">Secure, standards-compliant OAuth2 authentication and single sign-on.</p>
        <div class="flex justify-center gap-3">
            <a href="<?php echo sURL; ?>login" class="btn btn-primary">Sign In</a>
            <a href="<?php echo sURL; ?>register" class="btn btn-outline">Create Account</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
        <?php
        $features = [
            ['icon' => '&#128274;', 'title' => 'Authorization Code Flow', 'desc' => 'Standard three-legged OAuth2 with PKCE support for public clients.'],
            ['icon' => '&#128241;', 'title' => 'Device Authorization', 'desc' => 'Authorize input-constrained devices from a secondary browser (RFC 8628).'],
            ['icon' => '&#128273;', 'title' => 'JWT Client Assertion', 'desc' => 'High-security client authentication using signed JWT assertions (RFC 7523).'],
            ['icon' => '&#128257;', 'title' => 'Token Refresh & Exchange', 'desc' => 'Seamless token renewal and long-lived exchange flows.'],
            ['icon' => '&#127760;', 'title' => 'Single Sign-On', 'desc' => 'Sign in once and access all connected applications.'],
            ['icon' => '&#128203;', 'title' => 'Scope Management', 'desc' => 'Fine-grained access control with customizable scope definitions.'],
        ];
        foreach ($features as $f): ?>
        <div class="flex gap-4">
            <div class="text-3xl leading-none"><?php echo $f['icon']; ?></div>
            <div>
                <h3 class="font-semibold text-sm mb-1"><?php echo $f['title']; ?></h3>
                <p class="text-base-content/70 text-xs"><?php echo $f['desc']; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($this->serviceInfo['endpoints'])): ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs overflow-hidden">
        <div class="px-6 py-3 border-b border-base-300 font-semibold text-sm text-base-content">OAuth2 Endpoints</div>
        <table class="table table-sm text-sm">
            <tbody class="divide-y divide-base-200">
            <?php foreach ($this->serviceInfo['endpoints'] as $name => $url): ?>
                <tr>
                    <td class="text-xs"><?php echo htmlspecialchars(ucfirst($name)); ?></td>
                    <td class="px-6 py-3"><code class="text-xs text-base-content"><?php echo htmlspecialchars($url); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
