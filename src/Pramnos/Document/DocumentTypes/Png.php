<?php
namespace Pramnos\Document\DocumentTypes;

/**
 * @package     PramnosFramework
 * @subpackage  Document
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Png extends \Pramnos\Document\Document
{

    function render()
    {
        if (!headers_sent()) {
            header('Content-Type: image/png');
        }
        return self::_getContent();
    }

}
