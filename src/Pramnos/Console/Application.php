<?php

namespace Pramnos\Console;
/**
 * @package     PramnosFramework
 * @subpackage  Console
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Application extends \Symfony\Component\Console\Application
{

    /**
     * Class Constructor
     * @param string $name Application name
     * @param string $version Application Version
     */
    public function __construct($name = 'Pramnos Framework Console Application',
        $version = '1.0')
    {
        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'localhost';
            $_SERVER['SERVER_PORT'] = 80;
            $_SERVER['SERVER_NAME'] = 'localhost';
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['HTTP_USER_AGENT'] = 'CLI';
            $_SERVER['REQUEST_URI'] = '/';
        }
        if (!defined('sURL')) {
            define('sURL', 'https://pramnosframework.test'); //MainURL
        }
        parent::__construct($name, $version);
        $this->registerCommands();
    }

    /**
     * Register Commands to run
     */
    protected function registerCommands()
    {
        $this->add(new \Pramnos\Console\Commands\Create());
    }

}
