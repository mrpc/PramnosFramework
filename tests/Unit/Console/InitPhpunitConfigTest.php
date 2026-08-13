<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * The `phpunit.xml` that `pramnos init` writes into a new application.
 *
 * A scaffolded application inherits the framework's singletons, so it inherits the
 * leaks they cause: an identity sealed by one test answering for every test after it
 * (135 failures, once) and a shared mutable document (three more). The framework
 * learned that the hard way and registered two PHPUnit extensions. A generated
 * project that omits them is set up to learn it again from scratch — and the
 * omission is invisible, because a leak surfaces as a failure in some unrelated test
 * that looks like a bug in *that* test.
 *
 * This is asserted on the generated string rather than on a scaffolded tree because
 * a whole `init` run costs nearly two seconds and proves nothing extra here.
 */
class InitPhpunitConfigTest extends TestCase
{
    /**
     * Returns the generated `phpunit.xml` without scaffolding a project.
     *
     * @return string The XML as written to a new application's root
     */
    private function generatedConfig(): string
    {
        return (new \ReflectionClass(Init::class))
            ->getMethod('getPhpunitXml')
            ->invoke(new Init());
    }

    /**
     * The generated config registers both isolation extensions.
     *
     * Asserted by fully-qualified class name, because the name is the contract: it is
     * what PHPUnit resolves through the autoloader, and both classes live under
     * `Pramnos\Framework\Testing` precisely so that a consumer can name them.
     */
    public function testGeneratedConfigRegistersBothIsolationExtensions(): void
    {
        // Act
        $xml = $this->generatedConfig();

        // Assert
        $this->assertStringContainsString(
            '<bootstrap class="Pramnos\Framework\Testing\RequestIdentityIsolation"/>',
            $xml
        );
        $this->assertStringContainsString(
            '<bootstrap class="Pramnos\Framework\Testing\DocumentIsolation"/>',
            $xml
        );
    }

    /**
     * The extensions named in the generated config exist and are loadable.
     *
     * This is the assertion that would have caught the real hazard: `/tests` is
     * `export-ignore`d in `.gitattributes`, so the two classes as they were first
     * written — under `Pramnos\Tests\Support` — did not ship inside the composer
     * package at all. A generated `phpunit.xml` naming them would have failed to boot
     * in every consumer project while passing every test here.
     */
    public function testTheExtensionsItNamesAreShippedClasses(): void
    {
        // Arrange — the class names taken from the generated file, not from a literal
        preg_match_all(
            '#<bootstrap class="([^"]+)"/>#',
            $this->generatedConfig(),
            $matches
        );
        $this->assertNotEmpty($matches[1], 'The generated config registers no extensions.');

        foreach ($matches[1] as $class) {
            // Assert — loadable, and shipped rather than test-only
            $this->assertTrue(class_exists($class), $class . ' is registered but cannot be loaded.');

            $file = (new \ReflectionClass($class))->getFileName();
            $this->assertStringContainsString(
                '/src/',
                (string)$file,
                $class . ' lives outside src/, so it will not exist in a consumer project.'
            );
        }
    }

    /**
     * The config explains why the extensions are there.
     *
     * An `<extensions>` block whose purpose is not on the page next to it is a block
     * somebody deletes while tidying up, and the consequence arrives weeks later in a
     * test that has nothing to do with it.
     */
    public function testGeneratedConfigExplainsWhyTheExtensionsAreThere(): void
    {
        // Act
        $xml = $this->generatedConfig();

        // Assert
        $this->assertStringContainsString('process-wide in a test run', $xml);
        $this->assertStringContainsString('Keep them.', $xml);
    }
}
