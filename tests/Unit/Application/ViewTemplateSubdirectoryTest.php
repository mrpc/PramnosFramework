<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\View;

/**
 * A view can declare that its templates live one directory down.
 *
 * The framework's convention is flat — `views/<module>/<tpl>.<type>.php` — and it is what
 * the reference application and all three scaffolded themes use. This property exists for
 * an application migrating off the legacy view layer, whose views built
 * `<path>/tpl/<tpl>.<type>.php`; one such application has 820 templates in 131 `tpl/`
 * directories.
 *
 * It is a declaration rather than a search on purpose, and that is what these tests pin:
 * a view that does not set it must build exactly the path it always built, with no extra
 * filesystem call on the way.
 */
class ViewTemplateSubdirectoryProbe extends View
{
    /** No constructor: the path is all this decision reads. */
    public function __construct(string $path, string $subdirectory = '')
    {
        $this->path            = $path;
        $this->tplSubdirectory = $subdirectory;
    }

    public function directory(): string
    {
        return $this->templateDirectory();
    }
}

class ViewTemplateSubdirectoryTest extends TestCase
{
    /**
     * Without the property, the directory is the view's path, unchanged.
     *
     * The backwards-compatibility guarantee, and the one that matters most: every
     * existing view in every application is this view.
     */
    public function testWithoutThePropertyThePathIsUnchanged(): void
    {
        // Arrange
        $view = new ViewTemplateSubdirectoryProbe('/app/views/device');

        // Act & Assert
        $this->assertSame('/app/views/device', $view->directory());
    }

    /**
     * Declaring a subdirectory appends it.
     */
    public function testASubdirectoryIsAppended(): void
    {
        // Arrange
        $view = new ViewTemplateSubdirectoryProbe('/app/views/device', 'tpl');

        // Act & Assert
        $this->assertSame('/app/views/device' . DIRECTORY_SEPARATOR . 'tpl', $view->directory());
    }

    /**
     * An empty string is not a subdirectory.
     *
     * `''` must not become a trailing separator: `/app/views/device/` and
     * `/app/views/device` resolve the same on most filesystems, which is exactly why a
     * bug here would survive every test that only checks whether the file loads.
     */
    public function testAnEmptySubdirectoryAddsNothing(): void
    {
        // Arrange
        $view = new ViewTemplateSubdirectoryProbe('/app/views/device', '');

        // Act & Assert
        $this->assertSame('/app/views/device', $view->directory());
        $this->assertStringEndsNotWith(DIRECTORY_SEPARATOR, $view->directory());
    }

    /**
     * The default declared on the class is empty.
     *
     * Asserted against the class rather than an instance, so that changing the default —
     * which would silently relocate every template in every application — fails here
     * rather than in somebody's deploy.
     */
    public function testTheDeclaredDefaultIsEmpty(): void
    {
        // Arrange
        $defaults = (new \ReflectionClass(View::class))->getDefaultProperties();

        // Act & Assert
        $this->assertSame('', $defaults['tplSubdirectory']);
    }
}
