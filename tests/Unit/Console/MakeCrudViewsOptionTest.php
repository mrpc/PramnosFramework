<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Make\MakeCrud;

/**
 * `create:crud --views`: `html` or `tpl` are taken and handed to the generator; anything else
 * is refused before a file is written.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(MakeCrud::class)]
class MakeCrudViewsOptionTest extends TestCase
{
    /** A kind it does not know is refused, naming the two it does. */
    public function testAnUnknownKindIsRefused(): void
    {
        // Arrange
        $command = (new \Pramnos\Console\Application())->find('create:crud');
        $tester  = new \Symfony\Component\Console\Tester\CommandTester($command);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown --views 'twig'. Use html or tpl.");

        // Act
        $tester->execute(['name' => 'Client', '--target' => 'mvc', '--views' => 'twig']);
    }

    /** The option is declared, with the two kinds in its description. */
    public function testTheOptionIsDeclared(): void
    {
        // Act
        $option = (new MakeCrud())->getDefinition()->getOption('views');

        // Assert
        $this->assertStringContainsString('tpl (.tpl.php, the template engine)', $option->getDescription());
        $this->assertTrue($option->isValueRequired());
    }
}
