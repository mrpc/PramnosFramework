<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Impersonation;

/**
 * `display()` itself (both branches) is already exercised end to end by
 * {@see \Pramnos\Tests\Integration\Auth\ImpersonationTest} — but through an anonymous subclass
 * that overrides `__construct()` to skip the parent entirely, so the real constructor
 * (`addWriteAction(['display'])`, `parent::__construct()`) never ran under any test.
 *
 * A `POST`-only action this controller never marked as one would accept a plain `GET`, the same
 * CSRF gap {@see \Pramnos\Application\Controller::addWriteAction()} exists to close — worth its
 * own assertion rather than trusting it ran as a side effect of another test's setup.
 */
#[CoversClass(Impersonation::class)]
class ImpersonationControllerConstructTest extends TestCase
{
    /** `display` is registered as a write action, so a bare GET cannot reach it. */
    public function testDisplayIsRegisteredAsAWriteAction(): void
    {
        // Arrange / Act
        $controller = new Impersonation();

        // Assert
        $property = new \ReflectionProperty($controller, 'writeActions');
        $this->assertSame(['display'], $property->getValue($controller));
    }
}
