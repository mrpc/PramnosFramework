<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\OrmModel;

/**
 * The ORM guide may only show calls `OrmModel` can answer.
 *
 * WHAT: that no example in `docs/Pramnos_ORM_Guide.md` calls a static method on a model
 *       which the class does not declare.
 * WHY:  `docs/` is not export-ignored — it ships inside the composer package and sits in
 *       every project's `vendor/`, where it is what somebody (or an assistant) reads to
 *       learn the API. For most of this page's life it showed `User::find(42)`,
 *       `User::where(…)->first()`, `User::all()` and `User::create([...])`, none of which
 *       exists: `OrmModel` declares no `__callStatic` and none of its traits add one. A
 *       method written from the guide died with `Call to undefined method` — at runtime,
 *       in whatever path first reached it.
 *
 *       Wrong documentation is worse than missing documentation, because it is *followed*.
 *       This is the cheapest possible check that it stays followable.
 */
#[CoversNothing]
class OrmGuideMatchesTheClassTest extends TestCase
{
    /**
     * Every `Model::method(` in the guide is a static the class really has.
     *
     * Read out of the fenced code blocks only: the prose deliberately names
     * `User::find(42)` while explaining that it does not exist, and a sweep that could not
     * tell the two apart would forbid the warning as well as the mistake.
     */
    public function testNoExampleCallsAStaticTheModelDoesNotHave(): void
    {
        // Arrange
        $guide = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docs/Pramnos_ORM_Guide.md'
        );

        $available = [];
        // A sweep over nothing passes. The collection is asserted non-empty first: a
        // registry that failed to populate, or a helper that quietly returns [], turns
        // this guard into a no-op that still reports success.
        $this->assertNotEmpty((new \ReflectionClass(OrmModel::class))->getMethods(), 'the sweep found nothing to check');
        foreach ((new \ReflectionClass(OrmModel::class))->getMethods() as $method) {
            if ($method->isStatic()) {
                $available[] = $method->getName();
            }
        }
        // A `__callStatic` would make anything legal, so its presence ends this test's
        // subject rather than failing it.
        if (method_exists(OrmModel::class, '__callStatic')) {
            $this->markTestSkipped('OrmModel forwards unknown statics; any name is answerable.');
        }

        // Act — the code fences, then every `Something::method(` inside them.
        $offenders = [];
        preg_match_all('/```php\n(.*?)```/s', $guide, $blocks);
        foreach ($blocks[1] as $block) {
            // Comments carry the same prose problem as the body text.
            $block = (string) preg_replace('~^\s*//.*$~m', '', $block);

            if (!preg_match_all('/\b([A-Z]\w*)::(\w+)\s*\(/', $block, $calls, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($calls as [$call, $class, $method]) {
                // Only calls on a *model*. The guide also shows framework classes, which
                // have their own statics and are not this test's business.
                if (!in_array($class, ['User', 'Post', 'Comment', 'Product', 'Order'], true)) {
                    continue;
                }
                if (!in_array($method, $available, true)) {
                    $offenders[] = $call . ')';
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "the ORM guide's examples call statics OrmModel does not declare:\n"
            . implode("\n", array_unique($offenders))
            . "\nEither implement them or write the examples against the instance API."
        );
    }
}
