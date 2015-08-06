<?php

namespace Pramnos\Tests;
use Pramnos\Translator\StringFinder;


/**
 * @package     pramnosFrameworkTests
 * @copyright   2015 Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class TranslatorStringFinderTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var StringFinder
     */
    protected $object;

    /**
     * Create the instance of StringFinder
     */
    protected function setUp()
    {
        $language = new \Pramnos\Translator\Language();
        $filesystem = new \Pramnos\Filesystem\Filesystem();
        $this->object = new StringFinder($language, $filesystem);
    }

    /**
     * Destroy the instance of StringFinder
     */
    protected function tearDown()
    {
        $this->object = null;
    }

    /**
     * @covers \Pramnos\Translator\StringFinder::__construct
     */
    public function testConstructor()
    {
        $language = new \Pramnos\Translator\Language();
        $filesystem = new \Pramnos\Filesystem\Filesystem();
        $object = new StringFinder($language, $filesystem);
        $this->assertInstanceOf('Pramnos\Translator\StringFinder', $object);
    }

    /**
     * @covers \Pramnos\Translator\StringFinder::Search
     */
    public function testSearchShouldReturnAnArray()
    {
        $this->assertInternalType(
            'array',
            $this->object->search(ROOT)
        );
    }


}
