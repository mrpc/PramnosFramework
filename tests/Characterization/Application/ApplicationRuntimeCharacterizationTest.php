<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Html\Breadcrumb;

/**
 * Characterization tests for deterministic Application runtime helpers.
 *
 * Scope: redirect handling, breadcrumbs, controller metadata, start-page flag,
 * extra paths, and maintenance mode file toggles.
 */
#[CoversClass(Application::class)]
class ApplicationRuntimeCharacterizationTest extends TestCase
{
    protected function tearDown(): void
    {
        // Arrange/Act cleanup
        $maintenanceFile = ROOT . DS . 'var' . DS . 'MAINTENANCE';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }
    }

    /**
     * Create Application object without running constructor side effects.
     */
    private function makeAppStub(): Application
    {
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        $breadcrumbsProp = new \ReflectionProperty($app, 'breadcrumbs');
        $breadcrumbsProp->setValue($app, new Breadcrumb());

        return $app;
    }

    /**
     * redirect(null) returns false when no redirect target exists.
     */
    public function testRedirectReturnsFalseWhenNoTargetIsSet(): void
    {
        // Arrange
        $app = $this->makeAppStub();

        // Act
        ob_start();
        $result = $app->redirect(null, false);
        $output = ob_get_clean();

        // Assert
        $this->assertFalse($result);
        $this->assertSame('', $output);
    }

    /**
     * setRedirect() is consumed by redirect(null) and emits redirect script/html.
     */
    public function testSetRedirectIsUsedByRedirectWhenUrlArgumentIsNull(): void
    {
        // Arrange
        $app = $this->makeAppStub();
        $app->setRedirect('/target/path');

        // Act
        ob_start();
        $result = $app->redirect(null, false, '302');
        $output = (string) ob_get_clean();

        // Assert
        $this->assertTrue($result);
        $this->assertStringContainsString('/target/path', $output);
        $this->assertStringContainsString('Redirecting.', $output);
    }

    /**
     * addbreadcrumb() returns $this and renderBreadcrumbs() includes labels.
     */
    public function testBreadcrumbRoundTrip(): void
    {
        // Arrange
        $app = $this->makeAppStub();

        // Act
        $result = $app->addbreadcrumb('Home', '/', 'Homepage')
            ->addbreadcrumb('Section', '/section', 'Section title');
        $html = $app->renderBreadcrumbs();

        // Assert
        $this->assertSame($app, $result);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Section', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
    }

    /**
     * setControllerInfo/getControllerInfo keep the same payload.
     */
    public function testSetAndGetControllerInfo(): void
    {
        // Arrange
        $app = $this->makeAppStub();
        $info = ['type' => 'page', 'title' => 'Dashboard', 'id' => 7];

        // Act
        $app->setControllerInfo($info);
        $actual = $app->getControllerInfo();

        // Assert
        $this->assertSame($info, $actual);
    }

    /**
     * setStartPage()/isStartPage() toggle current start-page state.
     */
    public function testStartPageFlagCanBeToggled(): void
    {
        // Arrange
        $app = $this->makeAppStub();

        // Act
        $app->setStartPage(false);
        $afterFalse = $app->isStartPage();
        $app->setStartPage(true);
        $afterTrue = $app->isStartPage();

        // Assert
        $this->assertFalse($afterFalse);
        $this->assertTrue($afterTrue);
    }

    /**
     * addExtraPath() stores unique keys and getExtraPaths() returns map.
     */
    public function testAddExtraPathStoresUniqueEntries(): void
    {
        // Arrange
        $app = $this->makeAppStub();

        // Act
        $result = $app->addExtraPath('/tmp/views')
            ->addExtraPath('/tmp/models')
            ->addExtraPath('/tmp/views'); // duplicate key overwrite, no duplicate count
        $paths = $app->getExtraPaths();

        // Assert
        $this->assertSame($app, $result);
        $this->assertCount(2, $paths);
        $this->assertArrayHasKey('/tmp/views', $paths);
        $this->assertArrayHasKey('/tmp/models', $paths);
    }

    /**
     * startMaintenance() creates MAINTENANCE file and stopMaintenance() removes it.
     */
    public function testMaintenanceFileLifecycle(): void
    {
        // Arrange
        $app = $this->makeAppStub();
        $maintenanceFile = ROOT . DS . 'var' . DS . 'MAINTENANCE';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }

        // Act
        $app->startMaintenance('characterization test');
        $existsAfterStart = file_exists($maintenanceFile);
        $content = $existsAfterStart ? (string) file_get_contents($maintenanceFile) : '';
        $app->stopMaintenance();
        $existsAfterStop = file_exists($maintenanceFile);

        // Assert
        $this->assertTrue($existsAfterStart);
        $this->assertStringContainsString('characterization test', $content);
        $this->assertFalse($existsAfterStop);
    }
}
