<?php

namespace Pramnos\Logs;

/**
 * @package     PramnosFramework
 * @subpackage  Logs
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Logger
{

    /**
     * Log something
     * @param string $log The log string to write
     * @param string $file The file to write the log
     * @param string $ext The extension of the log file
     * @param bool $startoffile If true, the log will be written at the start of the file
     * @return void
     */
    public static function log($log, $file = 'pramnosframework', $ext = "log", $startoffile = false)
    {
        if (!file_exists(LOG_PATH)) {
            @mkdir(LOG_PATH);
            @chmod(LOG_PATH, 0777);
        }
        if (!file_exists(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs');
            @chmod(LOG_PATH . DS . 'logs', 0777);
        }
        $file = $file . '.' . $ext;
        $handle = @fopen(LOG_PATH . DS . 'logs' . DS . $file, "a+");


        if ($handle) {
            if ($startoffile) {
                $content = @fread($handle, filesize(LOG_PATH . DS . 'logs' . DS . $file));
                @ftruncate($handle, 0);
                @rewind($handle);
                @fwrite(
                    $handle,
                    "["
                        . date('d/m/Y H:i:s', time())
                        . "] " . $log . "\r\n"
                );
                @fwrite($handle, $content);
            } else {
                @fwrite(
                    $handle,
                    "["
                        . date('d/m/Y H:i:s', time())
                        . "] " . $log . "\r\n"
                );
            }
        }
        @fclose($handle);
    }

    /**
     * Log something at the start of the file
     * @param string $log The log string to write
     * @param string $file The file to write the log
     * @param string $ext The extension of the log file
     * @return void
     */
    public static function logPrepend($log, $file = 'pramnosframework', $ext = "log")
    {
        self::log($log, $file, $ext, true);
    }

}
