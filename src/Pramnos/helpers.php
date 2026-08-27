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

if (!function_exists('l')) {
    /**
     * Alias of echo $lang->_('string');
     *
     * Until 2026-08-14 this was declared twice, choosing between the framework's Factory and
     * `pramnos_factory` from a deprecated CMS, and was skipped entirely when a `pramnos_theme`
     * class existed. Neither class ships here, so the branch could only ever be taken by an
     * application that carried its own copy — and the guard silently left `l()` undefined for
     * one that did.
     */
    function l()
    {
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $params = func_get_args();
        echo call_user_func_array(array($lang, '_'), $params);
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escape a value for safe output inside HTML templates.
     *
     * Converts the value to a string then runs it through htmlspecialchars()
     * with ENT_QUOTES | ENT_SUBSTITUTE so that both single and double quotes
     * are escaped and invalid UTF-8 sequences are replaced with the Unicode
     * replacement character rather than causing an empty return.
     *
     * Usage in .html.php templates:
     *   <?php echo e($user->name); ?>
     *   <input value="<?php echo e($request->get('q')); ?>">
     *
     * Intentionally NOT escaped (use raw output):
     *   <?php echo $view->trustedHtml; ?>  // generated HTML, not user input
     *
     * @param  mixed  $value    Any scalar, null, or stringable.
     * @param  string $encoding Character encoding (default UTF-8).
     * @return string           HTML-safe string.
     */
    function e(mixed $value, string $encoding = 'UTF-8'): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, $encoding);
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
        $serverPort = $_SERVER["SERVER_PORT"] ?? null;
        if ($serverPort == "80"
            || $serverPort == "443"
            || $serverPort === null
        ) {
            $port = '';
        } else {
            $port = ":" . $serverPort;
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
if (!function_exists('adminUrl')) {
    /**
     * A URL inside the administration area.
     *
     * ```php
     * <a href="<?php echo adminUrl('Users/edit/5'); ?>">Edit</a>
     * ```
     *
     * A view that belongs to the admin area cannot use a bare `sURL . 'Users'`:
     * inside the area that link leaves it, so every table row, every "back" link
     * and every pagination control dropped the visitor onto the public copy of the
     * page with a different layout and no sidebar.
     *
     * With no area configured this is exactly `sURL . $path`, so the same view
     * serves an application that has one and an application that does not.
     *
     * @param  string $path Path relative to the area, e.g. `Users/edit/5`
     * @return string Absolute URL
     * @see \Pramnos\Http\AdminArea::url()
     */
    function adminUrl(string $path = ''): string
    {
        return \Pramnos\Http\AdminArea::url($path);
    }
}

if (!function_exists('t')) {
    /**
     * A translation, returned rather than echoed — `l()`'s missing partner.
     *
     * `l()` echoes, which makes it right inside a template and useless everywhere a
     * string is a value: a document title, a flash message, an exception, an array of
     * labels. The workaround was `\Pramnos\Framework\Factory::getLanguage()->_(…)` at
     * every one of those call sites, which is long enough that most of them simply
     * kept the English literal instead — the whole account area's page titles among
     * them.
     *
     * Same arguments and same formatting rules as `_()`: with no arguments the
     * translation is returned verbatim, placeholders included.
     *
     * ```php
     * $doc->title = t('Account Dashboard');
     * $doc->title = t('%s messages', $count);
     * ```
     */
    function t(): string
    {
        $lang = \Pramnos\Framework\Factory::getLanguage();

        return (string) call_user_func_array([$lang, '_'], func_get_args());
    }
}

if (!function_exists('humanCheckField')) {
    /**
     * The human check's form fields, as markup to echo inside a `<form>`.
     *
     * ```php
     * <form method="post" action="…">
     *     <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
     *     <?php echo humanCheckField($this->humanCheck ?? null); ?>
     * ```
     *
     * Returns an empty string when the application has not switched the check on for
     * that form, so the same line is safe on every one of them.
     *
     * A function rather than the partial this started as. A partial has to live in a
     * view directory, and a view directory is per-application: a project whose public
     * screens are its own — which is every project, since the sign-in page is the one
     * screen nobody inherits — had to copy the partial in to use the feature, and then
     * owned a copy of the framework's markup for ever. The copy is what this exists to
     * remove.
     *
     * The `data-pf-humancheck` attribute belongs on the `<form>`, which markup inserted
     * *inside* the form cannot reach, so it is set from a one-line script. That script
     * carries the CSP nonce: without it a project with a strict policy drops it
     * silently, no solution is ever computed, and the check then refuses every
     * submission — a failure that looks like the check working.
     *
     * @param ?array{challenge: string, difficulty: int, expires: int} $challenge
     *        From `HumanCheck::challenge()`, or null when there is no check.
     */
    function humanCheckField(?array $challenge): string
    {
        if ($challenge === null || empty($challenge['challenge'])) {
            return '';
        }

        $token = (string) $challenge['challenge'];
        $nonce = \Pramnos\Application\Application::currentInstance()?->cspNonce ?? '';
        $attr  = $nonce !== ''
            ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        $id    = 'pf-hc-' . substr(hash('sha256', $token), 0, 8);
        $json  = json_encode($challenge, JSON_UNESCAPED_SLASHES);
        $src   = (defined('sURL') ? sURL : '') . 'assets/js/pf-humancheck.js';

        return '<input type="hidden" name="human_challenge" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="human_solution" value="" id="' . $id . '">'
            . '<script' . $attr . '>(function(){var f=document.getElementById("' . $id . '");'
            . 'if(f&&f.form){f.form.setAttribute("data-pf-humancheck",'
            . json_encode($json, JSON_UNESCAPED_SLASHES) . ');}})();</script>'
            . '<script' . $attr . ' src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8')
            . '"></script>';
    }
}
