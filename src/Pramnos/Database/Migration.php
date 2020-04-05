<?php

namespace Pramnos\Database;

/**
 * This class is a template for database migrations
 * @static
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Database
 * @copyright   (C) 2005 - 2020 Yannis - Pastis Glaros, Pramnos Hosting
 */
abstract class Migration extends \Pramnos\Framework\Base
{
    /**
     * Version that this migration sets
     * @var string
     */
    public $version = '';
    /**
     * Description of the migration
     * @var string
     */
    public $description = '';
    /**
     * Should the migration executed automatically
     * @var bool
     */
    public $autoExecute = true;
    /**
     * List of queries to execute
     * @var string[]
     */
    protected $queriesToExecute = array();
    /**
     * Application
     * @var \Pramnos\Application\Application
     */
    protected $application;

    /**
     * Database migration
     * @param \Pramnos\Application\Application $application
     */
    public function __construct(\Pramnos\Application\Application $application)
    {
        $this->application = $application;
        parent::__construct();
    }

    /**
     * Get the description of the migration
     * @return string
     */
    public function getDescription() : string
    {
        return $this->description;
    }

    /**
     * Add a query for execution
     * @param string $query
     */
    protected function addQuery($query)
    {
        $this->queriesToExecute[] = $query;
    }

    /**
     * Execute all the queries
     */
    protected function executeQueries()
    {
        foreach ($this->queriesToExecute as $query) {
            try {
                $this->application->database->Execute($query);
                \Pramnos\Logs\Logs::log("\n" . $query . "\n\n", 'upgrades');
            } catch (Exception $exception) {
                \Pramnos\Logs\Logs::log(
                    $exception->getMessage() . "\n\n" . $query, 'upgradeerrors'
                );
            }
        }
    }

    /**
     * Run the migration
     * @return void
     */
    public function up() : void
    {

    }

    /**
     * Undo the migration
     * @return void
     */
    public function down() : void
    {

    }
}