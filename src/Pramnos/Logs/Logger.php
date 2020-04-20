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
     * @param string $log
     * @param string $file
     * @param string $ext
     */
    public static function log($log, $file = 'pramnosframework', $ext = "log")
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
        @fwrite(
            $handle, "["
            . date('d/m/Y H:i', time() + 25200)
            . "] " . $log . "\r\n"
        );
        @fclose($handle);
    }

}