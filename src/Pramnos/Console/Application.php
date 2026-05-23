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
     * Internal application used to connect to databases etc
     * @var \Pramnos\Application\Application
     */
    public $internalApplication = null;

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
        $this->internalApplication
            = \Pramnos\Application\Application::getInstance();
    }

    /**
     * Register Commands to run
     */
    protected function registerCommands()
    {
        $this->add(new \Pramnos\Console\Commands\Init());
        $this->add(new \Pramnos\Console\Commands\Make\MakeModel());
        $this->add(new \Pramnos\Console\Commands\Make\MakeController());
        $this->add(new \Pramnos\Console\Commands\Make\MakeView());
        $this->add(new \Pramnos\Console\Commands\Make\MakeCrud());
        $this->add(new \Pramnos\Console\Commands\Make\MakeApi());
        $this->add(new \Pramnos\Console\Commands\Make\MakeMigration());
        $this->add(new \Pramnos\Console\Commands\Make\MakeMiddleware());
        $this->add(new \Pramnos\Console\Commands\Make\MakeEvent());
        $this->add(new \Pramnos\Console\Commands\Make\MakeListener());
        $this->add(new \Pramnos\Console\Commands\Make\MakeSeeder());
        $this->add(new \Pramnos\Console\Commands\Make\MakeWebhook());
        $this->add(new \Pramnos\Console\Commands\Serve());
        $this->add(new \Pramnos\Console\Commands\MigrateLogs());
        // Migration CLI commands (Phase 4)
        $this->add(new \Pramnos\Console\Commands\Migrate());
        $this->add(new \Pramnos\Console\Commands\MigrateRollback());
        $this->add(new \Pramnos\Console\Commands\MigrateReset());
        $this->add(new \Pramnos\Console\Commands\MigrateRefresh());
        $this->add(new \Pramnos\Console\Commands\MigrateStatus());
        // Health check (Phase 4)
        $this->add(new \Pramnos\Console\Commands\HealthCheck());
        // Scheduled tasks (Phase 4)
        $this->add(new \Pramnos\Console\Commands\ScheduleRun());
        $this->add(new \Pramnos\Console\Commands\ScheduleList());
        // Policy Engine (Phase 4)
        $this->add(new \Pramnos\Console\Commands\PolicyEngine());
        // Queue System (Phase 2)
        $this->add(new \Pramnos\Console\Commands\ProcessQueue());
        $this->add(new \Pramnos\Console\Commands\CleanupQueue());
        // Database seeding
        $this->add(new \Pramnos\Console\Commands\DbSeed());
        // Scaffolding utilities
        $this->add(new \Pramnos\Console\Commands\ScaffoldViews());
        // MCP server + debug status (Phase 13)
        $this->add(new \Pramnos\Console\Commands\McpServe());
        $this->add(new \Pramnos\Console\Commands\DebugStatus());
        // DaemonOrchestrator is abstract — apps register their own concrete subclass
    }

}
