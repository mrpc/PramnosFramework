<?php
namespace Pramnos\Document\DocumentTypes;

/**
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
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
