<?php

namespace Pramnos\Translator;

/**
 * Finds strings for translation and imports them to the database
 * @package     PramnosFramework
 * @subpackage  Translator
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
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
     * @param Language $language
     * @param \Pramnos\Filesystem\Filesystem $filesystem
     */
    public function __construct($language, $filesystem)
    {
        $this->language = $language;
        $this->filesystem = $filesystem;
    }

    /**
     *
     * @param string $path
     * @return array
     */
    public function search($path)
    {
        return array();
    }
}
