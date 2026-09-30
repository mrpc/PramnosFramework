<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Groups;

/**
 * `/admin/Groups` resolves to the framework's screen in a project that has none of its own.
 *
 * The screen is not scaffolded, so a project that enables the feature later has it at once —
 * which holds only while the application's fallback finds it. That is asserted here through
 * `getController()` itself, rather than inferred from the file existing.
 */
class GroupsScreenResolvesTest extends TestCase
{
    /**
     * With no `Groups` of the application's own, inside the admin area, the fallback
     * answers with the framework's screen.
     */
    public function testTheFallbackFindsTheScreen(): void
    {
        // Arrange — an application whose namespace has no Groups controller.
        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $app->applicationInfo = ['namespace' => 'NoSuchAppNamespace'];
        (new \ReflectionProperty(Application::class, 'area'))->setValue($app, 'Admin');

        // Act
        $controller = $app->getController('groups');

        // Assert
        $this->assertInstanceOf(Groups::class, $controller);
    }
}
