<?php

namespace Tests\Fixtures\KernelFallbackApp {
    /**
     * Fixture: an application that ships its own <namespace>\Application kernel.
     * Never instantiated here — resolveApplicationClass() only returns its name.
     */
    class Application extends \Pramnos\Application\Application
    {
    }
}

namespace Tests\Unit\Pramnos\Application {

    use PHPUnit\Framework\TestCase;
    use Pramnos\Application\Application;

    /**
     * Covers Application::resolveApplicationClass() — the kernel-class resolution
     * from an app.php config, which lets an app omit an (empty) Application
     * subclass and still be instantiable via the base kernel.
     */
    class ApplicationClassResolutionTest extends TestCase
    {
        /**
         * No namespace in the config → the base kernel, so an app that declares
         * none still resolves (previously this produced a non-existent class).
         */
        public function testFallsBackToBaseKernelWhenNoNamespace(): void
        {
            $this->assertSame(Application::class, Application::resolveApplicationClass([]));
        }

        /**
         * An empty-string namespace is treated as "none" and falls back too.
         */
        public function testEmptyNamespaceFallsBack(): void
        {
            $this->assertSame(Application::class, Application::resolveApplicationClass(['namespace' => '']));
        }

        /**
         * A namespace whose <namespace>\Application does not exist → the base
         * kernel: an app needs no empty subclass just to be instantiable.
         */
        public function testFallsBackWhenNamespaceHasNoApplicationClass(): void
        {
            $this->assertSame(
                Application::class,
                Application::resolveApplicationClass(['namespace' => 'No\\Such\\App'])
            );
        }

        /**
         * When the app DOES ship a <namespace>\Application kernel, it is honoured
         * (BC: the previous behaviour for apps with a custom kernel is unchanged).
         */
        public function testHonoursAppSuppliedKernelSubclass(): void
        {
            $resolved = Application::resolveApplicationClass(['namespace' => 'Tests\\Fixtures\\KernelFallbackApp']);

            $this->assertTrue(class_exists($resolved));
            $this->assertSame('Tests\\Fixtures\\KernelFallbackApp\\Application', ltrim($resolved, '\\'));
            $this->assertTrue(is_subclass_of($resolved, Application::class));
        }
    }
}
