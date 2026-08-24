<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;

/**
 * The view search path asks where applications live, rather than assuming it.
 *
 * `getView()` falls back to searching the application directory when a view is not found
 * anywhere else. It built that path from `INCLUDES`, which describes where the *code*
 * lives; the legacy controller built it from `APPS_PATH`, which is the constant whose
 * purpose is to answer this question.
 *
 * In a stock layout those are the same directory, which is why the difference went
 * unnoticed. They diverge the moment an installation moves its applications, and then the
 * fallback searches a directory that does not exist — silently, because a fallback that
 * finds nothing is indistinguishable from a view that is genuinely absent.
 */
class ApplicationsPathProbe extends Controller
{
    /** No constructor: the decision reads constants and nothing else. */
    public function __construct()
    {
    }

    public static function base(): string
    {
        return static::applicationsBasePath();
    }
}

class ControllerApplicationsPathTest extends TestCase
{
    /**
     * With `APPS_PATH` defined, that is the answer — and a trailing separator is trimmed.
     *
     * Run in a separate process because it has to `define()` a constant, and PHP cannot
     * undefine one: doing it in-process would fix `APPS_PATH` for every test that ran
     * afterwards in the same worker. One isolated test rather than two, since process
     * isolation is the expensive part and both assertions need the same constant.
     *
     * The trailing separator matters because `APPS_PATH` is written by hand in an
     * application's bootstrap, so both spellings arrive. `/apps//myapp` resolves fine on
     * Linux and is the kind of thing that turns up in an error message years later.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testAppsPathIsUsedWhenDefinedAndTrimmed(): void
    {
        // Arrange
        if (!defined('APPS_PATH')) {
            define('APPS_PATH', DIRECTORY_SEPARATOR . 'somewhere' . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR);
        }

        // Act
        $base = ApplicationsPathProbe::base();

        // Assert
        $this->assertSame(
            DIRECTORY_SEPARATOR . 'somewhere' . DIRECTORY_SEPARATOR . 'apps',
            $base,
            'APPS_PATH is the constant that answers where applications live'
        );
        $this->assertStringEndsNotWith(DIRECTORY_SEPARATOR, $base);
    }

    /**
     * The fallback is still the expression it always was.
     *
     * Read out of the source, because the branch is only reachable when `APPS_PATH` is
     * undefined and PHP cannot undefine a constant. What this guards is the promise made
     * to every installation that defines no `APPS_PATH`: it searches exactly where it
     * searched before, and a refactor that "tidied" the fallback into something else
     * would move their views out from under them.
     */
    public function testTheFallbackIsUnchanged(): void
    {
        // Arrange
        $method = new \ReflectionMethod(Controller::class, 'applicationsBasePath');
        $file   = file($method->getFileName());
        $body   = implode('', array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        // Act & Assert
        $this->assertStringContainsString('ROOT . DS . INCLUDES', $body,
            'an installation without APPS_PATH must keep searching where it always did');
    }
}
