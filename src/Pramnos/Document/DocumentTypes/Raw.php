<?php
namespace Pramnos\Document\DocumentTypes;
/**
 * Raw document
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Raw extends \Pramnos\Document\Document
{

    function render()
    {

        return self::_getContent();
    }

}
