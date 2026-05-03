<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;

/**
 * Characterization tests for Model state/change-tracking behavior.
 *
 * These tests lock non-obvious contracts (prefix substitution, cache-key shape,
 * data export filtering, and numeric/non-numeric change semantics).
 */
#[CoversClass(Model::class)]
class ModelCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        // Arrange
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();
    }

    /**
     * Ensures __init replaces #THISPREFIX# with "<prefix>_" exactly once.
     */
    public function testInitReplacesThisPrefixPlaceholder(): void
    {
        // Arrange
        $model = $this->makeModelStub();
        $model->prefix = 'demo';
        $model->setDbTableForTest('#THISPREFIX#records');

        // Act
        $model->__init();

        // Assert
        $this->assertSame('demo_records', $model->getDbTableForTest());
    }

    /**
     * Ensures specific cache key format stays "<primary>-<cacheKey>".
     */
    public function testGenerateSpecificCacheKeyUsesPrimaryDashCacheKeyFormat(): void
    {
        // Arrange
        $model = $this->makeModelStub();
        $model->setDbTableForTest('#PREFIX#records');

        // Act
        $cacheKey = $model->generateSpecificCacheKeyForTest(15);

        // Assert
        // Current behavior: unresolved #PREFIX# token is retained in _cacheKey.
        $this->assertSame('15-#PREFIX#records', $cacheKey);
    }

    /**
     * Ensures getChanges keeps numeric loose-comparison semantics but strict
     * comparison for non-numeric values.
     */
    public function testGetChangesNumericLooseAndStringStrictComparisonContract(): void
    {
        // Arrange
        $model = $this->makeModelStub();
        $model->setIsNewForTest(false);
        $model->setInitialDataForTest([
            'count' => '1',
            'status' => 'active',
        ]);
        $model->count = 1;
        $model->status = 'inactive';

        // Act
        $changes = $model->getChanges();

        // Assert
        // This proves numeric string/int values are treated as unchanged when numerically equal.
        $this->assertArrayNotHasKey('count', $changes);
        $this->assertArrayHasKey('status', $changes);
        $this->assertSame('active', $changes['status']['old']);
        $this->assertSame('inactive', $changes['status']['new']);
    }

    /**
     * Ensures getData excludes internal model metadata fields.
     */
    public function testGetDataExcludesInternalModelMetadataFields(): void
    {
        // Arrange
        $model = $this->makeModelStub();
        $model->publicText = 'value';
        $model->publicNumber = 42;

        // Act
        $data = $model->getData();

        // Assert
        $this->assertSame('value', $data['publicText']);
        $this->assertSame(42, $data['publicNumber']);
        $this->assertArrayNotHasKey('_primaryKey', $data);
        $this->assertArrayNotHasKey('_dbtable', $data);
        $this->assertArrayNotHasKey('prefix', $data);
        $this->assertArrayNotHasKey('modelname', $data);
    }

    private function makeModelStub(): ModelCharacterizationStub
    {
        // Arrange
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $controller */
        $controller = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new ModelCharacterizationStub($controller, 'Record');
    }
}

/**
 * Small helper subclass exposing protected state for characterization tests.
 */
class ModelCharacterizationStub extends Model
{
    public int $count = 0;
    public string $status = '';
    public string $publicText = '';
    public int $publicNumber = 0;

    public function setDbTableForTest(string $table): void
    {
        $this->_dbtable = $table;
    }

    public function getDbTableForTest(): string
    {
        return (string) $this->_dbtable;
    }

    public function setInitialDataForTest(array $data): void
    {
        $this->_initialData = $data;
    }

    public function setIsNewForTest(bool $isNew): void
    {
        $this->_isnew = $isNew;
    }

    public function generateSpecificCacheKeyForTest(int $primary): string
    {
        return (string) $this->_generateSpecificCacheKey($primary);
    }
}
