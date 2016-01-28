<?php

namespace Pramnos\Translator;

use Symfony\Component\Finder\Finder;

/**
 * Finds strings for translation and imports them to the database
 * @package     PramnosFramework
 * @subpackage  Translator
 * @copyright   2005 - 2016 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class StringFinder
{

    /**
     * The active language instance
     * @var Language
     */
    protected $language;

    /**
     * An instance of Filesystem class
     * @var \Pramnos\Filesystem\Filesystem
     */
    protected $filesystem;

    /**
     * Create a new translation string finder instance
     */
    public function __construct()
    {
    }

    /**
     * Searches in a path for strings to be translated
     * Check this: https://github.com/barryvdh/laravel-translation-manager/blob/master/src/Manager.php
     * @param string $path
     * @return array An array of all the files and their contained strings
     */
    public function search($path = null)
    {
        if ($path === null && defined('APPS_PATH')) {
            $path = APPS_PATH;
        } elseif ($path === null) {
            $path = dirname(__FILE__);
        }
        $fileArray = array();
        $functions =  array(' l', '_');
        $pattern =                              // See http://regexr.com/392hu
            "(".implode('|', $functions) .")".  // Must start with one of the functions
            "\(\s*".                               // Match opening parenthese
            "[\'\"]".                           // Match " or '
            "(".                                // Start a new group to match:
                "(.)+".                         // Can contain ANYTHING
            ")".                                // Close group
            "[\'\"]".                           // Closing quote
            "\s*[\),]";                            // Close parentheses or new parameter
        // Find all PHP + Twig files in the app folder, except for storage
        $finder = new Finder();
        $finder->in($path)->exclude('storage')->name('*.php')->files();
        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            $fileArray[$file->__tostring()] = array();
           // Search the current file for the pattern
            if(preg_match_all("/$pattern/siU", $file->getContents(), $matches)) {
                // Get all matches
                foreach ($matches[2] as $key) {
                    $key = preg_replace('!\'\s+\.\s+\'!', '', $key);
                    $fileArray[$file->__tostring()][] = $key;
                }
            }
        }
        foreach ($fileArray as $key=>$innerArray) {
            $fileArray[$key] = array_values(array_unique($innerArray));
            if (count($fileArray[$key]) == 0) {
                unset($fileArray[$key]);
            }
        }

        return $fileArray;
    }
}
