<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OrganizationScope;

/**
 * What the organisation resolver may answer, and what is refused.
 *
 * The id it returns decides whose roles count in a permission check, so anything that is not
 * plainly an organisation id or null is an error rather than a guess — the callers refuse on
 * an error, where a guess could check the wrong organisation.
 */
#[CoversClass(OrganizationScope::class)]
class OrganizationScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        OrganizationScope::reset();
    }

    /**
     * With no resolver there is no organisation, and nothing is configured.
     */
    public function testWithoutAResolverThereIsNoOrganisation(): void
    {
        // Act & Assert
        $this->assertFalse(OrganizationScope::isConfigured());
        $this->assertNull(OrganizationScope::current());
    }

    /**
     * An int, a numeric string and null are answers; reset forgets the resolver.
     */
    public function testValidAnswers(): void
    {
        // Act & Assert
        OrganizationScope::resolveWith(fn () => 7);
        $this->assertTrue(OrganizationScope::isConfigured());
        $this->assertSame(7, OrganizationScope::current());

        OrganizationScope::resolveWith(fn () => '12');
        $this->assertSame(12, OrganizationScope::current(), 'a numeric string, as a header or route gives it');

        OrganizationScope::resolveWith(fn () => null);
        $this->assertNull(OrganizationScope::current());

        OrganizationScope::reset();
        $this->assertFalse(OrganizationScope::isConfigured());
    }

    /** @return array<string, array{mixed}> */
    public static function notAnId(): array
    {
        return [
            'zero'            => [0],
            'negative'        => [-3],
            'word'            => ['abc'],
            'numeric-ish'     => ['12a'],
            'zero string'     => ['0'],
            'float'           => [1.5],
            'array'           => [[5]],
            'true'            => [true],
        ];
    }

    /**
     * Anything else is an error, naming what came back.
     */
    #[DataProvider('notAnId')]
    public function testAnythingElseIsAnError(mixed $answer): void
    {
        // Arrange
        OrganizationScope::resolveWith(fn () => $answer);

        // Assert
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('not an organisation id');

        // Act
        OrganizationScope::current();
    }

    /**
     * A resolver that throws is not swallowed here: the caller refuses.
     */
    public function testAThrowingResolverPropagates(): void
    {
        // Arrange
        OrganizationScope::resolveWith(function (): int {
            throw new \RuntimeException('no tenant');
        });

        // Assert
        $this->expectExceptionMessage('no tenant');

        // Act
        OrganizationScope::current();
    }
}
