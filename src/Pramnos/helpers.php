<?php


if (!function_exists("env")) {
    /**
     * Check if an constant is defined and returns it. Otherwise returns
     * the default value
     * @param string $field
     * @param mixed $defaultReturn
     * @return mixed
     */
    function env($field, $defaultReturn = null)
    {
        if (defined($field)) {
            return constant($field);
        }
        return $defaultReturn;
    }
}

if (!function_exists("envvar")) {
    /**
     * Returns an environment variable from getenv(), $_ENV or $_SERVER.
     * Also parses common string values like true, false, null and empty.
     *
     * @param string $field
     * @param mixed $defaultReturn
     * @return mixed
     */
    function envvar($field, $defaultReturn = null)
    {
        $value = getenv($field);

        if ($value !== false) {
            return parseEnvValue($value);
        }

        if (array_key_exists($field, $_ENV)) {
            return parseEnvValue($_ENV[$field]);
        }

        if (array_key_exists($field, $_SERVER)) {
            return parseEnvValue($_SERVER[$field]);
        }

        return $defaultReturn;
    }
}

if (!function_exists("parseEnvValue")) {
    /**
     * Parse string environment values to native PHP types.
     *
     * @param mixed $value
     * @return mixed
     */
    function parseEnvValue($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmedValue = trim($value);
        $lowerValue = strtolower($trimmedValue);

        switch ($lowerValue) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
        }

        if (
            (substr($trimmedValue, 0, 1) === '"' && substr($trimmedValue, -1) === '"')
            || (substr($trimmedValue, 0, 1) === "'" && substr($trimmedValue, -1) === "'")
        ) {
            return substr($trimmedValue, 1, -1);
        }

        if (is_numeric($trimmedValue)) {
            if (strpos($trimmedValue, '.') !== false) {
                return (float) $trimmedValue;
            }
            return (int) $trimmedValue;
        }

        return $trimmedValue;
    }
}

if (!function_exists("loadDotenv")) {
    /**
     * Loads .env file from the given path using Symfony Dotenv.
     *
     * @param string|null $path
     * @param string $fileName
     * @return bool
     */
    function loadDotenv($path = null, $fileName = '.env')
    {
        static $loadedFiles = array();

        if (!class_exists('\Symfony\Component\Dotenv\Dotenv')) {
            return false;
        }

        if ($path === null) {
            $path = getcwd();
        }

        $envFile = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

        if (isset($loadedFiles[$envFile])) {
            return true;
        }

        if (!is_file($envFile) || !is_readable($envFile)) {
            return false;
        }

        $dotenv = new \Symfony\Component\Dotenv\Dotenv();
        $dotenv->loadEnv($envFile);

        $loadedFiles[$envFile] = true;

        return true;
    }
}

if (!function_exists('l') && !class_exists("pramnos_theme")) {
    /**
     * Alias of echo $lang->_('string');
     */
    if (class_exists('pramnos_factory')) {
        function l(){
            $lang = \pramnos_factory::getLanguage();
            $params = func_get_args();
            echo call_user_func_array(array($lang,'_'), $params);
        }
    } else {
        function l(){
            $lang = \Pramnos\Framework\Factory::getLanguage();
            $params = func_get_args();
            echo call_user_func_array(array($lang,'_'), $params);
        }
    }

}

if (!function_exists("getUrl")) {
    /**
     * Returns the current URL. This function exists here to
     * let us define URL and
     * sURL
     * @return string The current url
     */
    function getUrl()
    {
        if (empty($_SERVER["HTTPS"])) {
               $s = '';
           } else {
               $s = ($_SERVER["HTTPS"] == "on") ? "s" : "";
           }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
            $s = 's';
        }
        $port = '';
        if ($_SERVER["SERVER_PORT"] == "80"
            || $_SERVER["SERVER_PORT"] == "443"
        ) {
            $port = '';
        } else {
            $port = ":" . $_SERVER["SERVER_PORT"];
        }
        if (isset($_SERVER['SERVER_NAME'])) {
            $url = 'http' . $s . "://"
                . $_SERVER['SERVER_NAME']
                . $port . dirname($_SERVER["SCRIPT_NAME"]);
        } else {
            $url = '';
        }
        if (substr($url, -1) != "/") {
            $url .= "/";
        }
        return $url;
    }

}