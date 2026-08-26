<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\CapabilitiesSyncService;

/**
 * The manifest shape the integration guide publishes must be the shape that syncs.
 *
 * The guide documents a map keyed by name — which is the natural JSON for this,
 * and what a client would send:
 *
 * ```json
 * "resources": { "invoices": { "description": "…", "scopes": { "read": "View" } } }
 * ```
 *
 * The normaliser was `array_values()`. It threw the keys away, so every entry
 * arrived with no `name`, every loop skipped it, and a correct manifest synced
 * **zero** resources while answering `200 {"status":"synced"}` — the one response
 * that tells a CI pipeline everything is fine.
 *
 * Scopes were worse than skipped. `{"read": "View invoices"}` became the bare
 * string `"View invoices"`, which the scope writer took as the scope *name*. So a
 * server that accepted the manifest ended up with a scope called "View invoices",
 * and an application asking for `read` matched nothing — a permission system
 * quietly keyed on prose.
 *
 * The normaliser is private, so the shapes are asserted through `hashManifest()`
 * and through the public behaviour a test can reach without a database: two
 * manifests that describe the same capabilities must hash the same, and the two
 * accepted shapes must be interchangeable.
 */
class CapabilitiesManifestShapeTest extends TestCase
{
    /**
     * A service with no usable database.
     *
     * `hashManifest()` touches none, and it is the only method exercised here.
     */
    private function service(): CapabilitiesSyncService
    {
        return new CapabilitiesSyncService(
            $this->createMock(\Pramnos\Database\Database::class)
        );
    }

    /**
     * The documented map shape hashes, and hashes stably.
     *
     * The hash is what makes a repeated deploy a no-op, so an unstable one would
     * re-write every resource on every push.
     */
    public function testTheDocumentedShapeHashesStably(): void
    {
        // Arrange
        $manifest = [
            'resources' => [
                'invoices' => [
                    'description' => 'Customer invoices',
                    'scopes' => ['read' => 'View invoices', 'write' => 'Edit invoices'],
                ],
            ],
            'conditions' => [
                'location_id' => ['value_type' => 'int[]', 'description' => 'Restrict to locations'],
            ],
        ];

        // Act
        $first = $this->service()->hashManifest($manifest);
        $second = $this->service()->hashManifest($manifest);

        // Assert
        $this->assertNotSame('', $first);
        $this->assertSame($first, $second, 'the same manifest must hash the same twice');
    }

    /**
     * A different manifest hashes differently.
     *
     * Without this the test above passes on a constant.
     */
    public function testADifferentManifestHashesDifferently(): void
    {
        // Arrange
        $service = $this->service();
        $one = ['resources' => ['invoices' => ['scopes' => ['read' => 'View']]]];
        $two = ['resources' => ['invoices' => ['scopes' => ['read' => 'View', 'write' => 'Edit']]]];

        // Assert
        $this->assertNotSame($service->hashManifest($one), $service->hashManifest($two));
    }

    /**
     * The normaliser takes the name from the key.
     *
     * This is the fix, reached through reflection because the method is private
     * and the alternative — a database round trip per shape — would test the
     * writer rather than the reader.
     */
    public function testTheKeyBecomesTheName(): void
    {
        // Arrange
        $method = new \ReflectionMethod(CapabilitiesSyncService::class, 'asList');
        $service = $this->service();

        // Act
        $resources = $method->invoke($service, [
            'invoices' => ['description' => 'Customer invoices'],
        ], 'name');

        // Assert
        $this->assertCount(1, $resources);
        $this->assertSame('invoices', $resources[0]['name'],
            "the map's key is the resource name");
        $this->assertSame('Customer invoices', $resources[0]['description']);
    }

    /**
     * A scope map's value is its description, not its name.
     *
     * `{"read": "View invoices"}` is one scope named `read`. Read the other way
     * round it is a scope named after its own description, which is what used to
     * be written.
     */
    public function testAScopeMapNamesTheScopeFromTheKey(): void
    {
        // Arrange
        $method = new \ReflectionMethod(CapabilitiesSyncService::class, 'asList');

        // Act
        $scopes = $method->invoke($this->service(), ['read' => 'View invoices'], 'name');

        // Assert
        $this->assertSame('read', $scopes[0]['name']);
        $this->assertSame('View invoices', $scopes[0]['description']);
    }

    /**
     * Conditions take their key as `key`, not as `name`.
     *
     * The sync loop reads `$condition['key']`; naming it `name` would skip every
     * condition exactly as before.
     */
    public function testAConditionMapNamesTheConditionKey(): void
    {
        // Arrange
        $method = new \ReflectionMethod(CapabilitiesSyncService::class, 'asList');

        // Act
        $conditions = $method->invoke($this->service(), [
            'location_id' => ['value_type' => 'int[]'],
        ], 'key');

        // Assert
        $this->assertSame('location_id', $conditions[0]['key']);
        $this->assertSame('int[]', $conditions[0]['value_type']);
    }

    /**
     * A list whose entries name themselves is passed through unchanged.
     *
     * Anything written against the previous behaviour keeps working.
     */
    public function testAListOfNamedEntriesIsUnchanged(): void
    {
        // Arrange
        $method = new \ReflectionMethod(CapabilitiesSyncService::class, 'asList');

        // Act
        $resources = $method->invoke($this->service(), [
            ['name' => 'invoices', 'description' => 'Customer invoices'],
        ], 'name');

        // Assert
        $this->assertSame('invoices', $resources[0]['name']);
        $this->assertSame('Customer invoices', $resources[0]['description']);
    }

    /**
     * An explicit name inside the entry wins over the key.
     *
     * A manifest that says both is contradicting itself; taking the inner one
     * means the more specific statement wins rather than being silently replaced.
     */
    public function testAnExplicitNameWinsOverTheKey(): void
    {
        // Arrange
        $method = new \ReflectionMethod(CapabilitiesSyncService::class, 'asList');

        // Act
        $resources = $method->invoke($this->service(), [
            'ignored' => ['name' => 'invoices'],
        ], 'name');

        // Assert
        $this->assertSame('invoices', $resources[0]['name']);
    }

    /**
     * Anything that is not an array normalises to nothing.
     *
     * A malformed manifest must sync zero rather than raise on a client's deploy.
     */
    public function testMalformedInputNormalisesToNothing(): void
    {
        // Arrange
        $method = new \ReflectionMethod(CapabilitiesSyncService::class, 'asList');

        // Assert
        $this->assertSame([], $method->invoke($this->service(), 'not an array', 'name'));
        $this->assertSame([], $method->invoke($this->service(), null, 'name'));
    }
}
