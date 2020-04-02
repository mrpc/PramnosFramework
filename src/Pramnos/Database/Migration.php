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
     * Get the description of the migration
     * @return string
     */
    public function getDescription() : string
    {
        return $this->description;
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