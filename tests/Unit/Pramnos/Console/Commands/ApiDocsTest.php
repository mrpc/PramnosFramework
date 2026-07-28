<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ApiDocs;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the api:docs console command.
 *
 * Points the command at the real PSR-4 fixture controllers dir + namespace and
 * asserts it writes a valid OpenAPI JSON file with the reflected operations, and
 * that a supplied overrides file is deep-merged.
 */
#[CoversClass(ApiDocs::class)]
class ApiDocsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/apidocs_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/**/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @unlink($this->tmp . '/openapi.json');
    }

    private function tester(): CommandTester
    {
        $command = new ApiDocs();
        $command->targetBaseDir = $this->tmp;
        return new CommandTester($command);
    }

    private function fixturesDir(): string
    {
        return dirname(__DIR__, 4) . '/Fixtures/OpenApi';
    }

    /**
     * The command reflects the fixture controllers and writes an OpenAPI document
     * with the expected paths, info and derived security.
     */
    public function testGeneratesOpenApiFile(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            '--controllers' => $this->fixturesDir(),
            '--namespace'   => 'Pramnos\\Tests\\Fixtures\\OpenApi',
            '--output'      => 'openapi.json',
            '--title'       => 'Demo API',
            '--api-version' => '9.9.9',
        ]);

        $this->assertSame(0, $exit, $tester->getDisplay());

        $file = $this->tmp . '/openapi.json';
        $this->assertFileExists($file);
        $doc = json_decode((string) file_get_contents($file), true);

        $this->assertSame('3.0.3', $doc['openapi']);
        $this->assertSame('Demo API', $doc['info']['title']);
        $this->assertSame('9.9.9', $doc['info']['version']);
        $this->assertArrayHasKey('/api/ping', $doc['paths']);
        $this->assertSame('demo.ping', $doc['paths']['/api/ping']['get']['operationId']);
        // The admin route is documented as secured.
        $this->assertSame([['bearerAuth' => []]], $doc['paths']['/api/things']['post']['security']);
    }

    /**
     * A missing controllers directory fails cleanly with a non-zero exit code.
     */
    public function testMissingControllersDirFails(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            '--controllers' => 'does/not/exist',
            '--namespace'   => 'Pramnos\\Tests\\Fixtures\\OpenApi',
            '--output'      => 'openapi.json',
        ]);

        $this->assertSame(1, $exit);
    }
}
