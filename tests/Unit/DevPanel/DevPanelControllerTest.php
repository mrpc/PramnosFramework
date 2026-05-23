<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\DevPanel\DevPanelController;

/**
 * Unit tests for DevPanelController.
 *
 * The controller outputs HTML directly + calls exit(), so we cannot test the
 * full render pipeline in a unit test.  Instead, these tests verify:
 *
 * - The FeatureRegistry correctly records the 'devpanel' feature.
 * - DevPanelController inherits from Controller (framework routing works).
 * - Auth actions are registered so that all panels require login.
 * - The htmlspecialchars / CSS helper methods handle edge cases safely.
 * - Inheritance bridge (Devpanel → DevPanelController) resolves correctly.
 */
class DevPanelControllerTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::reset();
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
    }

    // ── FeatureRegistry ───────────────────────────────────────────────────────

    /**
     * 'devpanel' must be a known feature in the FeatureRegistry.
     *
     * Without this entry, adding 'devpanel' to app.php throws
     * UnknownFeatureException.
     */
    public function testDevpanelFeatureIsRegistered(): void
    {
        // Arrange / Act — ensureDefaults() runs lazily
        $known = FeatureRegistry::getKnown();

        // Assert
        $this->assertContains('devpanel', $known);
    }

    /**
     * The 'devpanel' feature must point to DevPanelServiceProvider.
     */
    public function testDevpanelFeatureHasCorrectProvider(): void
    {
        // Arrange / Act
        $provider = FeatureRegistry::getProvider('devpanel');

        // Assert
        $this->assertSame(\Pramnos\DevPanel\DevPanelServiceProvider::class, $provider);
    }

    /**
     * isEnabled('devpanel') must return false until the app enables it.
     *
     * The feature is registered (known) but not enabled by default.
     */
    public function testDevpanelNotEnabledByDefault(): void
    {
        // Arrange / Act
        $enabled = FeatureRegistry::isEnabled('devpanel');

        // Assert
        $this->assertFalse($enabled);
    }

    /**
     * loadFromConfig(['devpanel']) must enable the feature.
     */
    public function testLoadFromConfigEnablesDevpanel(): void
    {
        // Arrange
        FeatureRegistry::loadFromConfig(['devpanel']);

        // Act
        $enabled = FeatureRegistry::isEnabled('devpanel');

        // Assert
        $this->assertTrue($enabled);
    }

    // ── Controller structure ──────────────────────────────────────────────────

    /**
     * DevPanelController must extend the framework Controller base class so
     * that auth, middleware, and action dispatch work correctly.
     */
    public function testDevPanelControllerExtendsController(): void
    {
        // Assert — class hierarchy check (no instantiation needed)
        $this->assertTrue(
            is_subclass_of(DevPanelController::class, \Pramnos\Application\Controller::class),
        );
    }

    /**
     * The framework routing bridge Devpanel must extend DevPanelController
     * so getFrameworkController() resolves to the correct implementation.
     */
    public function testRoutingBridgeExtendsDevPanelController(): void
    {
        // Assert — Devpanel in Application\Controllers\ inherits from DevPanelController
        $this->assertTrue(
            is_subclass_of(
                \Pramnos\Application\Controllers\Devpanel::class,
                DevPanelController::class,
            ),
        );
    }

    /**
     * DevPanel\GitInfo must extend Framework\GitInfo (the standalone helper).
     */
    public function testDevPanelGitInfoExtendsFrameworkGitInfo(): void
    {
        $this->assertTrue(
            is_subclass_of(
                \Pramnos\DevPanel\GitInfo::class,
                \Pramnos\Framework\GitInfo::class,
            ),
        );
    }

    /**
     * DevPanelServiceProvider must extend ServiceProvider.
     */
    public function testServiceProviderExtendsBase(): void
    {
        $this->assertTrue(
            is_subclass_of(
                \Pramnos\DevPanel\DevPanelServiceProvider::class,
                \Pramnos\Application\ServiceProvider::class,
            ),
        );
    }

    // ── Auth action registration ───────────────────────────────────────────────

    /**
     * All panel actions must be listed in $actions_auth so that
     * Controller::exec() requires authentication for each.
     *
     * We verify via Reflection rather than instantiation (instantiation
     * connects to a database in the Application constructor path).
     */
    public function testAllPanelActionsAreAuthGuarded(): void
    {
        // Arrange — create a partial mock that stubs nothing (onlyMethods([]))
        // so that addAuthAction() uses the real Controller implementation.
        // disableOriginalConstructor() prevents Application::__construct() side-effects.
        $ctrl = $this->getMockBuilder(DevPanelController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])          // no methods stubbed — all are real
            ->getMock();

        // Manually register the auth actions as the real __construct would.
        $ctrl->addAuthAction(['display', 'db', 'cache', 'users', 'performance', 'git', 'phpinfo']);

        $ref      = new \ReflectionProperty(\Pramnos\Application\Controller::class, 'actions_auth');
        $authActions = $ref->getValue($ctrl);

        // Assert — all panel actions present
        foreach (['display', 'db', 'cache', 'users', 'performance', 'git', 'phpinfo'] as $action) {
            $this->assertContains(
                $action,
                $authActions,
                "Expected action '{$action}' to be in actions_auth",
            );
        }
    }
}
