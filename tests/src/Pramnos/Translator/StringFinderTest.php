<?php

namespace Pramnos\Tests;

use Pramnos\Translator\StringFinder;


#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Translator\StringFinder::class)]
class StringFinderTest extends \PHPUnit\Framework\TestCase
{

    /**
     * @var StringFinder
     */
    protected $object;

    /**
     * Create the instance of StringFinder
     */
    protected function setUp(): void
    {
        $language = new \Pramnos\Translator\Language();
        $filesystem = new \Pramnos\Filesystem\Filesystem();
        $this->object = new StringFinder($language, $filesystem);
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
        $language = new \Pramnos\Translator\Language();
        $filesystem = new \Pramnos\Filesystem\Filesystem();
        $object = new StringFinder($language, $filesystem);
        $this->assertInstanceOf('Pramnos\Translator\StringFinder', $object);
    }

    
    public function testSearchShouldReturnAnArray()
    {
        $this->assertIsArray($this->object->search(ROOT));
    }

}
