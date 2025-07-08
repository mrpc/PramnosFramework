<?php

namespace Pramnos\General;

/**
 * Helper Methods
 * @package     PramnosFramework
 * @subpackage  General
 * @copyright   2005 - 2013 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Helpers
{

    /**
     * How much time has passed from a date
     * @param integer $date Date in linux timestamp
     * @return string
     */
    public static function timepassed($date)
    {
        $lang = \Pramnos\Translator\Language::getInstance();
        $returnedText = "";
        $minutes = (time() - $date) / 60;
        $minutes = (int) $minutes;
        $hours = $minutes / 60;
        $hours = (int) $hours;
        if ($hours <> 0) {
            $minutes = $minutes - ($hours * 60);
        }
        $days = $hours / 24;
        $days = (int) $days;
        if ($days <> 0) {
            $hours = $hours - ($days * 24);
        }
        $months = $days / 30;
        $months = (int) $months;
        if ($months <> 0) {
            $days = $days - ($months * 30);
        }
        $years = $months / 12;
        $years = (int) $years;
        if ($years <> 0) {
            $months = $months - ($years * 12);
        }

        if ($years == 0 and $months == 0 and $days == 1) {
            $returnedText = $lang->_('Yesterday');
        } elseif ($years == 0 && $months == 0 && $days == 0 && $hours == 0) {
            $returnedText = "$minutes "
                . $lang->_('minutes') . " " . $lang->_('ago');
        } elseif ($years == 0 && $months == 0 && $days == 0 && $minutes == 0) {
            $returnedText = "$hours "
                . $lang->_('hours') . " " . $lang->_('ago');
        } elseif ($years == 0 and $months == 0 and $days == 0) {
            $returnedText = "$hours "
                . $lang->_('hours') . " "
                . $lang->_('and') . " $minutes "
                . $lang->_('minutes') . " " . $lang->_('ago');
        } elseif ($years == 0 and $months == 0) {
            $returnedText = "$days " . $lang->_('days') . " " . $lang->_('ago');
        } elseif ($years == 0 and $days <> 0) {
            if ($months > 1) {
                $returnedText = "$months "
                    . $lang->_('months') . " "
                    . $lang->_('and') . " $days "
                    . $lang->_('days') . " " . $lang->_('ago');
            } else {
                $returnedText = $lang->_('One month and')
                    . " $days " . $lang->_('days') . " " . $lang->_('ago');
            }
        } elseif ($years == 0) {
            $returnedText = "$months "
                . $lang->_('months') . " " . $lang->_('ago');
        } elseif ($months == 0) {
            $returnedText = "$years "
                . $lang->_('years') . " " . $lang->_('ago');
        } elseif ($years == 1) {
            $returnedText = "$years "
                . $lang->_('year') . " " . $lang->_('and')
                . " $months months " . $lang->_('ago');
        } else {
            $returnedText = "$years "
                . $lang->_('years') . " " . $lang->_('and')
                . " $months months " . $lang->_('ago');
        }
        return $returnedText;
    }

    /**
     * Convert seconds to text format time difference
     * @param integer $seconds
     * @return string
     */
    public static function secondsToTime($seconds) {
        $dtF = new \DateTime("@0");
        $dtT = new \DateTime("@$seconds");
        if ($seconds > (3600*24)) {
            return $dtF->diff($dtT)->format(
                '%a days, %h hours, %i minutes and %s seconds'
            );
        } elseif ($seconds > 3600) {
            return $dtF->diff($dtT)->format(
                '%h hours, %i minutes and %s seconds'
            );
        } elseif ($seconds > 60) {
            return $dtF->diff($dtT)->format(
                '%i minutes and %s seconds'
            );
        } else {
            return $dtF->diff($dtT)->format(
                '%s seconds'
            );
        }

    }

    /**
     * Get time with time difference
     * @param int  $time
     * @param real $difference
     * @return integer
     */
    public static function getTime($time = NULL, $difference = 0)
    {
        if ($time == NULL) {
            $time = time();
        }
        if ($difference == 0) {
            $difference = \Pramnos\Application\Settings::getSetting(
                'timedifference'
            );
        }
        $difference = (float) $difference;
        $difference = $difference * 3600;
        return $time + $difference;
    }

    /**
     * Convert a boolean to string (true or false)
     * @param boolean $bool
     * @return string
     */
    public static function bool2string($bool)
    {
        if ($bool === true) {
            return 'true';
        } else {
            return 'false';
        }
    }

    /**
     * Clean up an html string
     * @param string $html An html string
     * @return string Clean text
     */
    public static function clearhtml($html)
    {
        $search = array(
            "'<script[^>]*?>.*?</script>'si", // Strip out javascript
            "'<[\/\!]*?[^<>]*?>'si", // Strip out HTML tags
            "'([\r\n])[\s]+'", // Strip out white space
            "'&(quot|#34);'i", // Replace HTML entities
            "'&(amp|#38);'i",
            "'&(lt|#60);'i",
            "'&(gt|#62);'i",
            "'&(nbsp|#160);'i",
            "'&(iexcl|#161);'i",
            "'&(cent|#162);'i",
            "'&(pound|#163);'i",
            "'&(copy|#169);'i",
            "'&#(\d+);'e"
        );                    // evaluate as php

        $replace = array("",
            "",
            "\\1",
            "\"",
            "&",
            "<",
            ">",
            " ",
            chr(161),
            chr(162),
            chr(163),
            chr(169),
            "chr(\\1)");

        return preg_replace($search, $replace, $html);
    }

    /**
     * Find out and return the user browser
     * @return string The browser
     */
    public static function get_user_browser($usarAgent = '')
    {
        if ($usarAgent == '') {
            $usarAgent = $_SERVER['HTTP_USER_AGENT'];
        }
        $userBrowser = '';
        if (preg_match('/MSIE/i', $usarAgent)) {
            $userBrowser = "ie";
        } elseif (preg_match('/Chrome/i', $usarAgent)) {
            $userBrowser = "chrome";
        } elseif (preg_match('/Firefox/i', $usarAgent)) {
            $userBrowser = "firefox";
        } elseif (preg_match('/Safari/i', $usarAgent)) {
            $userBrowser = "safari";
        } elseif (preg_match('/Flock/i', $usarAgent)) {
            $userBrowser = "flock";
        } elseif (preg_match('/Opera/i', $usarAgent)) {
            $userBrowser = "opera";
        }

        return $userBrowser;
    }

    /**
     *
     * @param string $agent
     * @return type
     */
    public static function getBrowser($agent) {

        $browserInfo = @get_browser(
            $agent, true
        );

        if (!$browserInfo) {
            return (object)array(
                'userAgent' => $agent,
                'browser'   => self::get_user_browser($agent),
                'version'   => '',
                'platform'  => '',
                'majorver'  => '',
                'os_number' => '',
                'engine' => ''

            );
        }

        if (isset($browserInfo['engine_data'])
            && is_array($browserInfo['engine_data'])
            && isset($browserInfo['engine_data'][0])) {
            $engine = $browserInfo['engine_data'][0];
        } else {
            $engine = '';
        }
        return (object)array(
            'userAgent' => $agent,
            'browser'   => $browserInfo['browser'],
            'version'   => $browserInfo['version'],
            'platform'  => $browserInfo['platform'],
            'majorver'  => $browserInfo['majorver'],
            'os_number' => '',
            'engine' => $engine

        );
    }


    /**
     * Fixes the odd indexing of multiple file uploads from the format:
     *
     * $_FILES['field']['key']['index']
     *
     * To the more standard and appropriate:
     *
     * $_FILES['field']['index']['key']
     *
     * @param array $files
     * @author Corey Ballou
     * @link http://www.jqueryin.com
     */
    public static function fixFilesArray(&$files)
    {
        $names = array(
            'name' => 1,
            'type' => 1,
            'tmp_name' => 1,
            'error' => 1,
            'size' => 1
        );

        foreach ($files as $key => $part) {
            // only deal with valid keys and multiple files
            $key = (string) $key;
            if (isset($names[$key]) && is_array($part)) {
                foreach ($part as $position => $value) {
                    $files[$position][$key] = $value;
                }
                // remove old key reference
                unset($files[$key]);
            }
        }
    }

    /**
     * Convert a greek string to greeklish
     * @param string $string
     * @param bool $urlFriendly
     * @return string
     */
    public static function greeklish($string, $urlFriendly=false)
    {
        if ($urlFriendly==true) {
            $greek = array(
                'α', 'ά', 'Ά', 'Α', 'β', 'Β', 'γ', 'Γ', 'δ', 'Δ', 'ε', 'έ',
                'Ε', 'Έ', 'ζ', 'Ζ', 'η', 'ή', 'Η', 'θ', 'Θ',
                'ι', 'ί', 'ϊ', 'ΐ', 'Ι',
                'Ί', 'κ', 'Κ', 'λ', 'Λ', 'μ', 'Μ', 'ν',
                'Ν', 'ξ', 'Ξ', 'ο', 'ό',
                'Ο', 'Ό', 'π', 'Π', 'ρ', 'Ρ', 'σ', 'ς',
                'Σ', 'τ', 'Τ', 'υ', 'ύ', 'Υ',
                'Ύ', 'φ', 'Φ', 'χ', 'Χ', 'ψ', 'Ψ',
                'ω', 'ώ', 'Ω', 'Ώ', ' ', "'", '"', ',', '_', '/',
                '.', '- ', '(', ')', '[', ']', '{', '}'
            );
            $english = array(
                'a', 'a', 'A', 'A', 'b', 'B', 'g', 'G', 'd', 'D', 'e', 'e',
                'E', 'E', 'z', 'Z', 'i', 'i', 'I', 'th',
                'Th', 'i', 'i', 'i', 'i', 'I',
                'I', 'k', 'K', 'l', 'L', 'm', 'M', 'n',
                'N', 'ks', 'Ks', 'o', 'o', 'O',
                'O', 'p', 'P', 'r', 'R', 's', 's', 'S',
                't', 'T', 'u', 'u', 'Y', 'Y',
                'f', 'F', 'x', 'X', 'ps', 'Ps', 'o',
                'o', 'O', 'O', '-', '', '', '', '-', '-',
                '', '', '', '', '', '', '', ''
            );
        } else {
            $greek = array(
                'α', 'ά', 'Ά', 'Α', 'β', 'Β', 'γ', 'Γ', 'δ',
                'Δ', 'ε', 'έ',
                'Ε', 'Έ', 'ζ', 'Ζ', 'η', 'ή', 'Η', 'θ', 'Θ',
                'ι', 'ί', 'ϊ', 'ΐ', 'Ι',
                'Ί', 'κ', 'Κ', 'λ', 'Λ', 'μ', 'Μ', 'ν', 'Ν',
                'ξ', 'Ξ', 'ο', 'ό', 'Ο',
                'Ό', 'π', 'Π', 'ρ', 'Ρ', 'σ', 'ς', 'Σ', 'τ',
                'Τ', 'υ', 'ύ', 'Υ', 'Ύ',
                'φ', 'Φ', 'χ', 'Χ', 'ψ', 'Ψ', 'ω', 'ώ', 'Ω', 'Ώ'
            );
        $english = array(
            'a', 'a', 'A', 'A', 'b', 'B', 'g', 'G', 'd', 'D', 'e', 'e',
            'E', 'E', 'z', 'Z', 'i', 'i', 'I', 'th', 'Th', 'i',
            'i', 'i', 'i', 'I',
            'I', 'k', 'K', 'l', 'L', 'm', 'M', 'n', 'N', 'x',
            'X', 'o', 'o', 'O',
            'O', 'p', 'P', 'r', 'R', 's', 's', 'S', 't',
            'T', 'u', 'u', 'Y', 'Y',
            'f', 'F', 'ch', 'Ch', 'ps', 'Ps', 'o', 'o', 'O', 'O'
        );
        }
        return str_replace($greek, $english, $string);
    }

    /**
     * Generate a password
     * @param integer $length
     * @return string
     */
    public static function generatePassword($length = 8)
    {
        $initialPass = md5(rand(0, time()));
        $symbols = "!@#$%^&*()_+-=[]{}|~";
        $maxlength = strlen($symbols);
        $symbol = substr($symbols, mt_rand(0, $maxlength - 1), 1);
        $injectpos = mt_rand(1, $length - 1);
        $password = substr(
                substr(
                    $initialPass, 0, $length - 1
                ),
                0, $injectpos
            )
            . $symbol
            . substr($initialPass, $injectpos);
        return $password;

    }

    /**
     * This function returns the same result with file_get_contents,
     * but it uses
     * CURL to get the content. However, if CURL is dissabled, it tries
     * file_get_contents instead.
     * @param string $url
     * @param boolean $debug
     * @param boolean $array If set to true, return an array
     * @param boolean $fakeRef Should we have a fake referrer?
     * @return string
     */
    public static function fileGetContents($url, $debug = false,
        $array = false, $fakeRef = false)
    {
        if (function_exists('curl_version')) {
            if ($fakeRef == true) {
                $optArray = array(
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_URL => $url,
                    CURLOPT_HEADER, 0,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,     // follow redirects
                    CURLOPT_MAXREDIRS      => 10,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 '
                    . '(Windows NT 6.2; WOW64; rv:17.0) '
                    . 'Gecko/20100101 Firefox/17.0',
                    CURLOPT_REFERER => 'https://www.google.com'
                );
            } else {
                $optArray = array(
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_URL => $url,
                    CURLOPT_HEADER, 0,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,     // follow redirects
                    CURLOPT_MAXREDIRS      => 10
                );
            }
            $handler = curl_init($url);
            curl_setopt_array(
                $handler,
                $optArray
            );
            $string = curl_exec($handler);
            if ($debug == true) {
                echo $url . "<br />";
                echo curl_error($handler);
                var_dump($string);
                var_dump($handler);
            }

            if ($array == true) {
                $array = array();
                $array['content'] = $string;
                $array['info'] = curl_getinfo($handler);
                curl_close($handler);
                return $array;
            } else {
                curl_close($handler);
                return $string;
            }
        } elseif (ini_get('allow_url_fopen')) {
            return file_get_contents($url);
        }
        return false;
    }

    /**
     * Get the greek name os a month
     * @param integer $month
     * @param integer $form 0: Active (Ιανουάριος), 1: Passive (Ιανουαρίου)
     * @return string
     */
    public static function greekdate($month, $form = 0)
    {
        $months = array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12);
        if ($form == 0) {
            $monthnames = array("Ιανουάριος", "Φεβρουάριος",
                "Μάρτιος", "Απρίλιος",
                "Μάης", "Ιούνης", "Ιούλης", "Αύγουστος",
                "Σεπτέμβρης", "Οκτώβρης",
                "Νοέμβρης", "Δεκέμβρης");
        } else {
            $monthnames = array("Ιανουαρίου", "Φεβρουαρίου",
                "Μαρτίου", "Απριλίου",
                "Μαΐου", "Ιουνίου", "Ιουλίου", "Αυγούστου",
                "Σεπτεμβρίου", "Οκτωβρίου",
                "Νοεμβρίου", "Δεκεμβρίου");
        }
        $monthname = str_replace($months, $monthnames, $month);
        return $monthname;
    }

    /**
     * Wraps the default var_dump function with html to be
     * shown normaly when you don't have x-code enabled
     * @param mixed $var
     */
    public static function pretty_var_dump($var)
    {
        echo '<pre>';
        echo "||----------------------------------------------------||\n";
        var_dump($var);
        echo "||----------------------------------------------------||\n";
        echo '</pre>';
    }

    /**
     * Return the contents of var_dump function
     * @param mixed $var
     * @param boolean $format Set true if yoy want to
     * add <pre></pre> to return
     * @return string
     */
    public static function varDumpToString($var, $format = false)
    {
        ob_start();
        if (is_array($var) || is_object($var)) {
            print_r($var);
        } else {
            var_dump($var);
        }
        $result = ob_get_clean();
        if ($format == true) {
            return '<pre>' . $result . '</pre>';
        }
        return $result;
    }

    /**
     * Get the closest value of an array to a trigger
     * @param integer $needle
     * @param array $heystack
     * @return integer
     */
    public static function getClosestArrayVal($needle, array $heystack)
    {
        $closest = null;
        foreach ($heystack as $item) {
            if ($closest == null
                || abs($needle - $closest) > abs($item - $needle)) {
                $closest = $item;
            }
        }
        return $closest;
    }

    /**
     * Check if a website is online or not
     * @param string $url
     * @param string $agent
     * @param boolean $debug
     * @param integer $timeout Timeout limit in seconds
     * @return array An array with two keys: online=>boolean,
     *  status=>integer (http status code)
     */
    public static function checkUrlStatus($url, $agent = NULL,
        $debug = false, $timeout = 15)
    {
        // initializes curl session
        $handler = curl_init();
        if ($agent === NULL) {
            $agent = "Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10_5_8; "
                . "pt-pt) AppleWebKit/533.20.25 (KHTML, like Gecko) "
                . "Version/5.0.4 Safari/533.20.27";
        }
        // sets the URL to fetch
        curl_setopt($handler, CURLOPT_URL, $url);
        // return the transfer as a string
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($handler, CURLOPT_USERAGENT, $agent);
        // disable output verbose information
        curl_setopt($handler, CURLOPT_VERBOSE, false);
        // max number of seconds to allow cURL function to execute
        curl_setopt($handler, CURLOPT_TIMEOUT, $timeout);
        curl_exec($handler);
        // get HTTP response code
        $httpcode = curl_getinfo($handler, CURLINFO_HTTP_CODE);
        curl_close($handler);
        if ($httpcode >= 200 && $httpcode < 300)
            return array('online' => true, 'status' => $httpcode);
        else {
            if ($debug == true) {
                var_dump($handler);
                var_dump($httpcode);
                throw new \Exception($httpcode);
            }
            return array('online' => false, 'status' => $httpcode);
        }
    }

    /**
     * Find a percentage
     * @param int $numAmount
     * @param int $numTotal
     * @return null|int
     */
    public static function percent($numAmount, $numTotal)
    {
        if ($numTotal == 0) {
            return NULL;
        }
        if ($numAmount == 0) {
            return 0;
        }
        $firstCount = $numAmount / $numTotal;
        $secondCount = $firstCount * 100;
        $count = number_format($secondCount, 0);
        return $count;
    }

    /**
     * Subtract a percentance from a number (the result + the percentance must
     * be equal to the number)
     * @param float $amount
     * @param int $percent
     * @return int
     */
    public static function subtractPercent($amount, $percent)
    {
        if ($amount == 0 || $percent == 0) {
            return $amount;
        }
        return $amount - ((($amount / (1+($percent/100)))-$amount)*-1);
    }

    /**
     * Quick sort an array of objects by any property
     * @param array $array Array of objects
     * @param string $property
     */
    public static function sortArrayoOfObjects(&$array, $property,
        $order = 'asc')
    {
        if (!is_array($array)) {
            throw new \Exception(
                'Method sortArrayOfObjects expected an array.'
            );
        }
        if (count($array) == 0) {
            return true;
        }
        $tmpar = array();
        foreach ($array as $a) {
            $tmpar[] = $a;
        }
        $array = $tmpar;
        $cur = 1;
        $stack[1]['l'] = 0;
        $stack[1]['r'] = count($array) - 1;

        do {
            $left = $stack[$cur]['l'];
            $right = $stack[$cur]['r'];
            $cur--;

            do {
                $iCount = $left;
                $jCount = $right;
                $tmp = $array[(int) ( ($left + $right) / 2 )];

                // partion the array in two parts.
                // left from $tmp are with smaller values,
                // right from $tmp are with bigger ones
                do {
                    while ($array[$iCount]->$property < $tmp->$property)
                        $iCount++;

                    while ($tmp->$property < $array[$jCount]->$property)
                        $jCount--;

                    // swap elements from the two sides
                    if ($iCount <= $jCount) {
                        $tmpVar = $array[$iCount];
                        $array[$iCount] = $array[$jCount];
                        $array[$jCount] = $tmpVar;

                        $iCount++;
                        $jCount--;
                    }
                } while ($iCount <= $jCount);

                if ($iCount < $right) {
                    $cur++;
                    $stack[$cur]['l'] = $iCount;
                    $stack[$cur]['r'] = $right;
                }
                $right = $jCount;
            } while ($left < $right);
        } while ($cur != 0);
        if ($order != 'asc') {
            $array = array_reverse($array);
        }
    }

    /**
     * Check if a string contains a serialized object
     * @param string $str
     * @return boolean
     */
    public static function checkUnserialize($str)
    {
        $data = @unserialize($str);
        if ($str === 'b:0;' || $data !== false) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if a string is a valid JSON object
     * @param string $string
     * @return boolean
     */
    public static function checkJSON($string)
    {
        return \Pramnos\General\Validator::isJson($string);
    }

    /**
     * Format a memory amount in bytes to a better unit
     * @param int $memory
     * @param int $digits Sets the number of decimal points.
     * @return boolean
     */
    public static function formatMemory($memory, $digits = 2)
    {
        if (!is_numeric($memory)) {
            return false;
        }
        $unit = ' Bytes';
        if ($memory > 1024) {
            $memory = $memory / 1024; //KB
            $unit = 'KB';
        }
        if ($memory > 1024) {
            $memory = $memory / 1024; //MB
            $unit = 'MB';
        }
        if ($memory > 1024) {
            $memory = $memory / 1024; //GB
            $unit = 'GB';
        }
        if ($memory > 1024) {
            $memory = $memory / 1024; //TB
            $unit = 'TB';
        }
        return number_format($memory, $digits) . $unit;
    }

    /**
     * Check if a number is even
     * @param int $number
     * @return boolean true for even, false for odd
     */
    public static function isEven($number)
    {
        if ($number % 2 == 0) {
            return true;
        }
        return false;
    }

    /**
     * Similar to substr, but it never splits a word.
     * @param string $text The text you want to shorten
     * @param int $length Number of characters
     * @param string $moreText Added to text to display that its shorten
     * @param string $charset
     * @return string
     */
    public static function shortenText($text, $length, $moreText = '&hellip;',
        $charset = 'utf-8')
    {
        if (!is_numeric($length)) {
            throw new \Exception('Invalid length');
        }
        $returnText = trim(strip_tags($text ?? ''));
        if (mb_strlen($returnText, $charset) > $length) {
            if (version_compare(PHP_VERSION, '7.4.0') >= 0) {
                $lastSpace = mb_strrpos(
                    mb_substr($returnText, 0, $length, $charset),
                    ' ', 0, $charset
                );
            } else {
                $lastSpace = mb_strrpos(
                    mb_substr($returnText, 0, $length, $charset),
                    ' ', $charset
                );
            }
            $returnText = mb_substr($returnText, 0, $lastSpace, $charset)
                . $moreText;
        }
        return $returnText;
    }




    /**
     * Optimize timestamps for use in sql or cache queries
     * @param integer $time unix timestamp to optimize
     * @param integer $round how many minutes to optimize to. Default is 1 min.
     * @return integer timestamp
     */
    public static function optimizeTime($time = NULL, $round = 1)
    {
        if ($time === NULL) {
            $time = time();
        }
        $optimized = floor($time / ($round * 60)) * ($round * 60);
        return (int) $optimized;
    }

    /**
     * Compare to objects
     * @param  object $firstObject
     * @param  object $secondObject
     * @return array  Returns an associative array with four keys:<br />
     *                added:       It has all properties of the second object
     *                             that aren't in the first object<br />
     *                removed:     It has all properties of the first object
     *                             that aren't in the second<br />
     *                changed:     It has all properties with different
     *                             values<br />
     *                description: Text description of the changes
     */
    public static function objectDiff($firstObject, $secondObject)
    {
        $lang = \Pramnos\Translator\Language::getInstance();
        $firstObjectVars = get_object_vars($firstObject);
        $secondObjectVars = get_object_vars($secondObject);
        $diff = array(
            'added'=>array(),
            'removed'=>array(),
            'changed'=>array(),
            'description' => ''
        );
        $comma = '';
        foreach ($firstObjectVars as $property=>$value) {
            if (isset($secondObjectVars[$property])) {
                if ($secondObjectVars[$property] != $value) {
                    $diff['changed'][$property]=array(
                        'original' => $value,
                        'new' => $secondObjectVars[$property]
                    );
                    if (!is_array($value) && !is_object($value)
                        && !is_array($secondObjectVars[$property])
                        && !is_object($secondObjectVars[$property])
                        && !is_resource($secondObjectVars[$property])
                        && !is_resource($value)) {
                        $diff['description'] .= $comma . $lang->_('Changed')
                            . ' "' . $property . '" ' . $lang->_('value from')
                            . ' "' . $value . '" ' . $lang->_('to') . ' "'
                            . $secondObjectVars[$property] . '".';
                        $comma = "\n";
                    } else {
                        $diff['description'] .= $comma . $lang->_('Changed')
                            . ' "' . $property . '"' . '.';
                        $comma = "\n";
                    }
                }
            } else {
                $diff['removed'][$property]=$value;
                if (!is_array($value) && !is_object($value)
                    && !is_resource($value)) {
                    $diff['description'] .= $comma . $lang->_('Removed property')
                        . ' "' . $property . '" ' . $lang->_('with value')
                        . ': "' . $value . '".';
                    $comma = "\n";
                } else {
                    $diff['description'] .= $comma . $lang->_('Removed property')
                        . ' "' . $property . '".';
                    $comma = "\n";
                }
            }
        }
        foreach ($secondObjectVars as $property=>$value) {
            if (!isset($firstObjectVars[$property])) {
                $diff['added'][$property]=$value;
                if (!is_array($value) && !is_object($value)
                    && !is_resource($value)) {
                    $diff['description'] .= $comma . $lang->_('Added property')
                        . ' "' . $property . '" ' . $lang->_('with value')
                        . ': "' . $value . '".';
                    $comma = "\n";
                } else {
                    $diff['description'] .= $comma . $lang->_('Added property')
                        . ' "' . $property . '".';
                    $comma = "\n";
                }
            }
        }
        return $diff;
    }


    /**
     * Format bytes to human-readable format
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted string
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }


    /**
     * Make a string uppercase and remove the greek accents
     * @param string $string utf-8 encoded input string
     * @return string Returns the uppercased string.
     */
    public static function greekStrToUpper($string)
    {
        return str_replace(
            array("Ά", "Έ", "Ή", "Ί", "Ϊ", "ΐ", "Ό", "Ύ", "Ϋ", "ΰ", "Ώ"),
            array("Α", "Ε", "Η", "Ι", "Ι", "Ι", "Ο", "Υ", "Υ", "Υ", "Ω"),
            mb_strtoupper($string, "UTF-8")
        );
    }


    /**
     * Converts a standard base64 string to a URL-safe version
     * by replacing "+" with "-" and "/" with "_" and removing padding.
     *
     * @param string $input A base64 encoded string
     * @return string URL-safe base64 string
     */
    public static function base64ToUrlSafe(string $input): string
    {
        // Replace non-URL safe characters and remove padding
        return rtrim(strtr($input, '+/', '-_'), '=');
    }

    /**
     * Converts a URL-safe base64 string back to standard format
     * by replacing "-" with "+" and "_" with "/" and adding padding.
     *
     * @param string $input URL-safe base64 string
     * @return string Standard base64 string
     */
    public static function urlSafeToBase64(string $input): string
    {
        // Calculate and add required padding
        $padding = strlen($input) % 4;
        if ($padding > 0) {
            $input .= str_repeat('=', 4 - $padding);
        }
        
        // Replace URL-safe characters with standard base64 characters
        return strtr($input, '-_', '+/');
    }

}
