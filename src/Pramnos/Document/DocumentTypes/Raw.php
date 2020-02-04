<?php
namespace Pramnos\Document\DocumentTypes;
/**
 * Raw document
 * @package     PramnosFramework
 * @subpackage  Document
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Raw extends \Pramnos\Document\Document
{

    function render()
    {

        return self::_getContent();
    }

}
