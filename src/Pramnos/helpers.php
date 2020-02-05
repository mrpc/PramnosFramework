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

if (!function_exists('l')) {
    /**
     * Alias of echo $lang->_('string');
     */
    function l(){
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $params = func_get_args();
        echo call_user_func_array(array($lang,'_'), $params);
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
        $s = empty($_SERVER["HTTPS"])
            ? ''
            : ($_SERVER["HTTPS"] == "on") ? "s" : "";
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
            $s = 's';
        }
        $port = ($_SERVER["SERVER_PORT"] == "80"
            || $_SERVER["SERVER_PORT"] == "443"
            ) ? "" : (":" . $_SERVER["SERVER_PORT"]);
        $url = 'http' . $s . "://"
            . $_SERVER['SERVER_NAME']
            . $port . dirname($_SERVER["SCRIPT_NAME"]);
        if (substr($url, -1) != "/") {
            $url .= "/";
        }
        return $url;
    }

}