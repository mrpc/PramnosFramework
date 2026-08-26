<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\CapabilitiesSyncService;

/**
 * The read side of the capabilities RFC.
 *
 * The write side existed alone: an application could push its resources, scopes
 * and ABAC condition keys, and no screen showed an operator what had arrived —
 * which makes "central permission control" a place where data goes in. A grant
 * names a resource, so "which names does this client publish" is the question the
 * whole design has to answer, and answering it meant querying four tables by hand.
 *
 * The shape is asserted here; the screen that renders it is covered where a
 * database and a session exist.
 */
class ApplicationCapabilitiesReadTest extends TestCase
{
    /**
     * A service whose queries all come back empty.
     */
    private function emptyService(): CapabilitiesSyncService
    {
        $result = new class {
            public int $numRows = 0;

            /** @var array<string, mixed> */
            public array $fields = [];

            public function getAll(): array
            {
                return [];
            }
        };

        $builder = $this->createMock(\Pramnos\Database\QueryBuilder::class);
        foreach (['table', 'where', 'orderBy'] as $chained) {
            $builder->method($chained)->willReturnSelf();
        }
        $builder->method('first')->willReturn($result);
        $builder->method('getAll')->willReturn([]);

        $database = $this->createMock(\Pramnos\Database\Database::class);
        $database->method('queryBuilder')->willReturn($builder);

        return new CapabilitiesSyncService($database);
    }

    /**
     * An application that never pushed describes as empty, with every key present.
     *
     * A view reads `resources` and `conditions` unconditionally, so a missing key
     * would be a warning on an administration page rather than an empty list.
     */
    public function testAnApplicationWithNoManifestDescribesAsEmpty(): void
    {
        // Act
        $described = $this->emptyService()->describe(1);

        // Assert
        $this->assertSame('', $described['hash']);
        $this->assertNull($described['synced_at']);
        $this->assertSame([], $described['resources']);
        $this->assertSame([], $described['conditions']);
    }

    /**
     * The shape is the one a view can render without checking anything.
     *
     * Every key is present and typed, so the template needs no `isset` around the
     * things it loops over.
     */
    public function testTheShapeIsStable(): void
    {
        // Act
        $described = $this->emptyService()->describe(1);

        // Assert
        $this->assertSame(
            ['hash', 'synced_at', 'resources', 'conditions'],
            array_keys($described)
        );
        $this->assertIsString($described['hash']);
        $this->assertIsArray($described['resources']);
        $this->assertIsArray($described['conditions']);
    }

    /**
     * `describe()` is public, because a screen has to be able to call it.
     *
     * The sync service's other reader — `currentHash()` — is public for the same
     * reason. A private one would leave the tables readable only by the writer.
     */
    public function testDescribeIsPartOfThePublicApi(): void
    {
        // Act
        $method = new \ReflectionMethod(CapabilitiesSyncService::class, 'describe');

        // Assert
        $this->assertTrue($method->isPublic());
    }
}
