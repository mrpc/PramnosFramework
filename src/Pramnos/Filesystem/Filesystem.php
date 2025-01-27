<?php

namespace Pramnos\Filesystem;

/**
 * @package     PramnosFramework
 * @subpackage  Filesystem
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @todo        FTP, Zip
 */
class Filesystem
{

    /**
     * Factory method for the class
     * @staticvar Filesystem $instance
     * @return \Pramnos\Filesystem\Filesystem
     */
    public static function &getInstance()
    {
        static $instance = null;
        if (!is_object($instance)) {
            $instance = new Filesystem;
        }
        return $instance;
    }

    /**
     * Clear the contents of a directory
     * @param string $dir
     * @return boolean
     */
    public function clearDirectory($dir)
    {
        if (!file_exists($dir)) {
            return false;
        }
        if (!is_dir($dir)) {
            return false;
        }
        $contents = scandir($dir);
        if (count($contents) == 2) {
            return true;
        }
        try {
            foreach ($contents as $file) {
                if (($file != "." && $file != "..")
                    && is_dir($dir . DS . $file)) {
                    $this->destroyDirectory($dir . DS . $file);
                } elseif (($file != "." && $file != "..")
                    && is_file($dir . DS . $file)) {
                    unlink($dir . DS . $file);
                }
            }
        } catch (\Exception $ex) {
            #pramnos_logs::log($ex->getMessage());
            return false;
        }

        return true;
    }


    /**
     * Delete a directory and all it's contents
     * @param string $dir
     * @param int $limit how many files it can delete
     * @return boolean
     */
    public function destroyDirectory($dir, $limit = 100)
    {
        if (!is_dir($dir) || is_link($dir)) {
            return unlink($dir);
        }
        $files = scandir($dir);
        if (!is_array($files)) {
            return false;
        }
        $counter = 0; //Performance
        foreach ($files as $file) {
            $counter +=1;
            if ($counter > $limit && $limit != 0) {
                return false;
            }
            if ($file == '.' || $file == '..') {
                continue;
            }
            if (!$this->destroyDirectory($dir . DS . $file)) {
                chmod($dir . DS . $file, 0777);
                if (!$this->destroyDirectory($dir . DS . $file)) {
                    return false;
                }
            }
        }
        return rmdir($dir);
    }

    /**
     * Try to copy an entire directory structure to another place
     * @param  string  $src
     * @param  string  $dst
     * @param  boolean $overwrite
     * @return boolean
     */
    public function recurseCopy($src, $dst, $overwrite = false)
    {
        $dir = opendir($src);
        $return = true;
        if (!file_exists($dst)) {
            try {
                mkdir($dst);
            } catch (\Exception $ex) {
                return false;
            }
        }
        while (false !== ( $file = readdir($dir))) {
            if (( $file != '.' ) && ( $file != '..' )) {
                if (is_dir($src . DS . $file)) {
                    $this->recurseCopy(
                        $src . DS . $file,
                        $dst . DS . $file,
                        $overwrite
                    );
                } else {
                    if (!file_exists($dst . DS . $file)
                        || $overwrite == true) {
                        if (!copy($src . DS . $file, $dst . DS . $file)) {
                            $return = false;
                        }
                    } else {
                        $return = false;
                    }
                }
            }
        }
        closedir($dir);
        return $return;
    }


    /**
     * Get all files of a directory and all it's subdirectories
     * @param string $dir
     * @return array
     */
    public function listDirectoryFiles($dir)
    {
        $ffs = scandir($dir);
        $files = array();
        foreach ($ffs as $ff) {
            if ($ff == '.' || $ff == '..') {
                continue;
            }
            if (is_dir($dir . DS . $ff)) {
                $files = array_merge(
                    $files,
                    $this->listDirectoryFiles($dir . DS . $ff)
                );
            } else {
                $files[]= $dir . DS . $ff;
            }
        }

        return $files;
    }


    /**
     * Delete a file
     * @param string $file
     * @return boolean
     */
    public function removeFile($file)
    {
        if (!file_exists($file) || !is_file($file)) {
            return false;
        }
        try {
            unlink($file);
        } catch (\Exception $ex) {
            #pramnos_logs::log($ex->getMessage());
            return false;
        }
        return true;
    }
}
