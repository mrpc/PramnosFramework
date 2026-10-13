<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;

/**
 * A model inside a JSON response encodes as its data.
 *
 * `json_encode()` walked a model's public properties, and `$controller` leads to the
 * application and back: "Recursion detected", `false`, and a 500 from every API response that
 * carried a model one level down — a ticket's departments, a user's package.
 */
class ModelEncodesAsItsDataTest extends TestCase
{
    /**
     * A nested model with a controller encodes, as its columns and nothing else.
     */
    public function testANestedModelWithAControllerEncodesAsItsData(): void
    {
        // Arrange — a controller whose public state leads back to the model, a cycle
        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $package             = new Model($controller, 'Package');
        $controller->actions = [$package];
        $package->packageid  = 7;
        $package->name       = 'Gold';

        // Act
        $json = json_encode(['user' => ['subscription' => ['model' => $package]]]);

        // Assert
        $this->assertNotFalse($json, json_last_error_msg());
        $decoded = json_decode($json, true)['user']['subscription']['model'];
        $this->assertSame(7, $decoded['packageid']);
        $this->assertSame('Gold', $decoded['name']);
        $this->assertArrayNotHasKey('controller', $decoded, 'internals stay out');
    }
}
