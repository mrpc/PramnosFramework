<?php

namespace Pramnos\Html;

use Pramnos\Framework\Base;

/**
 * Base class for all html elements
 * @package     PramnosFramework
 * @subpackage  Html
 * @copyright   2005 - 2017 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Html extends Base
{

    /**
     * The id attribute specifies a unique id for an HTML element
     * (the value must be unique within the HTML document)
     * @var string
     */
    public $id = '';
    /**
     * Specifies one or more classnames
     * for an element (refers to a class in a style sheet)
     * @var string
     */
    public $class = '';

    /**
     * The tabindex global attribute indicates if its element can be focused,
     * and if/where it participates in sequential keyboard navigation
     * (usually with the Tab key, hence the name).
     * @var int
     */
    public $tabindex;

    protected $_content;

    /**
     * Get a new Date object
     * @return Date
     */
    public function getDate()
    {
        return new Date();
    }


    /**
     * Returns the html part as string
     * @return string
     */
    public function render()
    {
        return $this->_content;
    }

    /**
     * alias to render();
     * @return string
     */
    public function __toString()
    {
        return $this->render();
    }

}