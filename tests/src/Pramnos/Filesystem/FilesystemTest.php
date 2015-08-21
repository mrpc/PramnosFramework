<?php

namespace Pramnos\Tests;

use Pramnos\Filesystem\Filesystem;

/**
 * @package     pramnosFrameworkTests
 * @copyright   2015 Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class FilesystemTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var Filesystem
     */
    protected $object;

    /**
     * Create the instance of StringFinder
     */
    protected function setUp()
    {
        $this->object = new Filesystem();
    }

    /**
     * Destroy the instance of StringFinder
     */
    protected function tearDown()
    {
        $this->object = null;
    }

    /**
     * @covers \Pramnos\Filesystem\Filesystem::getInstance
     */
    public function testConstructor()
    {

        $this->assertInstanceOf(
            'Pramnos\Filesystem\Filesystem', Filesystem::getInstance()
        );
    }

    /**
     * @covers \Pramnos\Filesystem\Filesystem::listDirectoryFiles
     */
    public function testListDirectoryFilesShoudReturnAnArray()
    {
        $this->assertInternalType(
            'array', $this->object->listDirectoryFiles(ROOT)
        );
    }

}
