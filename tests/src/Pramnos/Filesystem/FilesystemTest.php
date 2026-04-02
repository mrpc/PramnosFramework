<?php

namespace Pramnos\Tests;

use Pramnos\Filesystem\Filesystem;


#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Filesystem\Filesystem::class)]
class FilesystemTest extends \PHPUnit\Framework\TestCase
{

    /**
     * @var Filesystem
     */
    protected $object;

    /**
     * Create the instance of StringFinder
     */
    protected function setUp(): void
    {
        $this->object = new Filesystem();
    }

    /**
     * Destroy the instance of StringFinder
     */
    protected function tearDown(): void
    {
        $this->object = null;
    }

    
    public function testConstructor()
    {

        $this->assertInstanceOf(
            'Pramnos\Filesystem\Filesystem', Filesystem::getInstance()
        );
    }

    
    public function testListDirectoryFilesShoudReturnAnArray()
    {
        $this->assertIsArray($this->object->listDirectoryFiles(ROOT));
    }

}
