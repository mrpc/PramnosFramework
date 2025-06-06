<?php

namespace Pramnos\Tests;
use Pramnos\Translator\Language;

/**
 * Generated by PHPUnit_SkeletonGenerator 1.2.1 on 2014-05-16 at 23:47:16.
 * @package	pramnosFrameworkTests
 * @copyright	2013-2014 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class LanguageTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var Language
     */
    protected $object;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        $this->object = new Language();
    }

    /**
     * Tears down the fixture, for example, closes a network connection.
     * This method is called after a test is executed.
     */
    protected function tearDown()
    {

    }

    /**
     * @covers Pramnos\Translator\Language::getlang
     */
    public function testGetlang()
    {
        $this->assertTrue(is_array($this->object->getLang()));
    }

    /**
     * @covers Pramnos\Translator\Language::addlang
     */
    public function testAddlang()
    {
        $this->object->addlang(array('test' => 'test'));
        $lang = $this->object->getlang();
        $this->assertTrue(is_array($lang));
        $this->assertTrue(isset($lang['test']));
        $this->assertEquals($lang['test'], 'test');
    }

    /**
     * @covers Pramnos\Translator\Language::_
     */
    public function test_()
    {
        $lang = array(
            'Translated String' => 'a translated string'
        );
        $this->assertEquals('test string', $this->object->_('test string'));
        $this->object->addlang($lang);
        $this->assertEquals('a translated string',
                            $this->object->_('Translated String'));
    }

    /**
     * @covers Pramnos\Translator\Language::currentlang
     */
    public function testCurrentlang()
    {
        $this->object->setLang('greek');
        $this->assertEquals($this->object->currentlang(), 'greek');
    }

    /**
     * @covers Pramnos\Translator\Language::setLang
     */
    public function testSetLang()
    {
        $this->assertTrue(
            $this->object->setLang(
                'greek'
            ) instanceof \Pramnos\Translator\Language
        );
    }

    /**
     * @covers Pramnos\Translator\Language::setLang
     * @expectedException Exception
     */
    public function testSetLangWithNonString()
    {
        $this->setExpectedException('Exception');
        $this->object->setLang(array());
    }

    /**
     * @covers Pramnos\Translator\Language::getInstance
     */
    public function testGetInstance()
    {
        $this->assertTrue(
            Language::getInstance() instanceof \Pramnos\Translator\Language
        );
    }

}
