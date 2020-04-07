<?php

namespace Pramnos\Database;

/**
 * Helper functions for database
 * @package     PramnosFramework
 * @subpackage  Database
 * @copyright   Copyright (C) 2005 - 2013 Yannis - Pastis Glaros, Pramnos Hosting
 */
class Helper extends pramnos_base
{

    /**
     * Create a database using a file with database insert definitions
     * @param string $file
     * @return boolean
     * @throws Exception
     */
    public static function createDatabaseFromFile($file)
    {
        if (!file_exists($file)){
            throw new Exception("Database file doesn't exist.'");
        }
        $db = pramnos_factory::getDatabase();
        $db->sql_cache_flush_cache();
        $db->query(
            "ALTER DATABASE `" . $db->database . "` "
            . " DEFAULT CHARACTER SET utf8 "
            . " COLLATE utf8_general_ci"
        );
        $dbinsert = array();
        $dbset = array();
        require_once ($file);
        foreach (array_keys($dbset) as $table) {
            if (!$db->create_table($table, $dbset[$table])) {
                throw new Exception(
                    "cannot create table `$table` <br />SQL: " . $dbset[$table]
                );
            }
        }
        foreach (array_keys($dbinsert) as $line) {
            $sql = $db->prepare($dbinsert[$line]);
            if (trim($sql) == '') {
                $sql = str_ireplace('#PREFIX#', $db->prefix, $dbinsert[$line]);
            }
            $db->Execute($sql);
        }
        return true;
    }

}
